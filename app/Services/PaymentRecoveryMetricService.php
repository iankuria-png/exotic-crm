<?php

namespace App\Services;

use App\Models\BillingProviderTransaction;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class PaymentRecoveryMetricService
{
    private const CHUNK_SIZE = 1000;
    private const FAILURE_ATTEMPT_TYPES = [
        'callback_update',
        'hosted_checkout_init',
        'provider_status_check',
        'reconciliation_check',
        'retry_stk',
        'sandbox_reconcile',
        'stk_initiate',
    ];

    public function __construct(
        private readonly PaymentFailureReasonClassifier $failureReasonClassifier
    ) {
    }

    public function compute(?array $platformIds, Carbon $from, Carbon $to): array
    {
        if (is_array($platformIds) && empty($platformIds)) {
            return $this->emptyResult($from, $to);
        }

        $data = $this->collectWindowData($platformIds, $from, $to);
        $result = $this->summarize($data['union_find'], $data['failures'], $data['successes'], $from, $to);

        return $result['metrics'];
    }

    public function report(?array $platformIds, Carbon $from, Carbon $to, int $limit = 100): array
    {
        if (is_array($platformIds) && empty($platformIds)) {
            return [
                'metrics' => $this->emptyResult($from, $to),
                'recovered_pairs' => [],
                'failure_reasons' => $this->emptyFailureReasons(),
                'friction_breakdowns' => $this->emptyFrictionBreakdowns(),
            ];
        }

        $data = $this->collectWindowData($platformIds, $from, $to, true, true);
        $result = $this->summarize($data['union_find'], $data['failures'], $data['successes'], $from, $to);
        $successesByRoot = [];

        foreach ($data['successes'] as $success) {
            $eventAt = $success['event_at'];
            if (!$eventAt instanceof CarbonInterface) {
                continue;
            }

            $root = $data['union_find']->find($success['tokens'][0]);
            $successesByRoot[$root][] = $success;
        }

        foreach ($successesByRoot as $root => $successes) {
            usort($successes, fn (array $left, array $right) => $left['event_at']->getTimestamp() <=> $right['event_at']->getTimestamp());
            $successesByRoot[$root] = $successes;
        }

        $pairs = [];
        usort($data['failures'], fn (array $left, array $right) => $right['created_at']->getTimestamp() <=> $left['created_at']->getTimestamp());

        foreach ($data['failures'] as $failure) {
            if (count($pairs) >= $limit) {
                break;
            }

            $failedAt = $failure['created_at'];
            if (!$failedAt instanceof CarbonInterface) {
                continue;
            }

            $root = $data['union_find']->find($failure['tokens'][0]);
            foreach ($successesByRoot[$root] ?? [] as $success) {
                if (!$success['event_at']->greaterThan($failedAt)) {
                    continue;
                }

                $pairs[] = [
                    'identity_key' => $root,
                    'recovery_minutes' => max(0, $failedAt->diffInMinutes($success['event_at'])),
                    'failed_payment' => $this->serializePayment($failure['payment']),
                    'recovered_payment' => $this->serializePayment($success['payment']),
                ];
                break;
            }
        }

        return [
            'metrics' => $result['metrics'],
            'recovered_pairs' => $pairs,
            'failure_reasons' => $this->summarizeFailureReasons(
                $data['union_find'],
                $data['failures'],
                $result['latest_success_by_root']
            ),
            'friction_breakdowns' => $this->summarizeFrictionBreakdowns(
                $data['union_find'],
                $data['failures'],
                $result['latest_success_by_root']
            ),
        ];
    }

    private function collectWindowData(
        ?array $platformIds,
        Carbon $from,
        Carbon $to,
        bool $withRelations = false,
        bool $withFailureReasons = false
    ): array
    {
        $unionFind = new PaymentRecoveryUnionFind();
        $failures = [];
        $successes = [];

        $failureQuery = $this->failureQuery($platformIds, $from, $to);
        $successQuery = $this->successQuery($platformIds, $from, $to);

        if ($withRelations) {
            $failureQuery->with([
                'platform:id,name,country,currency_code',
                'client:id,name,phone_normalized',
                'product:id,name,display_name,tier',
                'deal:id,product_id',
                'deal.product:id,name,display_name,tier',
            ]);
            $successQuery->with([
                'platform:id,name,country,currency_code',
                'client:id,name,phone_normalized',
                'product:id,name,display_name,tier',
            ]);
        }

        $failureQuery
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$failures, $unionFind, $withFailureReasons) {
                $failureSignals = $withFailureReasons
                    ? $this->failureSignalsForPayments($payments->pluck('id')->map(fn ($id) => (int) $id)->all())
                    : [];

                foreach ($payments as $payment) {
                    $tokens = $this->identityTokens($payment);
                    $this->registerTokens($unionFind, $tokens);
                    $signals = $failureSignals[(int) $payment->id] ?? [];
                    $signals = $this->appendPaymentFailureSignals($signals, $payment);
                    $classification = $withFailureReasons
                        ? $this->failureReasonClassifier->classify($signals)
                        : null;

                    $failures[] = [
                        'tokens' => $tokens,
                        'created_at' => $payment->created_at,
                        'payment' => $payment,
                        'failure_reason' => $classification,
                    ];
                }
            });

        $successQuery
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$successes, $unionFind) {
                foreach ($payments as $payment) {
                    $tokens = $this->identityTokens($payment);
                    $this->registerTokens($unionFind, $tokens);

                    $successes[] = [
                        'tokens' => $tokens,
                        'event_at' => $payment->completed_at ?: $payment->created_at,
                        'payment' => $payment,
                    ];
                }
            });

        return [
            'union_find' => $unionFind,
            'failures' => $failures,
            'successes' => $successes,
        ];
    }

    private function summarize(PaymentRecoveryUnionFind $unionFind, array $failures, array $successes, Carbon $from, Carbon $to): array
    {
        $latestSuccessByRoot = [];
        foreach ($successes as $success) {
            $root = $unionFind->find($success['tokens'][0]);
            $eventAt = $success['event_at'];

            if (!$eventAt instanceof CarbonInterface) {
                continue;
            }

            if (!isset($latestSuccessByRoot[$root]) || $eventAt->greaterThan($latestSuccessByRoot[$root])) {
                $latestSuccessByRoot[$root] = $eventAt;
            }
        }

        $failedPayments = count($failures);
        $recoveredPayments = 0;
        $customers = [];
        $failedBreakdown = [];
        $recoveredBreakdown = [];
        $lostBreakdown = [];
        $failedRows = [];
        $recoveredRows = [];
        $lostRows = [];

        foreach ($failures as $failure) {
            $root = $unionFind->find($failure['tokens'][0]);
            $failedAt = $failure['created_at'];
            $payment = $failure['payment'];
            $recovered = $failedAt instanceof CarbonInterface
                && isset($latestSuccessByRoot[$root])
                && $latestSuccessByRoot[$root]->greaterThan($failedAt);

            $this->addAmount($failedBreakdown, $payment);
            $this->addAmountRow($failedRows, $payment, $failedAt);

            if ($recovered) {
                $recoveredPayments++;
                $this->addAmount($recoveredBreakdown, $payment);
                $this->addAmountRow($recoveredRows, $payment, $failedAt);
            } else {
                $this->addAmount($lostBreakdown, $payment);
                $this->addAmountRow($lostRows, $payment, $failedAt);
            }

            $customers[$root] = ($customers[$root] ?? false) || $recovered;
        }

        $failedCustomers = count($customers);
        $recoveredCustomers = count(array_filter($customers));
        $lostPayments = max(0, $failedPayments - $recoveredPayments);

        return [
            'metrics' => [
                'failed_payments' => $failedPayments,
                'recovered_payments' => $recoveredPayments,
                'lost_payments' => $lostPayments,
                'payment_recovery_rate' => $this->rate($recoveredPayments, $failedPayments),
                'failed_customers' => $failedCustomers,
                'recovered_customers' => $recoveredCustomers,
                'lost_customers' => max(0, $failedCustomers - $recoveredCustomers),
                'customer_recovery_rate' => $this->rate($recoveredCustomers, $failedCustomers),
                'failed_amount_breakdown' => $failedBreakdown,
                'recovered_amount_breakdown' => $recoveredBreakdown,
                'lost_amount_breakdown' => $lostBreakdown,
                'failed_amount_rows' => $failedRows,
                'recovered_amount_rows' => $recoveredRows,
                'lost_amount_rows' => $lostRows,
                'window' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
            ],
            'latest_success_by_root' => $latestSuccessByRoot,
        ];
    }

    private function failureQuery(?array $platformIds, Carbon $from, Carbon $to): Builder
    {
        return Payment::query()
            ->businessVisible()
            ->excludingWalletTopups()
            ->where('status', 'failed')
            ->whereBetween('created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->select([
                'id',
                'platform_id',
                'product_id',
                'deal_id',
                'phone',
                'client_id',
                'amount',
                'currency',
                'status',
                'failure_reason',
                'raw_payload',
                'payment_data',
                'transaction_reference',
                'reference_number',
                'created_at',
            ]);
    }

    private function successQuery(?array $platformIds, Carbon $from, Carbon $to): Builder
    {
        return Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->where(function (Builder $query) use ($from, $to) {
                $query->whereBetween('completed_at', [$from, $to])
                    ->orWhere(function (Builder $fallback) use ($from, $to) {
                        $fallback->whereNull('completed_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->select(['id', 'platform_id', 'product_id', 'phone', 'client_id', 'amount', 'currency', 'status', 'transaction_reference', 'reference_number', 'completed_at', 'created_at']);
    }

    private function identityTokens(Payment $payment): array
    {
        $tokens = [];
        $phone = $this->normalizePhone($payment->phone);

        if ($phone !== null) {
            $tokens[] = 'phone:' . $phone;
        }

        if ($payment->client_id) {
            $tokens[] = 'client:' . (int) $payment->client_id;
        }

        if (empty($tokens)) {
            $tokens[] = 'payment:' . (int) $payment->id;
        }

        return $tokens;
    }

    private function registerTokens(PaymentRecoveryUnionFind $unionFind, array $tokens): void
    {
        foreach ($tokens as $token) {
            $unionFind->makeSet($token);
        }

        $first = $tokens[0];
        foreach (array_slice($tokens, 1) as $token) {
            $unionFind->union($first, $token);
        }
    }

    private function normalizePhone(?string $phone): ?string
    {
        $value = preg_replace('/\D/', '', (string) $phone);

        if (!is_string($value)) {
            return null;
        }

        $value = ltrim($value, '0');

        return $value !== '' ? $value : null;
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

    private function emptyResult(Carbon $from, Carbon $to): array
    {
        return [
            'failed_payments' => 0,
            'recovered_payments' => 0,
            'lost_payments' => 0,
            'payment_recovery_rate' => 0.0,
            'failed_customers' => 0,
            'recovered_customers' => 0,
            'lost_customers' => 0,
            'customer_recovery_rate' => 0.0,
            'failed_amount_breakdown' => [],
            'recovered_amount_breakdown' => [],
            'lost_amount_breakdown' => [],
            'failed_amount_rows' => [],
            'recovered_amount_rows' => [],
            'lost_amount_rows' => [],
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ];
    }

    private function failureSignalsForPayments(array $paymentIds): array
    {
        if (empty($paymentIds)) {
            return [];
        }

        $signals = [];
        $attempts = PaymentAttempt::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'failed')
            ->whereIn('attempt_type', self::FAILURE_ATTEMPT_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'payment_id', 'error_code', 'error_message', 'response_meta']);

        foreach ($attempts as $attempt) {
            $paymentId = (int) $attempt->payment_id;
            $signals[$paymentId]['codes'][] = $attempt->error_code;
            $signals[$paymentId]['messages'][] = $attempt->error_message;

            foreach ([
                'provider_failure_code',
                'failure_code',
                'failureReason.failureCode',
                'provider_payload.failureReason.failureCode',
            ] as $path) {
                $signals[$paymentId]['codes'][] = data_get($attempt->response_meta, $path);
            }

            foreach ([
                'provider_failure_reason',
                'provider_message',
                'failure_reason',
                'failure_message',
                'failureReason.failureMessage',
                'provider_payload.failureReason.failureMessage',
            ] as $path) {
                $signals[$paymentId]['messages'][] = data_get($attempt->response_meta, $path);
            }
        }

        $providerTransactions = BillingProviderTransaction::query()
            ->whereIn('payment_id', $paymentIds)
            ->where(function (Builder $query) {
                $query->whereNotNull('provider_failure_code')
                    ->orWhereNotNull('provider_failure_message');
            })
            ->orderByRaw('COALESCE(last_status_at, created_at) DESC')
            ->orderByDesc('id')
            ->get(['id', 'payment_id', 'provider_failure_code', 'provider_failure_message']);

        foreach ($providerTransactions as $transaction) {
            $paymentId = (int) $transaction->payment_id;
            $signals[$paymentId]['codes'][] = $transaction->provider_failure_code;
            $signals[$paymentId]['messages'][] = $transaction->provider_failure_message;
        }

        return $signals;
    }

    private function summarizeFailureReasons(
        PaymentRecoveryUnionFind $unionFind,
        array $failures,
        array $latestSuccessByRoot
    ): array {
        $items = [];

        foreach ($failures as $failure) {
            $reason = is_array($failure['failure_reason'] ?? null)
                ? $failure['failure_reason']
                : [
                    'code' => 'reason_unavailable',
                    'label' => 'Reason unavailable',
                    'classified' => false,
                    'recorded' => false,
                ];
            $code = (string) $reason['code'];
            $failedAt = $failure['created_at'];
            $root = $unionFind->find($failure['tokens'][0]);
            $recovered = $failedAt instanceof CarbonInterface
                && isset($latestSuccessByRoot[$root])
                && $latestSuccessByRoot[$root]->greaterThan($failedAt);

            if (!isset($items[$code])) {
                $items[$code] = [
                    'code' => $code,
                    'label' => (string) $reason['label'],
                    'failed_count' => 0,
                    'recovered_count' => 0,
                    'unresolved_count' => 0,
                    'failed_amount_breakdown' => [],
                    'failed_amount_rows' => [],
                ];
            }

            $items[$code]['failed_count']++;
            $items[$code][$recovered ? 'recovered_count' : 'unresolved_count']++;
            $this->addAmount($items[$code]['failed_amount_breakdown'], $failure['payment']);
            $this->addAmountRow($items[$code]['failed_amount_rows'], $failure['payment'], $failedAt);
        }

        $total = count($failures);
        $classified = 0;
        $recorded = 0;

        foreach ($failures as $failure) {
            $classified += (bool) data_get($failure, 'failure_reason.classified', false) ? 1 : 0;
            $recorded += (bool) data_get($failure, 'failure_reason.recorded', false) ? 1 : 0;
        }

        $items = array_values(array_map(function (array $item) use ($total) {
            $item['percentage'] = $this->rate($item['failed_count'], $total);
            $item['recovery_rate'] = $this->rate($item['recovered_count'], $item['failed_count']);

            return $item;
        }, $items));

        usort($items, function (array $left, array $right) {
            $countComparison = $right['failed_count'] <=> $left['failed_count'];

            return $countComparison !== 0
                ? $countComparison
                : strcmp($left['label'], $right['label']);
        });

        return [
            'total' => $total,
            'classified' => $classified,
            'unclassified' => max(0, $total - $classified),
            'recorded' => $recorded,
            'reason_unavailable' => max(0, $total - $recorded),
            'coverage_pct' => $this->rate($classified, $total),
            'recorded_pct' => $this->rate($recorded, $total),
            'items' => $items,
        ];
    }

    private function emptyFailureReasons(): array
    {
        return [
            'total' => 0,
            'classified' => 0,
            'unclassified' => 0,
            'recorded' => 0,
            'reason_unavailable' => 0,
            'coverage_pct' => 0.0,
            'recorded_pct' => 0.0,
            'items' => [],
        ];
    }

    private function appendPaymentFailureSignals(array $signals, Payment $payment): array
    {
        $signals['messages'][] = $payment->failure_reason;

        foreach ([
            'failure_data.code',
            'failure_data.error_code',
            'failureReason.failureCode',
            'provider_failure.code',
        ] as $path) {
            $signals['codes'][] = data_get($payment->raw_payload, $path);
        }

        foreach ([
            'failure_data.message',
            'failure_data.reason',
            'failureReason.failureMessage',
            'provider_failure.message',
        ] as $path) {
            $signals['messages'][] = data_get($payment->raw_payload, $path);
        }

        foreach ([
            'failure_code',
            'failure.error_code',
            'failureReason.failureCode',
        ] as $path) {
            $signals['codes'][] = data_get($payment->payment_data, $path);
        }

        foreach ([
            'failure_reason',
            'failure.message',
            'failureReason.failureMessage',
        ] as $path) {
            $signals['messages'][] = data_get($payment->payment_data, $path);
        }

        return $signals;
    }

    private function summarizeFrictionBreakdowns(
        PaymentRecoveryUnionFind $unionFind,
        array $failures,
        array $latestSuccessByRoot
    ): array {
        $markets = [];
        $packages = [];

        foreach ($failures as $failure) {
            $payment = $failure['payment'];
            $failedAt = $failure['created_at'];
            $root = $unionFind->find($failure['tokens'][0]);
            $recovered = $failedAt instanceof CarbonInterface
                && isset($latestSuccessByRoot[$root])
                && $latestSuccessByRoot[$root]->greaterThan($failedAt);
            $platform = $payment->relationLoaded('platform') ? $payment->platform : null;
            $product = $payment->relationLoaded('product') ? $payment->product : null;

            if (!$product && $payment->relationLoaded('deal') && $payment->deal?->relationLoaded('product')) {
                $product = $payment->deal->product;
            }

            $marketKey = $platform ? 'market:' . (int) $platform->id : 'market:unattributed';
            $packageKey = $product ? 'package:' . (int) $product->id : 'package:unattributed';

            $this->addFrictionItem($markets, $marketKey, [
                'platform_id' => $platform ? (int) $platform->id : null,
                'label' => $platform?->name ?: 'Unattributed market',
                'country' => $platform?->country,
                'attributed' => (bool) $platform,
            ], $payment, $failedAt, $recovered);

            $this->addFrictionItem($packages, $packageKey, [
                'product_id' => $product ? (int) $product->id : null,
                'label' => $product?->display_name ?: ($product?->name ?: 'Unattributed package'),
                'tier' => $product?->tier,
                'attributed' => (bool) $product,
            ], $payment, $failedAt, $recovered);
        }

        return [
            'markets' => $this->finalizeFrictionDimension($markets, count($failures)),
            'packages' => $this->finalizeFrictionDimension($packages, count($failures)),
        ];
    }

    private function addFrictionItem(
        array &$items,
        string $key,
        array $identity,
        Payment $payment,
        mixed $failedAt,
        bool $recovered
    ): void {
        if (!isset($items[$key])) {
            $items[$key] = [
                ...$identity,
                'failed_count' => 0,
                'recovered_count' => 0,
                'unresolved_count' => 0,
                'failed_amount_breakdown' => [],
                'failed_amount_rows' => [],
            ];
        }

        $items[$key]['failed_count']++;
        $items[$key][$recovered ? 'recovered_count' : 'unresolved_count']++;
        $this->addAmount($items[$key]['failed_amount_breakdown'], $payment);
        $this->addAmountRow($items[$key]['failed_amount_rows'], $payment, $failedAt);
    }

    private function finalizeFrictionDimension(array $items, int $total): array
    {
        $attributed = array_sum(array_map(
            static fn (array $item) => ($item['attributed'] ?? false) ? (int) $item['failed_count'] : 0,
            $items
        ));
        $items = array_values(array_map(function (array $item) use ($total) {
            $item['percentage'] = $this->rate((int) $item['failed_count'], $total);
            $item['recovery_rate'] = $this->rate((int) $item['recovered_count'], (int) $item['failed_count']);
            unset($item['attributed']);

            return $item;
        }, $items));

        usort($items, function (array $left, array $right) {
            $countComparison = $right['failed_count'] <=> $left['failed_count'];

            return $countComparison !== 0
                ? $countComparison
                : strcmp((string) $left['label'], (string) $right['label']);
        });

        return [
            'total' => $total,
            'attributed' => $attributed,
            'unattributed' => max(0, $total - $attributed),
            'coverage_pct' => $this->rate($attributed, $total),
            'items' => $items,
        ];
    }

    private function emptyFrictionBreakdowns(): array
    {
        $empty = [
            'total' => 0,
            'attributed' => 0,
            'unattributed' => 0,
            'coverage_pct' => 0.0,
            'items' => [],
        ];

        return [
            'markets' => $empty,
            'packages' => $empty,
        ];
    }

    private function addAmount(array &$breakdown, Payment $payment): void
    {
        $currency = strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: 'USD'));
        $breakdown[$currency] = round((float) ($breakdown[$currency] ?? 0) + (float) $payment->amount, 2);
    }

    private function addAmountRow(array &$rows, Payment $payment, mixed $eventAt): void
    {
        $platform = $payment->relationLoaded('platform') ? $payment->platform : null;

        $rows[] = [
            'currency' => strtoupper((string) ($payment->currency ?: $platform?->currency_code ?: 'USD')),
            'amount' => round((float) $payment->amount, 2),
            'event_date' => $eventAt instanceof CarbonInterface
                ? $eventAt->toDateString()
                : ($payment->created_at?->toDateString() ?: now()->toDateString()),
            'platform_id' => $payment->platform_id ? (int) $payment->platform_id : null,
            'platform_country' => $platform?->country,
            'platform_name' => $platform?->name,
        ];
    }

    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'currency' => strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: 'USD')),
            'phone' => $payment->phone,
            'client_id' => $payment->client_id ? (int) $payment->client_id : null,
            'client' => $payment->client ? [
                'id' => (int) $payment->client->id,
                'name' => $payment->client->name,
                'phone_normalized' => $payment->client->phone_normalized,
            ] : null,
            'platform' => $payment->platform ? [
                'id' => (int) $payment->platform->id,
                'name' => $payment->platform->name,
                'country' => $payment->platform->country,
                'currency_code' => $payment->platform->currency_code,
            ] : null,
            'product' => $payment->product ? [
                'id' => (int) $payment->product->id,
                'name' => $payment->product->name,
            ] : null,
            'transaction_reference' => $payment->transaction_reference,
            'reference_number' => $payment->reference_number,
            'created_at' => $payment->created_at?->toDateTimeString(),
            'completed_at' => $payment->completed_at?->toDateTimeString(),
        ];
    }
}

class PaymentRecoveryUnionFind
{
    private array $parents = [];
    private array $ranks = [];

    public function makeSet(string $token): void
    {
        if (isset($this->parents[$token])) {
            return;
        }

        $this->parents[$token] = $token;
        $this->ranks[$token] = 0;
    }

    public function find(string $token): string
    {
        $this->makeSet($token);

        if ($this->parents[$token] !== $token) {
            $this->parents[$token] = $this->find($this->parents[$token]);
        }

        return $this->parents[$token];
    }

    public function union(string $left, string $right): void
    {
        $leftRoot = $this->find($left);
        $rightRoot = $this->find($right);

        if ($leftRoot === $rightRoot) {
            return;
        }

        if ($this->ranks[$leftRoot] < $this->ranks[$rightRoot]) {
            $this->parents[$leftRoot] = $rightRoot;
            return;
        }

        if ($this->ranks[$leftRoot] > $this->ranks[$rightRoot]) {
            $this->parents[$rightRoot] = $leftRoot;
            return;
        }

        $this->parents[$rightRoot] = $leftRoot;
        $this->ranks[$leftRoot]++;
    }
}
