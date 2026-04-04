<?php

namespace App\Services;

use App\Models\Client;
use App\Models\BillingRoutingDecision;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentCompletionService
{
    public function __construct(
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly WalletService $walletService,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly WalletSyncService $walletSyncService,
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function complete(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        return match ((string) $payment->purpose) {
            'wallet_topup' => $this->completeTopupPayment($payment, $providerPayload, $options),
            'subscription' => $this->completeSubscriptionPayment($payment, $providerPayload, $options),
            default => $this->completeGenericPayment($payment, $providerPayload, $options),
        };
    }

    public function completeTopupPayment(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        $payment->loadMissing(['client.platform', 'platform']);
        if ($payment->purpose !== 'wallet_topup') {
            throw new InvalidArgumentException('Only wallet top-up payments can credit a wallet balance.');
        }

        if ($this->isSandboxPayment($payment)) {
            $alreadyCompleted = (string) $payment->status === 'completed'
                && (bool) data_get($payment->payment_data, 'test_mode', false);

            $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                'payment_data' => $this->sandboxMetadata($payment, 'completed'),
            ]));

            return [
                'payment' => $payment,
                'credited' => false,
                'replayed' => $alreadyCompleted,
                'wallet' => $payment->client
                    ? $this->walletService->summary($payment->client, $this->walletRecentTransactionsLimit($payment))
                    : null,
            ];
        }

        if ($payment->wallet_transaction_id) {
            if ($payment->client) {
                $this->walletSyncService->syncClientBalance($payment->client);
            }

            return [
                'payment' => $payment->fresh(['platform', 'client']),
                'credited' => false,
                'replayed' => true,
                'wallet' => $payment->client
                    ? $this->walletService->summary($payment->client, $this->walletRecentTransactionsLimit($payment))
                    : null,
            ];
        }

        $transactionReference = $this->resolveTransactionReference($payment, $providerPayload, $options);
        $resolvedProvider = $this->resolveProviderType($payment);
        $resolvedEnvironment = $this->resolveExecutionEnvironment($payment);
        $payment->forceFill([
            'status' => 'completed',
            'failure_reason' => null,
            'completed_at' => $payment->completed_at ?? now(),
            'transaction_reference' => (string) $transactionReference,
            'raw_payload' => $this->mergeRawPayload($payment, $providerPayload, $options),
        ])->save();

        $credit = $this->walletService->credit($payment->client, (float) $payment->amount, [
            'payment' => $payment,
            'reference_type' => 'wallet_topup',
            'reference_id' => (int) $payment->id,
            'idempotency_key' => 'wallet-topup-credit:' . (int) $payment->id,
            'description' => sprintf('Wallet top-up via %s', strtoupper($resolvedProvider)),
            'metadata' => [
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'transaction_reference' => $transactionReference,
            ],
        ]);

        $payment->refresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $autoSubscribe = is_array($paymentData['auto_subscribe'] ?? null) ? $paymentData['auto_subscribe'] : null;
        $autoSubscribeResult = null;

        if ($autoSubscribe && !empty($autoSubscribe['enabled'])) {
            try {
                $productId = (int) ($autoSubscribe['product_id'] ?? 0);
                $duration = (string) ($autoSubscribe['duration'] ?? '');
                $product = Product::query()
                    ->where('platform_id', (int) $payment->platform_id)
                    ->findOrFail($productId);

                $checkout = $this->walletCheckoutService->payForSubscriptionFromWallet(
                    $payment->client,
                    $product,
                    $duration,
                    'wallet-auto-subscribe:' . (int) $payment->id,
                    [
                        'environment' => $resolvedEnvironment,
                        'origin' => 'wallet_auto_subscribe',
                        'topup_payment_id' => (int) $payment->id,
                    ]
                );

                $autoSubscribeResult = [
                    'status' => 'completed',
                    'subscription_payment_id' => (int) ($checkout['payment']->id ?? 0),
                    'deal_id' => (int) ($checkout['deal']?->id ?? 0),
                ];
            } catch (\Throwable $exception) {
                $autoSubscribeResult = [
                    'status' => 'failed',
                    'message' => mb_substr($exception->getMessage(), 0, 190),
                ];
            }
        }

        if ($autoSubscribeResult !== null) {
            $payment->forceFill([
                'payment_data' => array_merge($paymentData, [
                    'auto_subscribe' => array_merge($autoSubscribe, [
                        'result' => $autoSubscribeResult,
                    ]),
                ]),
            ])->save();
        }

        return [
            'payment' => $payment->fresh(['platform', 'client']),
            'credited' => true,
            'replayed' => false,
            'wallet' => $this->walletService->summary($credit['client'], $this->walletRecentTransactionsLimit($payment)),
            'auto_subscribe' => $autoSubscribeResult,
        ];
    }

    public function completeSubscriptionPayment(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        return DB::transaction(function () use ($payment, $providerPayload, $options) {
            $payment = $this->markPaymentCompleted($payment, $providerPayload, $options);
            $client = $options['client'] ?? $this->resolveClientForPayment($payment);
            $deal = null;

            if ($this->isSandboxPayment($payment)) {
                $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                    'payment_data' => $this->sandboxMetadata($payment, 'completed'),
                ]));

                return [
                    'payment' => $payment->fresh(['platform', 'client', 'deal', 'product']),
                    'client' => $client,
                    'deal' => null,
                    'provisioned' => false,
                ];
            }

            if ($client) {
                $deal = $this->subscriptionProvisioningService->provisionCompletedPayment($payment, [
                    'client' => $client,
                    'confirmed_at' => $options['confirmed_at'] ?? ($payment->confirmed_at ?? now()),
                    'match_confidence' => $options['match_confidence'] ?? ($payment->match_confidence ?: 'auto_high'),
                    'reconciliation_confidence' => $options['reconciliation_confidence'] ?? 'high',
                    'reconciliation_state' => $options['reconciliation_state'] ?? 'resolved',
                    'payment_method' => $options['payment_method'] ?? $this->resolvePaymentMethod(
                        $payment,
                        is_array($options['metadata'] ?? null) ? $options['metadata'] : null,
                        is_array($options['raw_context'] ?? null) ? $options['raw_context'] : []
                    ),
                    'duration_days' => $options['duration_days'] ?? null,
                    'payment_reference' => $options['payment_reference']
                        ?? $payment->transaction_reference
                        ?? $payment->reference_number,
                    'emit_payment_received_timeline' => $options['emit_payment_received_timeline'] ?? true,
                    'emit_profile_activated_timeline' => $options['emit_profile_activated_timeline'] ?? false,
                    'emit_deal_activated_timeline' => $options['emit_deal_activated_timeline'] ?? true,
                ]);
            } else {
                Log::warning('Successful payment could not be linked to a CRM client', [
                    'payment_id' => $payment->id,
                    'platform_id' => $payment->platform_id,
                    'user_id' => $payment->user_id,
                ]);
            }

            return [
                'payment' => $payment->fresh(['platform', 'client', 'deal', 'product']),
                'client' => $client,
                'deal' => $deal?->fresh(['client', 'product', 'platform']),
                'provisioned' => $deal !== null,
            ];
        });
    }

    public function completeGenericPayment(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        if ($this->isSandboxPayment($payment)) {
            $options['payment_data'] = $this->sandboxMetadata($payment, 'completed');
        }

        return [
            'payment' => $this->markPaymentCompleted($payment, $providerPayload, $options),
            'replayed' => false,
        ];
    }

    public function resolveClientForPayment(Payment $payment): ?Client
    {
        if ($payment->client_id) {
            return Client::find($payment->client_id);
        }

        if ($payment->platform_id && $payment->user_id) {
            $client = Client::query()
                ->where('platform_id', $payment->platform_id)
                ->where('wp_user_id', $payment->user_id)
                ->orderByDesc('id')
                ->first();
            if ($client) {
                return $client;
            }
        }

        if ($payment->platform_id && $payment->escort_post_id) {
            $client = Client::query()
                ->where('platform_id', $payment->platform_id)
                ->where('wp_post_id', $payment->escort_post_id)
                ->first();
            if ($client) {
                return $client;
            }
        }

        if ($payment->platform_id && $payment->phone) {
            $match = app(PaymentMatchingService::class)->matchPayment($payment);
            if (!empty($match['matched']) && !empty($match['client_id'])) {
                return Client::find($match['client_id']);
            }
        }

        return null;
    }

    public function resolvePaymentMethod(Payment $payment, ?array $metadata = null, array $rawData = []): string
    {
        $method = data_get($payment->raw_payload, 'method')
            ?? data_get($metadata, 'payment_method')
            ?? data_get($rawData, 'payment_method')
            ?? data_get($rawData, 'source')
            ?? 'provider';

        return strtolower(trim((string) $method)) ?: 'provider';
    }

    private function markPaymentCompleted(Payment $payment, array $providerPayload = [], array $options = []): Payment
    {
        $payment->loadMissing(['client.platform', 'platform', 'product', 'deal']);
        $paymentData = array_merge(
            is_array($payment->payment_data) ? $payment->payment_data : [],
            is_array($options['payment_data'] ?? null) ? $options['payment_data'] : []
        );

        $payment->forceFill([
            'status' => 'completed',
            'failure_reason' => null,
            'completed_at' => $payment->completed_at ?? now(),
            'transaction_reference' => (string) $this->resolveTransactionReference($payment, $providerPayload, $options),
            'raw_payload' => $this->mergeRawPayload($payment, $providerPayload, $options),
            'payment_data' => !empty($paymentData) ? $paymentData : $payment->payment_data,
        ])->save();

        return $payment->fresh(['platform', 'client', 'deal', 'product']);
    }

    private function resolveTransactionReference(Payment $payment, array $providerPayload = [], array $options = []): string
    {
        return (string) (
            $options['transaction_reference']
            ?? data_get($providerPayload, 'reference')
            ?? data_get($providerPayload, 'order_tracking_id')
            ?? data_get($providerPayload, 'orderTrackingId')
            ?? $payment->transaction_reference
            ?? $payment->reference_number
        );
    }

    private function mergeRawPayload(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        $rawPayload = array_merge(
            is_array($payment->raw_payload) ? $payment->raw_payload : [],
            is_array($options['raw_payload'] ?? null) ? $options['raw_payload'] : []
        );

        if (!empty($providerPayload) && !array_key_exists('completion_payload', $rawPayload)) {
            $rawPayload['completion_payload'] = $providerPayload;
        }

        return $rawPayload;
    }

    private function isSandboxPayment(Payment $payment): bool
    {
        return (bool) data_get($payment->payment_data, 'test_mode', false)
            || (
                strtolower(trim((string) $payment->source)) === 'gateway'
                && $this->resolveExecutionEnvironment($payment) === 'sandbox'
            );
    }

    private function resolveExecutionEnvironment(Payment $payment): string
    {
        $decision = $this->latestPinnedDecision($payment);

        if ($decision instanceof BillingRoutingDecision) {
            return strtolower(trim((string) ($decision->environment ?: 'production')));
        }

        return strtolower(trim((string) ($payment->provider_environment ?: 'production')));
    }

    private function sandboxMetadata(Payment $payment, string $result): array
    {
        $existing = is_array($payment->payment_data) ? $payment->payment_data : [];

        return array_merge($existing, [
            'test_mode' => true,
            'test_result' => $result,
            'side_effects_skipped' => true,
            'verified_at' => (string) ($existing['verified_at'] ?? now()->toIso8601String()),
        ]);
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        if ($payment->relationLoaded('routingDecisions')) {
            return $payment->routingDecisions
                ->sortByDesc(function (BillingRoutingDecision $decision) {
                    return optional($decision->created_at)->getTimestamp() ?? 0;
                })
                ->first();
        }

        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function resolveProviderType(Payment $payment): string
    {
        $decision = $this->latestPinnedDecision($payment);

        if ($decision instanceof BillingRoutingDecision) {
            $provider = strtolower(trim((string) $decision->provider_type_key));
            if ($provider !== '') {
                return $provider;
            }
        }

        return strtolower(trim((string) $payment->provider_key));
    }

    private function walletRecentTransactionsLimit(Payment $payment, int $default = 10): int
    {
        return $this->walletSettingsService->runtimeRecentTransactionsLimit($payment->platform, $default);
    }
}
