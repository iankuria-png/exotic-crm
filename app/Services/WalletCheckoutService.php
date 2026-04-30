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
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder,
        private readonly SelfServiceIncentiveService $selfServiceIncentiveService
    ) {
    }

    public function resolveSubscriptionPricing(Product $product, string $duration, ?string $currency = null): array
    {
        $product->loadMissing(['activePrices', 'platform']);

        $normalized = $this->normalizeDuration($duration);
        $durationKey = $normalized['duration_key'];
        $legacyDuration = $normalized['legacy_duration'];
        $effectiveCurrencies = $product->platform?->effectiveCurrencies()
            ?? [strtoupper((string) ($product->platform?->currency_code ?: $product->currency ?: 'KES'))];
        $requestedCurrency = strtoupper(trim((string) ($currency ?? '')));

        if ($requestedCurrency === '') {
            if (count($effectiveCurrencies) > 1) {
                throw new InvalidArgumentException('currency required for multi-currency market');
            }

            $requestedCurrency = strtoupper((string) ($product->platform?->primaryCurrency() ?: $product->currency ?: 'KES'));
        }

        if (!in_array($requestedCurrency, $effectiveCurrencies, true)) {
            throw new InvalidArgumentException(sprintf('%s is not supported for this market.', $requestedCurrency));
        }

        $priceRow = $product->activePrices
            ->first(fn (ProductPrice $row) => $row->duration_key === $durationKey
                && strtoupper((string) $row->currency) === $requestedCurrency);

        $amount = null;
        $durationDays = null;
        $durationLabel = $normalized['duration_label'];

        if ($priceRow instanceof ProductPrice) {
            $amount = round((float) $priceRow->price, 2);
            $durationDays = (int) ($priceRow->duration_days ?: $normalized['duration_days']);
            $durationLabel = $priceRow->duration_label ?: $durationLabel;
        } elseif (count($effectiveCurrencies) === 1) {
            $amount = match ($legacyDuration) {
                'weekly' => (float) $product->weekly_price,
                'biweekly' => (float) $product->biweekly_price,
                default => (float) $product->monthly_price,
            };
            $durationDays = $normalized['duration_days'];
        } else {
            throw new InvalidArgumentException(sprintf(
                '%s price not configured for this plan/duration',
                $requestedCurrency
            ));
        }

        if ($amount === null || $amount <= 0) {
            if (count($effectiveCurrencies) > 1) {
                throw new InvalidArgumentException(sprintf(
                    '%s price not configured for this plan/duration',
                    $requestedCurrency
                ));
            }

            throw new InvalidArgumentException('The selected package duration is not currently purchasable.');
        }

        return [
            'product' => $product,
            'duration_key' => $durationKey,
            'legacy_duration' => $legacyDuration,
            'duration_days' => $durationDays,
            'duration_label' => $durationLabel,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $requestedCurrency,
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

        $pricing = $this->resolveSubscriptionPricing($product, $duration, $options['currency'] ?? null);
        $incentivePercent = $this->selfServiceIncentiveService->resolveForPlatform((int) $product->platform_id, 'wallet');
        $incentive = $this->selfServiceIncentiveService->applyToAmount((float) $pricing['amount'], $incentivePercent);
        if ($incentive) {
            $pricing['original_amount'] = $incentive['original_amount'];
            $pricing['amount'] = number_format($incentive['amount'], 2, '.', '');
            $pricing['discount_percent'] = $incentive['percent'];
        }
        $referenceNumber = $this->walletReference('WSUB', [
            $client->platform_id,
            $client->id,
            $product->id,
            $pricing['duration_key'],
            $idempotencyKey,
        ]);

        $result = DB::transaction(function () use ($client, $product, $pricing, $idempotencyKey, $options, $referenceNumber) {
            $existingTransaction = $client->walletTransactions()
                ->where('currency_code', $pricing['currency'])
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
                    'self_service_incentive' => !empty($pricing['discount_percent']) ? [
                        'original_amount' => (float) $pricing['original_amount'],
                        'percent' => (float) $pricing['discount_percent'],
                        'source' => 'self_service_incentive',
                    ] : null,
                ],
            ]);

            $this->billingRoutingDecisionRecorder->recordWalletSubscription($payment, $pricing, [
                'environment' => $options['environment'] ?? null,
                'origin' => $options['origin'] ?? 'wallet_subscribe',
                'topup_payment_id' => $options['topup_payment_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $debit = $this->walletService->debit($lockedClient, $pricing['currency'], (float) $pricing['amount'], [
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

    public function resolveDealRenewalPricing(Deal $deal): array
    {
        $deal->loadMissing(['product.platform', 'platform']);

        if (!$deal->product) {
            throw new InvalidArgumentException('The active subscription has no linked product for wallet renewal.');
        }

        $effectiveCurrencies = $deal->product->platform?->effectiveCurrencies()
            ?? $deal->platform?->effectiveCurrencies()
            ?? [strtoupper((string) ($deal->product->currency ?: $deal->platform?->currency_code ?: 'KES'))];
        $dealCurrency = strtoupper(trim((string) ($deal->currency ?? '')));

        if ($dealCurrency === '') {
            if (count($effectiveCurrencies) > 1) {
                throw new InvalidArgumentException('Deal currency is required for multi-currency renewal.');
            }

            $dealCurrency = strtoupper((string) ($deal->product->platform?->primaryCurrency() ?: $deal->product->currency ?: $deal->platform?->currency_code ?: 'KES'));
        }

        return $this->resolveSubscriptionPricing(
            $deal->product,
            (string) ($deal->duration ?: 'monthly'),
            $dealCurrency
        );
    }

    public function autoRenewDealFromWallet(
        Deal $deal,
        string $idempotencyKey,
        array $options = []
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Wallet auto-renew idempotency key is required.');
        }

        $deal->loadMissing(['client.platform', 'product']);
        if (!$deal->client) {
            throw new InvalidArgumentException('Deal has no linked client for wallet auto-renew.');
        }

        $pricing = $this->resolveDealRenewalPricing($deal);
        $referenceNumber = $this->walletReference('WREN', [
            $deal->platform_id,
            $deal->client_id,
            $deal->id,
            $pricing['duration_key'],
            $idempotencyKey,
        ]);

        $result = DB::transaction(function () use ($deal, $pricing, $idempotencyKey, $options, $referenceNumber) {
            $lockedDeal = Deal::query()
                ->with(['client.platform', 'product'])
                ->lockForUpdate()
                ->findOrFail((int) $deal->id);

            if ((string) $lockedDeal->status !== 'active') {
                throw new RuntimeException('Only active subscriptions can be auto-renewed from wallet.');
            }

            $lockedClient = $lockedDeal->client;
            if (!$lockedClient) {
                throw new RuntimeException('Wallet auto-renew requires a linked client.');
            }

            $existingTransaction = $lockedClient->walletTransactions()
                ->where('currency_code', $pricing['currency'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingTransaction) {
                $payment = $existingTransaction->payment;

                return [
                    'client' => $lockedClient->fresh(['platform']),
                    'transaction' => $existingTransaction,
                    'payment' => $payment,
                    'deal' => $lockedDeal,
                    'pricing' => $pricing,
                    'previous_expires_at' => optional($lockedDeal->expires_at)->toDateTimeString(),
                    'replayed' => true,
                ];
            }

            $payment = Payment::query()->create([
                'user_id' => $lockedClient->wp_user_id,
                'escort_post_id' => $lockedClient->wp_post_id,
                'platform_id' => (int) $lockedDeal->platform_id,
                'product_id' => (int) $lockedDeal->product_id,
                'client_id' => (int) $lockedClient->id,
                'deal_id' => (int) $lockedDeal->id,
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
                'duration' => $lockedDeal->duration,
                'completed_at' => now(),
                'raw_payload' => [
                    'method' => 'wallet',
                    'wallet_auto_renew' => true,
                ],
                'payment_data' => [
                    'duration_key' => $pricing['duration_key'],
                    'duration_days' => $pricing['duration_days'],
                    'duration_label' => $pricing['duration_label'],
                    'idempotency_key' => $idempotencyKey,
                    'origin' => $options['origin'] ?? 'wallet_auto_subscribe',
                    'renewal_mode' => 'auto_renew',
                    'renewed_deal_id' => (int) $lockedDeal->id,
                    'cycle_expires_at' => optional($lockedDeal->expires_at)->toDateTimeString(),
                ],
            ]);

            $this->billingRoutingDecisionRecorder->recordWalletSubscription($payment, $pricing, [
                'environment' => $options['environment'] ?? null,
                'origin' => $options['origin'] ?? 'wallet_auto_subscribe',
                'idempotency_key' => $idempotencyKey,
                'topup_payment_id' => $options['topup_payment_id'] ?? null,
            ]);

            $debit = $this->walletService->debit($lockedClient, $pricing['currency'], (float) $pricing['amount'], [
                'payment' => $payment,
                'deal_id' => (int) $lockedDeal->id,
                'reference_type' => 'wallet_auto_renew',
                'reference_id' => (int) $payment->id,
                'idempotency_key' => $idempotencyKey,
                'description' => sprintf(
                    'Wallet auto-renew for %s (%s)',
                    $lockedDeal->product?->display_name ?: $lockedDeal->product?->name ?: 'subscription',
                    $pricing['duration_label']
                ),
                'metadata' => [
                    'deal_id' => (int) $lockedDeal->id,
                    'product_id' => (int) $lockedDeal->product_id,
                    'duration_key' => $pricing['duration_key'],
                    'duration_days' => $pricing['duration_days'],
                    'cycle_expires_at' => optional($lockedDeal->expires_at)->toDateTimeString(),
                ],
            ]);

            $wpPostId = (int) ($lockedClient->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                throw new RuntimeException('Client is not linked to a WordPress profile.');
            }

            $previousExpiry = $lockedDeal->expires_at ? $lockedDeal->expires_at->copy() : null;
            $baseExpiry = $previousExpiry && $previousExpiry->isFuture()
                ? $previousExpiry->copy()
                : now();
            $newExpiry = $baseExpiry->copy()->addDays((int) $pricing['duration_days']);

            $wpSync = WpSyncService::forPlatform((int) $lockedDeal->platform_id);
            $wpSync->extendClient($wpPostId, (int) $pricing['duration_days']);

            $lockedDeal->forceFill([
                'expires_at' => $newExpiry,
                'payment_id' => (int) $payment->id,
                'payment_reference' => $payment->transaction_reference,
                'is_free_trial' => false,
                'free_trial_approved_by' => null,
            ])->save();

            $payment->forceFill([
                'start_date' => now(),
                'end_date' => $newExpiry,
                'completed_at' => $payment->completed_at ?? now(),
                'client_id' => (int) $lockedClient->id,
                'deal_id' => (int) $lockedDeal->id,
                'reconciliation_confidence' => 'high',
                'reconciliation_state' => 'resolved',
                'match_confidence' => 'manual',
            ])->save();

            return [
                'client' => $debit['client'],
                'transaction' => $debit['transaction'],
                'payment' => $payment->fresh(['client', 'deal', 'platform', 'product']),
                'deal' => $lockedDeal->fresh(['client', 'product', 'platform']),
                'pricing' => $pricing,
                'previous_expires_at' => optional($previousExpiry)->toDateTimeString(),
                'replayed' => false,
            ];
        }, 3);

        $client = $deal->client;
        if ($client) {
            app(WalletSyncService::class)->syncClientBalance($client);
        }

        $freshDeal = ($result['deal'] instanceof Deal ? $result['deal'] : null)?->fresh(['client.platform', 'product', 'platform']);
        if ($freshDeal?->client?->wp_post_id) {
            $syncedClient = (new ClientSyncService($freshDeal->platform))->syncOne((int) $freshDeal->client->wp_post_id);
            $freshDeal->setRelation('client', $syncedClient);
            $result['client'] = $syncedClient->fresh(['platform']);
        }

        return [
            'client' => ($result['client'] instanceof Client ? $result['client'] : null)?->fresh(['platform']),
            'transaction' => $result['transaction'],
            'payment' => ($result['payment'] instanceof Payment ? $result['payment'] : null)?->fresh(['client', 'deal', 'platform', 'product']),
            'deal' => $freshDeal,
            'pricing' => $result['pricing'],
            'previous_expires_at' => $result['previous_expires_at'] ?? null,
            'replayed' => (bool) ($result['replayed'] ?? false),
        ];
    }

    private function compensateFailedCheckout(Client $client, Payment $payment, $debitTransaction, Throwable $exception): void
    {
        DB::transaction(function () use ($client, $payment, $debitTransaction, $exception) {
            $reason = mb_substr($exception->getMessage(), 0, 190);
            $creditIdempotencyKey = 'wallet-compensation:' . (int) $payment->id;

            $credit = $this->walletService->credit($client, (string) $payment->currency, (float) $payment->amount, [
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
