<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class PaymentRecoveryMetricService
{
    private const CHUNK_SIZE = 1000;

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
            ];
        }

        $data = $this->collectWindowData($platformIds, $from, $to, true);
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
        ];
    }

    private function collectWindowData(?array $platformIds, Carbon $from, Carbon $to, bool $withRelations = false): array
    {
        $unionFind = new PaymentRecoveryUnionFind();
        $failures = [];
        $successes = [];

        $failureQuery = $this->failureQuery($platformIds, $from, $to);
        $successQuery = $this->successQuery($platformIds, $from, $to);

        if ($withRelations) {
            $failureQuery->with(['platform:id,name,country,currency_code', 'client:id,name,phone_normalized', 'product:id,name']);
            $successQuery->with(['platform:id,name,country,currency_code', 'client:id,name,phone_normalized', 'product:id,name']);
        }

        $failureQuery
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$failures, $unionFind) {
                foreach ($payments as $payment) {
                    $tokens = $this->identityTokens($payment);
                    $this->registerTokens($unionFind, $tokens);

                    $failures[] = [
                        'tokens' => $tokens,
                        'created_at' => $payment->created_at,
                        'payment' => $payment,
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

        foreach ($failures as $failure) {
            $root = $unionFind->find($failure['tokens'][0]);
            $failedAt = $failure['created_at'];
            $payment = $failure['payment'];
            $recovered = $failedAt instanceof CarbonInterface
                && isset($latestSuccessByRoot[$root])
                && $latestSuccessByRoot[$root]->greaterThan($failedAt);

            $this->addAmount($failedBreakdown, $payment);

            if ($recovered) {
                $recoveredPayments++;
                $this->addAmount($recoveredBreakdown, $payment);
            } else {
                $this->addAmount($lostBreakdown, $payment);
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
                'window' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
            ],
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
            ->select(['id', 'platform_id', 'product_id', 'phone', 'client_id', 'amount', 'currency', 'status', 'transaction_reference', 'reference_number', 'created_at']);
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
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ];
    }

    private function addAmount(array &$breakdown, Payment $payment): void
    {
        $currency = strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: 'USD'));
        $breakdown[$currency] = round((float) ($breakdown[$currency] ?? 0) + (float) $payment->amount, 2);
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
