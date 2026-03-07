<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class WalletService
{
    public function summary(Client $client, int $limit = 10): array
    {
        $freshClient = $client->fresh(['platform']) ?? $client->loadMissing('platform');
        $transactions = $this->recentTransactions($freshClient, $limit);
        $lastTopup = $this->lastTopup($freshClient);

        return [
            'balance' => number_format((float) ($freshClient->wallet_balance ?? 0), 2, '.', ''),
            'currency' => (string) ($freshClient->wallet_currency ?: ($freshClient->platform?->currency_code ?: 'KES')),
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

    public function credit(Client $client, float $amount, array $options = []): array
    {
        return $this->mutateBalance($client, 'credit', $amount, $options);
    }

    public function debit(Client $client, float $amount, array $options = []): array
    {
        return $this->mutateBalance($client, 'debit', $amount, $options);
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

    private function mutateBalance(Client $client, string $type, float $amount, array $options = []): array
    {
        $normalizedAmount = round($amount, 2);
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Unsupported wallet transaction type.');
        }

        if ($normalizedAmount <= 0) {
            throw new InvalidArgumentException('Wallet amount must be greater than zero.');
        }

        return DB::transaction(function () use ($client, $type, $normalizedAmount, $options) {
            $idempotencyKey = isset($options['idempotency_key']) ? trim((string) $options['idempotency_key']) : '';
            if ($idempotencyKey !== '') {
                $existing = WalletTransaction::query()
                    ->where('client_id', (int) $client->id)
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

            $currentBalance = round((float) ($lockedClient->wallet_balance ?? 0), 2);
            if ($type === 'debit' && $currentBalance < $normalizedAmount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $nextBalance = $type === 'debit'
                ? $currentBalance - $normalizedAmount
                : $currentBalance + $normalizedAmount;

            $currencyCode = strtoupper((string) ($options['currency_code']
                ?? $lockedClient->wallet_currency
                ?? $lockedClient->platform?->currency_code
                ?? 'KES'));

            $lockedClient->forceFill([
                'wallet_balance' => number_format($nextBalance, 2, '.', ''),
                'wallet_currency' => $currencyCode,
            ])->save();

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
}
