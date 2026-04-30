<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientWalletBalance;
use App\Models\Payment;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

class WalletService
{
    public function __construct(
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function summary(Client $client, int $limit = 10): array
    {
        $freshClient = $client->fresh(['platform']) ?? $client->loadMissing('platform');
        $transactions = $this->recentTransactions($freshClient, $limit);
        $lastTopup = $this->lastTopup($freshClient);
        $currencyCode = $this->resolvePrimaryWalletCurrency($freshClient);
        $balances = $this->summarizeBalances($freshClient);
        $primaryBalance = collect($balances)->firstWhere('currency', $currencyCode)['balance'] ?? '0.00';

        return [
            'balance' => $primaryBalance,
            'currency' => $currencyCode,
            'balances' => $balances,
            'last_topup' => $lastTopup ? $this->serializeTransaction($lastTopup) : null,
            'transactions' => $transactions->map(fn (WalletTransaction $transaction) => $this->serializeTransaction($transaction))->values()->all(),
            'refreshed_at' => now()->toIso8601String(),
            'wallet_last_synced_at' => optional($freshClient->wallet_last_synced_at)->toIso8601String(),
        ];
    }

    public function recentTransactions(Client $client, int $limit = 10): Collection
    {
        $limit = max(1, min(50, $limit));

        return $client->walletTransactions()
            ->with(['payment', 'deal'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function lastTopup(Client $client): ?WalletTransaction
    {
        return $client->walletTransactions()
            ->where('type', 'credit')
            ->where(function ($query) {
                $query->where('reference_type', 'wallet_topup')
                    ->orWhereHas('payment', function ($paymentQuery) {
                        $paymentQuery->where('purpose', 'wallet_topup');
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * Read the balance row for a client/currency under a transaction lock.
     * If concurrent requests race to create the first row, the unique
     * (client_id, currency) constraint is relied on and duplicate-key errors
     * are retried against a subsequent lockForUpdate() reload.
     */
    public function balanceFor(Client $client, string $currency): float
    {
        $currencyCode = $this->normalizeCurrency($currency);

        return DB::transaction(function () use ($client, $currencyCode) {
            $lockedClient = Client::query()
                ->with('platform')
                ->lockForUpdate()
                ->findOrFail((int) $client->id);

            $balanceRow = $this->lockBalanceRow($lockedClient, $currencyCode);

            return round((float) $balanceRow->balance, 2);
        }, 3);
    }

    public function credit(Client $client, string|float|int $currency, float|array $amount = 0, array $options = []): array
    {
        [$currencyCode, $normalizedAmount, $normalizedOptions] = $this->normalizeMutationArguments($client, $currency, $amount, $options);

        return $this->mutateBalance($client, 'credit', $currencyCode, $normalizedAmount, $normalizedOptions);
    }

    public function debit(Client $client, string|float|int $currency, float|array $amount = 0, array $options = []): array
    {
        [$currencyCode, $normalizedAmount, $normalizedOptions] = $this->normalizeMutationArguments($client, $currency, $amount, $options);

        return $this->mutateBalance($client, 'debit', $currencyCode, $normalizedAmount, $normalizedOptions);
    }

    public function serializeTransaction(WalletTransaction $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'type' => $transaction->type,
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => $transaction->currency_code,
            'balance_after' => number_format((float) $transaction->balance_after, 2, '.', ''),
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id ? (int) $transaction->reference_id : null,
            'payment_id' => $transaction->payment_id ? (int) $transaction->payment_id : null,
            'deal_id' => $transaction->deal_id ? (int) $transaction->deal_id : null,
            'created_at' => optional($transaction->created_at)->toIso8601String(),
            'metadata' => is_array($transaction->metadata) ? $transaction->metadata : null,
        ];
    }

    private function mutateBalance(Client $client, string $type, string $currency, float $amount, array $options = []): array
    {
        $normalizedAmount = round($amount, 2);
        $currencyCode = $this->normalizeCurrency($currency);
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Unsupported wallet transaction type.');
        }

        if ($normalizedAmount <= 0) {
            throw new InvalidArgumentException('Wallet amount must be greater than zero.');
        }

        return DB::transaction(function () use ($client, $type, $currencyCode, $normalizedAmount, $options) {
            $idempotencyKey = isset($options['idempotency_key']) ? trim((string) $options['idempotency_key']) : '';
            if ($idempotencyKey !== '') {
                $existing = WalletTransaction::query()
                    ->where('client_id', (int) $client->id)
                    ->where('currency_code', $currencyCode)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    DB::afterCommit(function () use ($client) {
                        app(WalletSyncService::class)->syncClientBalanceById((int) $client->id);
                    });

                    return [
                        'client' => $client->fresh(['platform']),
                        'transaction' => $existing->fresh(),
                        'replayed' => true,
                    ];
                }
            }

            $lockedClient = Client::query()
                ->with('platform')
                ->lockForUpdate()
                ->findOrFail((int) $client->id);

            $balanceRow = $this->lockBalanceRow($lockedClient, $currencyCode);
            $currentBalance = round((float) $balanceRow->balance, 2);
            if ($type === 'debit' && $currentBalance < $normalizedAmount) {
                $deficit = $normalizedAmount - $currentBalance;

                throw new RuntimeException(sprintf(
                    'Top up your %s wallet (need %s %s more).',
                    $currencyCode,
                    $currencyCode,
                    number_format($deficit, 2, '.', '')
                ));
            }

            $nextBalance = $type === 'debit'
                ? $currentBalance - $normalizedAmount
                : $currentBalance + $normalizedAmount;

            $balanceRow->forceFill([
                'balance' => number_format($nextBalance, 2, '.', ''),
                'last_synced_at' => $lockedClient->wallet_last_synced_at,
            ])->save();

            $this->syncPrimaryMirror($lockedClient);

            $payment = $options['payment'] ?? null;
            if ($payment !== null && !$payment instanceof Payment) {
                throw new InvalidArgumentException('Wallet mutation payment must be a Payment model.');
            }

            $transaction = WalletTransaction::query()->create([
                'client_id' => (int) $lockedClient->id,
                'platform_id' => (int) $lockedClient->platform_id,
                'type' => $type,
                'currency_code' => $currencyCode,
                'amount' => number_format($normalizedAmount, 2, '.', ''),
                'balance_after' => number_format($nextBalance, 2, '.', ''),
                'reference_type' => $options['reference_type'] ?? null,
                'reference_id' => isset($options['reference_id']) ? (int) $options['reference_id'] : null,
                'payment_id' => $payment?->id ?? (isset($options['payment_id']) ? (int) $options['payment_id'] : null),
                'deal_id' => isset($options['deal_id']) ? (int) $options['deal_id'] : null,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'description' => (string) ($options['description'] ?? ucfirst($type) . ' wallet transaction'),
                'performed_by' => isset($options['performed_by']) ? (int) $options['performed_by'] : null,
                'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : null,
            ]);

            if ($payment) {
                $payment->forceFill([
                    'wallet_transaction_id' => (int) $transaction->id,
                ])->save();
            }

            DB::afterCommit(function () use ($lockedClient) {
                app(WalletSyncService::class)->syncClientBalanceById((int) $lockedClient->id);
            });

            return [
                'client' => $lockedClient->fresh(['platform']),
                'transaction' => $transaction->fresh(),
                'replayed' => false,
            ];
        }, 3);
    }

    private function summarizeBalances(Client $client): array
    {
        $primaryCurrency = $this->resolvePrimaryWalletCurrency($client);
        $effectiveCurrencies = $client->platform?->effectiveCurrencies() ?? [$primaryCurrency];
        $existing = $client->walletBalances()
            ->orderBy('currency')
            ->get(['currency', 'balance'])
            ->mapWithKeys(fn (ClientWalletBalance $balance) => [
                strtoupper((string) $balance->currency) => number_format((float) $balance->balance, 2, '.', ''),
            ])
            ->all();

        if (!array_key_exists($primaryCurrency, $existing)) {
            $legacyCurrency = strtoupper(trim((string) ($client->wallet_currency ?? '')));
            if ($legacyCurrency === '' || $legacyCurrency === $primaryCurrency) {
                $legacyBalance = round((float) ($client->wallet_balance ?? 0), 2);
                if ($legacyBalance !== 0.0) {
                    $existing[$primaryCurrency] = number_format($legacyBalance, 2, '.', '');
                }
            }
        }

        $orderedCurrencies = array_values(array_unique(array_filter(array_merge(
            [$primaryCurrency],
            $effectiveCurrencies
        ))));

        return collect($orderedCurrencies)
            ->map(fn (string $currency) => [
                'currency' => $currency,
                'balance' => $existing[$currency] ?? '0.00',
            ])
            ->values()
            ->all();
    }

    private function lockBalanceRow(Client $client, string $currency): ClientWalletBalance
    {
        $currencyCode = $this->normalizeCurrency($currency);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                ClientWalletBalance::query()->firstOrCreate(
                    [
                        'client_id' => (int) $client->id,
                        'currency' => $currencyCode,
                    ],
                    [
                        'balance' => $this->legacyPrimaryBalanceSeed($client, $currencyCode),
                    ]
                );

                return ClientWalletBalance::query()
                    ->where('client_id', (int) $client->id)
                    ->where('currency', $currencyCode)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (QueryException $exception) {
                if (!$this->isDuplicateKeyException($exception) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to lock wallet balance row.');
    }

    private function syncPrimaryMirror(Client $client): void
    {
        $primaryCurrency = $this->resolvePrimaryWalletCurrency($client);
        $primaryRow = ClientWalletBalance::query()
            ->where('client_id', (int) $client->id)
            ->where('currency', $primaryCurrency)
            ->first();

        $client->forceFill([
            'wallet_balance' => number_format((float) ($primaryRow?->balance ?? 0), 2, '.', ''),
            'wallet_currency' => $primaryCurrency,
        ])->save();
    }

    private function resolvePrimaryWalletCurrency(Client $client): string
    {
        $walletConfigCurrency = $this->walletSettingsService->runtimeWalletCurrencyCode($client->platform, '');
        if ($walletConfigCurrency !== '') {
            return strtoupper($walletConfigCurrency);
        }

        $clientCurrency = strtoupper(trim((string) ($client->wallet_currency ?? '')));
        if ($clientCurrency !== '') {
            return $clientCurrency;
        }

        $platformCurrency = strtoupper(trim((string) ($client->platform?->currency_code ?? '')));
        if ($platformCurrency !== '') {
            return $platformCurrency;
        }

        return 'KES';
    }

    private function legacyPrimaryBalanceSeed(Client $client, string $currency): string
    {
        $primaryCurrency = $this->resolvePrimaryWalletCurrency($client);
        $legacyCurrency = strtoupper(trim((string) ($client->wallet_currency ?? '')));

        if ($currency !== $primaryCurrency) {
            return '0.00';
        }

        if ($legacyCurrency !== '' && $legacyCurrency !== $primaryCurrency) {
            return '0.00';
        }

        return number_format((float) ($client->wallet_balance ?? 0), 2, '.', '');
    }

    private function normalizeCurrency(string $currency): string
    {
        $normalized = strtoupper(trim($currency));
        if ($normalized === '' || strlen($normalized) !== 3) {
            throw new InvalidArgumentException('Wallet currency must be a 3-letter code.');
        }

        return $normalized;
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (string) ($errorInfo[1] ?? '');

        return $sqlState === '23000' || $sqlState === '23505' || $driverCode === '19';
    }

    private function normalizeMutationArguments(
        Client $client,
        string|float|int $currency,
        float|array $amount,
        array $options
    ): array {
        if (is_string($currency) && !is_numeric($currency)) {
            return [
                $this->normalizeCurrency($currency),
                (float) $amount,
                $options,
            ];
        }

        $legacyOptions = is_array($amount) ? $amount : $options;
        $legacyCurrency = (string) ($legacyOptions['currency_code'] ?? $this->resolvePrimaryWalletCurrency($client));

        return [
            $this->normalizeCurrency($legacyCurrency),
            (float) $currency,
            $legacyOptions,
        ];
    }
}
