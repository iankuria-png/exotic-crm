<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Billing\Support\BillingRoutingDecisionRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WalletCheckoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder
    ) {
    }

    public function resolveSubscriptionPricing(Product $product, string $duration): array
    {
        $product->loadMissing(['activePrices', 'platform']);

        $normalized = $this->normalizeDuration($duration);
        $durationKey = $normalized['duration_key'];
        $legacyDuration = $normalized['legacy_duration'];

        $priceRow = $product->activePrices
            ->firstWhere('duration_key', $durationKey);

        $amount = null;
        $currency = null;
        $durationDays = null;
        $durationLabel = $normalized['duration_label'];

        if ($priceRow instanceof ProductPrice) {
            $amount = round((float) $priceRow->price, 2);
            $currency = $priceRow->currency;
            $durationDays = (int) ($priceRow->duration_days ?: $normalized['duration_days']);
            $durationLabel = $priceRow->duration_label ?: $durationLabel;
        } else {
            $amount = match ($legacyDuration) {
                'weekly' => (float) $product->weekly_price,
                'biweekly' => (float) $product->biweekly_price,
                default => (float) $product->monthly_price,
            };
            $currency = $product->currency ?: ($product->platform?->currency_code ?: 'KES');
            $durationDays = $normalized['duration_days'];
        }

        if ($amount === null || $amount <= 0) {
            throw new InvalidArgumentException('The selected package duration is not currently purchasable.');
        }

        return [
            'product' => $product,
            'duration_key' => $durationKey,
            'legacy_duration' => $legacyDuration,
            'duration_days' => $durationDays,
            'duration_label' => $durationLabel,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper((string) $currency),
        ];
    }

    public function payForSubscriptionFromWallet(
        Client $client,
        Product $product,
        string $duration,
        string $idempotencyKey,
        array $options = []
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Wallet checkout idempotency key is required.');
        }

        $pricing = $this->resolveSubscriptionPricing($product, $duration);
        $referenceNumber = $this->walletReference('WSUB', [
            $client->platform_id,
            $client->id,
            $product->id,
            $pricing['duration_key'],
            $idempotencyKey,
        ]);

        $result = DB::transaction(function () use ($client, $product, $pricing, $idempotencyKey, $options, $referenceNumber) {
            $existingTransaction = $client->walletTransactions()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingTransaction) {
                $payment = $existingTransaction->payment;
                $deal = $payment?->deal;

                return [
                    'client' => $client->fresh(['platform']),
                    'transaction' => $existingTransaction,
                    'payment' => $payment,
                    'deal' => $deal,
                    'pricing' => $pricing,
                    'replayed' => true,
                ];
            }

            $lockedClient = Client::query()->with('platform')->lockForUpdate()->findOrFail((int) $client->id);
            $currentBalance = round((float) ($lockedClient->wallet_balance ?? 0), 2);
            $amount = round((float) $pricing['amount'], 2);

            if ($currentBalance < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $payment = Payment::query()->create([
                'user_id' => $lockedClient->wp_user_id,
                'escort_post_id' => $lockedClient->wp_post_id,
                'platform_id' => (int) $lockedClient->platform_id,
                'product_id' => (int) $product->id,
                'client_id' => (int) $lockedClient->id,
                'phone' => $lockedClient->phone_normalized,
                'amount' => $pricing['amount'],
                'currency' => $pricing['currency'],
                'transaction_uuid' => (string) Str::uuid(),
                'transaction_reference' => $referenceNumber,
                'reference_number' => $referenceNumber,
                'status' => 'completed',
                'purpose' => 'subscription',
                'source' => 'wallet',
                'provider_key' => 'wallet',
                'provider_environment' => $options['environment'] ?? null,
                'duration' => $pricing['legacy_duration'],
                'completed_at' => now(),
                'raw_payload' => [
                    'method' => 'wallet',
                    'wallet_checkout' => true,
                ],
                'payment_data' => [
                    'duration_key' => $pricing['duration_key'],
                    'duration_days' => $pricing['duration_days'],
                    'duration_label' => $pricing['duration_label'],
                    'idempotency_key' => $idempotencyKey,
                    'origin' => $options['origin'] ?? 'wallet_subscribe',
                    'topup_payment_id' => isset($options['topup_payment_id']) ? (int) $options['topup_payment_id'] : null,
                ],
            ]);

            $this->billingRoutingDecisionRecorder->recordWalletSubscription($payment, $pricing, [
                'environment' => $options['environment'] ?? null,
                'origin' => $options['origin'] ?? 'wallet_subscribe',
                'topup_payment_id' => $options['topup_payment_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $debit = $this->walletService->debit($lockedClient, $amount, [
                'payment' => $payment,
                'reference_type' => 'wallet_subscription',
                'reference_id' => (int) $payment->id,
                'idempotency_key' => $idempotencyKey,
                'description' => sprintf(
                    'Wallet subscription payment for %s (%s)',
                    $product->display_name ?: $product->name,
                    $pricing['duration_label']
                ),
                'metadata' => [
                    'product_id' => (int) $product->id,
                    'duration_key' => $pricing['duration_key'],
                    'duration_days' => $pricing['duration_days'],
                ],
            ]);

            return [
                'client' => $debit['client'],
                'transaction' => $debit['transaction'],
                'payment' => $payment->fresh(['client', 'platform', 'product']),
                'deal' => null,
                'pricing' => $pricing,
                'replayed' => false,
            ];
        }, 3);

        $payment = $result['payment'];
        if ($result['replayed'] && $payment) {
            app(WalletSyncService::class)->syncClientBalance($client);

            return $this->hydrateResult($result);
        }

        try {
            $deal = $this->subscriptionProvisioningService->provisionCompletedPayment($payment, [
                'client' => $client->fresh(['platform']),
                'confirmed_at' => now(),
                'match_confidence' => 'manual',
                'reconciliation_confidence' => 'high',
                'reconciliation_state' => 'resolved',
                'payment_method' => 'wallet',
                'duration_days' => (int) $result['pricing']['duration_days'],
                'payment_reference' => $payment->reference_number,
                'emit_payment_received_timeline' => true,
                'emit_profile_activated_timeline' => false,
                'emit_deal_activated_timeline' => true,
            ]);

            return $this->hydrateResult(array_merge($result, [
                'deal' => $deal,
            ]));
        } catch (Throwable $exception) {
            $this->compensateFailedCheckout($client, $payment, $result['transaction'], $exception);

            throw $exception;
        }
    }

    private function compensateFailedCheckout(Client $client, Payment $payment, $debitTransaction, Throwable $exception): void
    {
        DB::transaction(function () use ($client, $payment, $debitTransaction, $exception) {
            $reason = mb_substr($exception->getMessage(), 0, 190);
            $creditIdempotencyKey = 'wallet-compensation:' . (int) $payment->id;

            $credit = $this->walletService->credit($client, (float) $payment->amount, [
                'reference_type' => 'wallet_compensation',
                'reference_id' => (int) $payment->id,
                'idempotency_key' => $creditIdempotencyKey,
                'description' => 'Wallet subscription compensation after activation failure',
                'metadata' => [
                    'failed_payment_id' => (int) $payment->id,
                    'failed_wallet_transaction_id' => (int) ($debitTransaction->id ?? 0),
                    'error' => $reason,
                ],
            ]);

            $deal = $payment->deal ?: Deal::query()->where('payment_id', (int) $payment->id)->latest('id')->first();
            if ($deal && $deal->status !== 'active') {
                $deal->forceFill([
                    'status' => 'failed',
                ])->save();
            }

            $payment->forceFill([
                'status' => 'failed',
                'failure_reason' => $reason,
                'raw_payload' => array_merge($payment->raw_payload ?? [], [
                    'wallet_compensation_transaction_id' => (int) $credit['transaction']->id,
                    'wallet_checkout_error' => $reason,
                ]),
            ])->save();
        }, 3);
    }

    private function hydrateResult(array $result): array
    {
        /** @var Payment|null $payment */
        $payment = $result['payment'] ?? null;

        return [
            'client' => ($result['client'] instanceof Client ? $result['client'] : null)?->fresh(['platform']),
            'transaction' => $result['transaction'],
            'payment' => $payment?->fresh(['client', 'deal', 'platform', 'product']),
            'deal' => ($result['deal'] instanceof Deal ? $result['deal'] : null)?->fresh(['client', 'product', 'platform']),
            'pricing' => $result['pricing'],
            'replayed' => (bool) ($result['replayed'] ?? false),
        ];
    }

    private function normalizeDuration(string $duration): array
    {
        $normalized = strtolower(trim($duration));

        return match ($normalized) {
            '1_week', 'weekly', 'week' => [
                'duration_key' => '1_week',
                'legacy_duration' => 'weekly',
                'duration_days' => 7,
                'duration_label' => '1 Week',
            ],
            '2_weeks', 'biweekly', '2weeks' => [
                'duration_key' => '2_weeks',
                'legacy_duration' => 'biweekly',
                'duration_days' => 14,
                'duration_label' => '2 Weeks',
            ],
            '1_month', 'monthly', 'month' => [
                'duration_key' => '1_month',
                'legacy_duration' => 'monthly',
                'duration_days' => 30,
                'duration_label' => '1 Month',
            ],
            default => throw new InvalidArgumentException('Unsupported subscription duration.'),
        };
    }

    private function walletReference(string $prefix, array $parts): string
    {
        $hash = strtoupper(substr(hash('sha256', implode('|', $parts)), 0, 18));

        return $prefix . '-' . $hash;
    }
}
