<?php

namespace App\Services;

use App\Billing\Settlement\SettlementTolerancePolicy;
use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Billing\Support\CanonicalPaymentStateReducer;
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
        private readonly WalletSettingsService $walletSettingsService,
        private readonly CanonicalPaymentStateReducer $canonicalPaymentStateReducer,
        private readonly SettlementTolerancePolicy $settlementTolerancePolicy,
        private readonly BillingProviderTransactionRecorder $billingProviderTransactionRecorder
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

        $settlementAssessment = $this->settlementTolerancePolicy->evaluate($payment, $providerPayload, $options);
        $this->billingProviderTransactionRecorder->recordSettlement($payment, $settlementAssessment, $providerPayload);

        if ($this->shouldHoldForSettlementReview($settlementAssessment)) {
            $payment = $this->markPaymentForSettlementReview($payment, $providerPayload, array_merge($options, [
                'payment_intent_status' => $this->settlementIntentStatus($settlementAssessment),
                'wallet_funding_status' => 'underpaid',
                'transition' => 'wallet_funding_settlement_review',
                'payment_data' => array_merge(
                    is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                    [
                    'settlement_assessment' => $settlementAssessment,
                    ]
                ),
            ]));

            return [
                'payment' => $payment,
                'credited' => false,
                'replayed' => false,
                'wallet' => $payment->client
                    ? $this->walletService->summary($payment->client, $this->walletRecentTransactionsLimit($payment))
                    : null,
            ];
        }

        $transactionReference = $this->resolveTransactionReference($payment, $providerPayload, $options);
        $resolvedProvider = $this->resolveProviderType($payment);
        $resolvedEnvironment = $this->resolveExecutionEnvironment($payment);

        $credit = $this->walletService->credit($payment->client, (string) $payment->currency, (float) $payment->amount, [
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

        $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
            'transaction_reference' => $transactionReference,
            'wallet_funding_status' => 'credited',
            'transition' => 'wallet_credit_succeeded',
            'payment_data' => array_merge(
                is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                [
                    'settlement_assessment' => $settlementAssessment,
                    'settlement_review_required' => (bool) ($settlementAssessment['review_required'] ?? false),
                ]
            ),
        ]));

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
                        'currency' => (string) ($autoSubscribe['currency'] ?? $payment->currency),
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
            $client = $options['client'] ?? $this->resolveClientForPayment($payment);
            $deal = null;
            $settlementAssessment = $this->settlementTolerancePolicy->evaluate($payment, $providerPayload, $options);
            $this->billingProviderTransactionRecorder->recordSettlement($payment, $settlementAssessment, $providerPayload);

            if ($this->isSandboxPayment($payment)) {
                $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                    'payment_data' => $this->sandboxMetadata($payment, 'completed'),
                    'provisioning_status' => 'suppressed_sandbox',
                    'sandbox_suppressed' => true,
                    'transition' => 'subscription_sandbox_succeeded',
                ]));

                return [
                    'payment' => $payment->fresh(['platform', 'client', 'deal', 'product']),
                    'client' => $client,
                    'deal' => null,
                    'provisioned' => false,
                ];
            }

            if ($this->shouldHoldForSettlementReview($settlementAssessment)) {
                $payment = $this->markPaymentForSettlementReview($payment, $providerPayload, array_merge($options, [
                    'payment_intent_status' => $this->settlementIntentStatus($settlementAssessment),
                    'provisioning_status' => $this->settlementProvisioningStatus($settlementAssessment),
                    'transition' => 'subscription_settlement_review',
                    'payment_data' => array_merge(
                        is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                        [
                        'settlement_assessment' => $settlementAssessment,
                        ]
                    ),
                ]));

                return [
                    'payment' => $payment->fresh(['platform', 'client', 'deal', 'product']),
                    'client' => $client,
                    'deal' => null,
                    'provisioned' => false,
                ];
            }

            $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                'provisioning_status' => 'pending',
                'transition' => 'subscription_provider_succeeded',
                'payment_data' => array_merge(
                    is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                    [
                        'settlement_assessment' => $settlementAssessment,
                        'settlement_review_required' => (bool) ($settlementAssessment['review_required'] ?? false),
                    ]
                ),
            ]));

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

                $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                    'client' => $client,
                    'provisioning_status' => 'completed',
                    'transition' => 'subscription_provisioned',
                ]));

                if ($deal) {
                    $dealId = (int) $deal->id;
                    DB::afterCommit(function () use ($dealId): void {
                        $freshDeal = Deal::query()->find($dealId);
                        if ($freshDeal) {
                            app(SubsidiaryTrialService::class)->activateIfPending($freshDeal);
                        }
                    });
                }
            } else {
                Log::warning('Successful payment could not be linked to a CRM client', [
                    'payment_id' => $payment->id,
                    'platform_id' => $payment->platform_id,
                    'user_id' => $payment->user_id,
                ]);

                $payment = $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                    'provisioning_status' => 'client_unresolved',
                    'transition' => 'subscription_client_unresolved',
                ]));
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

        $settlementAssessment = $this->settlementTolerancePolicy->evaluate($payment, $providerPayload, $options);
        $this->billingProviderTransactionRecorder->recordSettlement($payment, $settlementAssessment, $providerPayload);

        if ($this->shouldHoldForSettlementReview($settlementAssessment)) {
            return [
                'payment' => $this->markPaymentForSettlementReview($payment, $providerPayload, array_merge($options, [
                    'payment_intent_status' => $this->settlementIntentStatus($settlementAssessment),
                    'transition' => 'generic_settlement_review',
                    'payment_data' => array_merge(
                        is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                        [
                        'settlement_assessment' => $settlementAssessment,
                        ]
                    ),
                ])),
                'replayed' => false,
            ];
        }

        return [
            'payment' => $this->markPaymentCompleted($payment, $providerPayload, array_merge($options, [
                'payment_data' => array_merge(
                    is_array($options['payment_data'] ?? null) ? $options['payment_data'] : [],
                    [
                        'settlement_assessment' => $settlementAssessment,
                        'settlement_review_required' => (bool) ($settlementAssessment['review_required'] ?? false),
                    ]
                ),
            ])),
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

        $state = $this->canonicalPaymentStateReducer->complete($payment, [
            'payment_data' => $paymentData,
            'wallet_funding_status' => $options['wallet_funding_status'] ?? null,
            'provisioning_status' => $options['provisioning_status'] ?? null,
            'transition' => $options['transition'] ?? null,
            'sandbox_suppressed' => (bool) ($options['sandbox_suppressed'] ?? false),
        ]);

        $payment->forceFill(array_merge($state, [
            'transaction_reference' => (string) $this->resolveTransactionReference($payment, $providerPayload, $options),
            'raw_payload' => $this->mergeRawPayload($payment, $providerPayload, $options),
        ]))->save();

        return $payment->fresh(['platform', 'client', 'deal', 'product']);
    }

    private function markPaymentForSettlementReview(Payment $payment, array $providerPayload = [], array $options = []): Payment
    {
        $payment->loadMissing(['client.platform', 'platform', 'product', 'deal']);
        $paymentData = array_merge(
            is_array($payment->payment_data) ? $payment->payment_data : [],
            is_array($options['payment_data'] ?? null) ? $options['payment_data'] : []
        );

        $state = $this->canonicalPaymentStateReducer->reviewSettlement($payment, [
            'payment_data' => $paymentData,
            'payment_intent_status' => $options['payment_intent_status'] ?? 'underpaid',
            'wallet_funding_status' => $options['wallet_funding_status'] ?? null,
            'provisioning_status' => $options['provisioning_status'] ?? null,
            'transition' => $options['transition'] ?? 'settlement_review_required',
        ]);

        $payment->forceFill(array_merge($state, [
            'transaction_reference' => (string) $this->resolveTransactionReference($payment, $providerPayload, $options),
            'raw_payload' => $this->mergeRawPayload($payment, $providerPayload, $options),
        ]))->save();

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

    private function shouldHoldForSettlementReview(array $settlementAssessment): bool
    {
        return ($settlementAssessment['completion_policy'] ?? null) === 'hold_for_review';
    }

    private function settlementIntentStatus(array $settlementAssessment): string
    {
        return match ($settlementAssessment['settlement_status'] ?? null) {
            'currency_mismatch' => 'currency_mismatch',
            default => 'underpaid',
        };
    }

    private function settlementProvisioningStatus(array $settlementAssessment): string
    {
        return match ($settlementAssessment['settlement_status'] ?? null) {
            'currency_mismatch' => 'currency_mismatch_review_required',
            default => 'underpaid_review_required',
        };
    }

    private function walletRecentTransactionsLimit(Payment $payment, int $default = 10): int
    {
        return $this->walletSettingsService->runtimeRecentTransactionsLimit($payment->platform, $default);
    }
}
