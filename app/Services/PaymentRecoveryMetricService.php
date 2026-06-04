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

        $unionFind = new PaymentRecoveryUnionFind();
        $failures = [];
        $successes = [];

        $this->failureQuery($platformIds, $from, $to)
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$failures, $unionFind) {
                foreach ($payments as $payment) {
                    $tokens = $this->identityTokens($payment);
                    $this->registerTokens($unionFind, $tokens);

                    $failures[] = [
                        'tokens' => $tokens,
                        'created_at' => $payment->created_at,
                    ];
                }
            });

        $this->successQuery($platformIds, $from, $to)
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$successes, $unionFind) {
                foreach ($payments as $payment) {
                    $tokens = $this->identityTokens($payment);
                    $this->registerTokens($unionFind, $tokens);

                    $successes[] = [
                        'tokens' => $tokens,
                        'event_at' => $payment->completed_at ?: $payment->created_at,
                    ];
                }
            });

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

        foreach ($failures as $failure) {
            $root = $unionFind->find($failure['tokens'][0]);
            $failedAt = $failure['created_at'];
            $recovered = $failedAt instanceof CarbonInterface
                && isset($latestSuccessByRoot[$root])
                && $latestSuccessByRoot[$root]->greaterThan($failedAt);

            if ($recovered) {
                $recoveredPayments++;
            }

            $customers[$root] = ($customers[$root] ?? false) || $recovered;
        }

        $failedCustomers = count($customers);
        $recoveredCustomers = count(array_filter($customers));

        return [
            'failed_payments' => $failedPayments,
            'recovered_payments' => $recoveredPayments,
            'payment_recovery_rate' => $this->rate($recoveredPayments, $failedPayments),
            'failed_customers' => $failedCustomers,
            'recovered_customers' => $recoveredCustomers,
            'customer_recovery_rate' => $this->rate($recoveredCustomers, $failedCustomers),
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
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
            ->select(['id', 'phone', 'client_id', 'created_at']);
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
            ->select(['id', 'phone', 'client_id', 'completed_at', 'created_at']);
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
            'payment_recovery_rate' => 0.0,
            'failed_customers' => 0,
            'recovered_customers' => 0,
            'customer_recovery_rate' => 0.0,
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
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
