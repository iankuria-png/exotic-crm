<?php

namespace App\Services;

use App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter;
use App\Billing\Providers\PawaPay\PawaPayCompatibilityAdapter;
use App\Billing\Providers\Pesapal\PesapalCompatibilityAdapter;
use App\Billing\Support\BillingRoutingDecisionRecorder;
use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Billing\Support\CanonicalPaymentStateReducer;
use App\Models\BillingWebhookEvent;
use App\Models\BillingRoutingDecision;
use App\Models\BillingProviderProfile;
use App\Models\BillingProxySession;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\Routing\ProviderRoutingDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Throwable;

class BillingGatewayService
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly PesapalCompatibilityAdapter $pesapalCompatibilityAdapter,
        private readonly KopoKopoCompatibilityAdapter $kopokopoCompatibilityAdapter,
        private readonly PawaPayCompatibilityAdapter $pawaPayCompatibilityAdapter,
        private readonly ProviderStatusQueryOrchestrator $providerStatusQueryOrchestrator,
        private readonly PaymentCompletionService $paymentCompletionService,
        private readonly WalletService $walletService,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly KopokopoConfigService $kopokopoConfigService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder,
        private readonly BillingProviderTransactionRecorder $billingProviderTransactionRecorder,
        private readonly CanonicalPaymentStateReducer $canonicalPaymentStateReducer
    ) {
    }

    public function initiateTopup(
        Client $client,
        string $provider,
        float $amount,
        array $options = [],
        ?Request $request = null
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Top-up amount must be greater than zero.');
        }

        $client->loadMissing('platform');
        $platform = $client->platform ?: Platform::query()->findOrFail((int) $client->platform_id);
        $context = $this->billingModeService->providerContext($platform, $provider);
        $providerConfig = $context['provider_config'];
        $environment = $context['environment'];

        $this->assertAmountWithinBounds($client, $amount, $context);

        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Top-up idempotency key is required.');
        }

        $referenceNumber = $this->topupReference((int) $platform->id, (int) $client->id, (string) $provider, $idempotencyKey);
        $existing = Payment::query()
            ->where('purpose', 'wallet_topup')
            ->where('reference_number', $referenceNumber)
            ->first();

        if ($existing) {
            return [
                'payment' => $existing->fresh(['platform', 'client']),
                'provider' => $provider,
                'replayed' => true,
                'action' => $this->resumeActionForPayment($existing),
            ];
        }

        $autoSubscribe = $this->normalizeAutoSubscribe($platform, $context['wallet'], $options['auto_subscribe'] ?? null);
        $payment = Payment::query()->create([
            'user_id' => $client->wp_user_id,
            'escort_post_id' => $client->wp_post_id,
            'platform_id' => (int) $platform->id,
            'client_id' => (int) $client->id,
            'phone' => $options['phone'] ?? $client->phone_normalized,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper((string) ($context['wallet']['currency_code'] ?? $platform->currency_code ?: 'KES')),
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => $referenceNumber,
            'reference_number' => $referenceNumber,
            'status' => 'initiated',
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => $provider,
            'provider_environment' => $environment,
            'raw_payload' => [
                'method' => $provider,
                'billing_surface' => 'wallet_topup',
            ],
            'payment_data' => [
                'idempotency_key' => $idempotencyKey,
                'requested_amount' => number_format($amount, 2, '.', ''),
                'provider' => $provider,
                'auto_subscribe' => $autoSubscribe,
                'topup_limits' => [
                    'provider_min' => $providerConfig['min_amount'] ?? null,
                    'provider_max' => $providerConfig['max_amount'] ?? null,
                    'market_max_balance' => $context['wallet']['max_wallet_balance'] ?? null,
                ],
            ],
        ]);
        $payment->loadMissing(['client', 'platform']);

        $dispatchContext = array_merge($context, [
            'provider_key' => $provider,
        ]);
        $this->billingRoutingDecisionRecorder->recordWalletFunding($payment, $dispatchContext, array_merge($options, [
            'description' => 'Wallet top-up',
        ]));

        $action = app(ProviderRoutingDispatcher::class)->dispatch($payment, $dispatchContext, array_merge($options, [
            'request' => $request,
            'description' => 'Wallet top-up',
        ]));

        return [
            'payment' => $payment->fresh(['platform', 'client']),
            'provider' => $provider,
            'replayed' => false,
            'action' => $action,
        ];
    }

    /**
     * Initiate STK routing for provider-agnostic executor.
     *
     * Public API for ProviderRoutingExecutor to call, normalizing
     * M-Pesa STK initialization through a consistent interface.
     */
    public function initiateStkForRouting(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        return $this->initiateMpesaStk($payment, $context, $options, $request);
    }

    /**
     * Initiate hosted checkout routing for provider-agnostic executor.
     *
     * Public API for ProviderRoutingExecutor to call while preserving the
     * existing payment-attempt logging, resume state, and payload persistence
     * implemented by the wallet top-up flow.
     */
    public function initiateHostedCheckoutForRouting(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        return match ($context['provider_key'] ?? $payment->provider_key) {
            'paystack' => $this->initiatePaystack($payment, $context, $request),
            'pesapal' => $this->initiatePesapal($payment, $context, $options, $request),
            'pawapay' => $this->initiatePawaPay($payment, $context, $options, $request),
            default => throw new InvalidArgumentException('Unsupported hosted checkout billing provider.'),
        };
    }

    public function retryMpesaTopup(Payment $payment, array $options = [], ?Request $request = null): array
    {
        $payment->loadMissing(['client.platform', 'platform', 'client']);
        if ($payment->purpose !== 'wallet_topup' || !in_array($this->resolvedProviderType($payment), ['mpesa_stk', 'daraja', 'kopokopo'], true)) {
            throw new InvalidArgumentException('Only M-Pesa wallet top-up payments can be retried here.');
        }

        if (!in_array((string) $payment->status, ['initiated', 'pending', 'failed'], true)) {
            throw new InvalidArgumentException('This payment is not eligible for STK retry.');
        }

        if ($payment->created_at && $payment->created_at->lt(now()->subMinutes(30))) {
            throw new InvalidArgumentException('The STK retry window has expired.');
        }

        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $lastRetryAt = data_get($paymentData, 'last_retry_at');
        if ($lastRetryAt && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastRetryAt)) < 60) {
            throw new InvalidArgumentException('Please wait before requesting another STK push.');
        }

        $context = $this->billingModeService->providerContext(
            $payment->platform,
            $this->resolvedProviderType($payment) ?: 'mpesa_stk'
        );
        $action = $this->dispatchMpesaStk($payment, $context, [
            'phone' => $options['phone'] ?? $payment->phone,
            'retry' => true,
        ], $request);
        $storedAction = $action;
        unset($storedAction['provider_payload']);

        $payment->forceFill([
            'status' => 'pending',
            'failure_reason' => null,
            'payment_data' => array_merge($paymentData, [
                'last_retry_at' => now()->toIso8601String(),
                'retry_count' => ((int) data_get($paymentData, 'retry_count', 0)) + 1,
                'resume' => $storedAction,
            ]),
        ])->save();

        $retryOf = $this->billingProviderTransactionRecorder->latestAttempt(
            $payment,
            $context['provider_key'] ?? $this->resolvedProviderType($payment)
        );
        $this->billingProviderTransactionRecorder->recordInitiation($payment, $context, $action, [
            'reason_code' => 'manual_retry',
            'retry_of_provider_transaction_id' => $retryOf?->id,
        ]);

        return [
            'payment' => $payment->fresh(['platform', 'client']),
            'action' => $action,
        ];
    }

    public function completeTopupPayment(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        return $this->paymentCompletionService->completeTopupPayment($payment, $providerPayload, $options);
    }

    public function failPayment(Payment $payment, string $reason, array $providerPayload = []): Payment
    {
        if ($payment->wallet_transaction_id || (string) $payment->status === 'completed' || $payment->completed_at) {
            return $payment->fresh() ?? $payment;
        }

        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        if (
            !empty($paymentData['test_mode'])
            || (
                strtolower(trim((string) $payment->source)) === 'gateway'
                && strtolower(trim((string) $payment->provider_environment)) === 'sandbox'
            )
        ) {
            $paymentData = array_merge($paymentData, [
                'test_mode' => true,
                'test_result' => 'failed',
                'side_effects_skipped' => true,
                'verified_at' => (string) ($paymentData['verified_at'] ?? now()->toIso8601String()),
            ]);
        }

        $state = $this->canonicalPaymentStateReducer->fail($payment, $reason, [
            'payment_data' => $paymentData,
            'transition' => !empty($paymentData['test_mode']) ? 'sandbox_provider_failed' : null,
            'sandbox_suppressed' => !empty($paymentData['test_mode']),
        ]);

        $payment->forceFill(array_merge($state, [
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'failure_payload' => $providerPayload,
            ]),
        ]))->save();

        return $payment->fresh();
    }

    public function handlePaystackWebhook(string $rawBody, array $payload, string $signature): array
    {
        $reference = (string) data_get($payload, 'data.reference', '');
        if ($reference === '') {
            throw new InvalidArgumentException('Paystack webhook payload is missing the transaction reference.');
        }

        $payment = $this->resolveHostedCheckoutPaymentByReference('paystack', $reference);

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext(
            $payment->platform,
            'paystack',
            requireEnabled: false,
            environmentOverride: $this->resolvedEnvironment($payment)
        );
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $expected = hash_hmac('sha512', $rawBody, $secretKey);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Paystack signature.');
        }

        $verification = $this->providerStatusQueryOrchestrator->verify($payment, [
            'reference' => $reference,
        ]);
        $decision = $this->providerStatusQueryOrchestrator->decideMutation($payment, $verification, [
            'reference' => $reference,
        ]);
        $verifiedData = is_array($verification['data'] ?? null) ? $verification['data'] : [];
        if (($decision['decision'] ?? null) === 'apply_failed') {
            $failed = $this->failPayment(
                $payment,
                (string) ($decision['message'] ?? $verification['message'] ?? 'Paystack transaction did not complete successfully.'),
                $verifiedData
            );
            $this->recordBillingCallbackAttempt($failed, 'paystack_webhook', 'failed', [
                'error_message' => (string) ($decision['message'] ?? $verification['message'] ?? 'Paystack transaction did not complete successfully.'),
                'response_meta' => [
                    'reference' => $reference,
                    'gateway_response' => $verifiedData['gateway_response'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                    'decision' => $decision,
                ],
            ]);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        if (($decision['decision'] ?? null) === 'apply_completed') {
            $completed = $this->completeVerifiedPayment($payment, $verifiedData, [
                'transaction_reference' => $verifiedData['reference'] ?? $reference,
            ]);
            $this->recordBillingCallbackAttempt($completed['payment'], 'paystack_webhook', 'success', [
                'response_meta' => [
                    'reference' => $verifiedData['reference'] ?? $reference,
                    'gateway_response' => $verifiedData['gateway_response'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                    'decision' => $decision,
                ],
            ]);

            return array_merge($completed, [
                'status' => 'completed',
            ]);
        }

        $freshPayment = $payment->fresh() ?? $payment;
        $this->recordBillingCallbackAttempt($freshPayment, 'paystack_webhook', 'success', [
            'response_meta' => [
                'reference' => $verifiedData['reference'] ?? $reference,
                'gateway_response' => $verifiedData['gateway_response'] ?? null,
                'verification_status' => $verification['status'] ?? null,
                'decision' => $decision,
            ],
        ]);

        return [
            'payment' => $freshPayment,
            'status' => (string) ($decision['winning_status'] ?? $payment->status),
        ];
    }

    public function handlePesapalIpn(array $payload): array
    {
        $merchantReference = (string) ($payload['OrderMerchantReference']
            ?? $payload['order_merchant_reference']
            ?? $payload['merchant_reference']
            ?? '');
        if ($merchantReference === '') {
            throw new InvalidArgumentException('Pesapal IPN payload is missing the merchant reference.');
        }

        $payment = $this->resolveHostedCheckoutPaymentByReference('pesapal', $merchantReference);

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext(
            $payment->platform,
            'pesapal',
            requireEnabled: false,
            environmentOverride: $this->resolvedEnvironment($payment)
        );
        $trackingId = (string) ($payload['OrderTrackingId'] ?? $payload['order_tracking_id'] ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id', ''));
        if ($trackingId === '') {
            throw new InvalidArgumentException('Pesapal IPN payload is missing the tracking ID.');
        }

        $verification = $this->providerStatusQueryOrchestrator->verify($payment, [
            'tracking_id' => $trackingId,
            'provider_reference' => $trackingId,
        ]);
        $decision = $this->providerStatusQueryOrchestrator->decideMutation($payment, $verification, [
            'tracking_id' => $trackingId,
            'provider_reference' => $trackingId,
        ]);
        $verified = is_array($verification['data'] ?? null) ? $verification['data'] : [];

        if (($decision['decision'] ?? null) === 'apply_failed') {
            $failed = $this->failPayment(
                $payment,
                (string) ($decision['message'] ?? $verification['message'] ?? 'Pesapal transaction did not complete successfully.'),
                $verified
            );
            $this->recordBillingCallbackAttempt($failed, 'pesapal_ipn', 'failed', [
                'error_message' => (string) ($decision['message'] ?? $verification['message'] ?? 'Pesapal transaction did not complete successfully.'),
                'response_meta' => [
                    'merchant_reference' => $merchantReference,
                    'tracking_id' => $trackingId,
                    'verification_status' => $verification['status'] ?? null,
                    'decision' => $decision,
                ],
            ]);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        if (($decision['decision'] ?? null) === 'apply_completed') {
            $completed = $this->completeVerifiedPayment($payment, $verified, [
                'transaction_reference' => $trackingId,
            ]);
            $this->recordBillingCallbackAttempt($completed['payment'], 'pesapal_ipn', 'success', [
                'response_meta' => [
                    'merchant_reference' => $merchantReference,
                    'tracking_id' => $trackingId,
                    'verification_status' => $verification['status'] ?? null,
                    'decision' => $decision,
                ],
            ]);

            return array_merge($completed, [
                'status' => 'completed',
            ]);
        }

        $freshPayment = $payment->fresh() ?? $payment;
        $this->recordBillingCallbackAttempt($freshPayment, 'pesapal_ipn', 'success', [
            'response_meta' => [
                'merchant_reference' => $merchantReference,
                'tracking_id' => $trackingId,
                'verification_status' => $verification['status'] ?? null,
                'decision' => $decision,
            ],
        ]);

        return [
            'payment' => $freshPayment,
            'status' => (string) ($decision['winning_status'] ?? $payment->status),
        ];
    }

    public function handleMpesaCallback(string $rawBody, string $signature): array
    {
        $result = $this->kopokopoCompatibilityAdapter->handleWebhook($rawBody, $signature);
        if (($result['status'] ?? null) !== 'success') {
            throw new RuntimeException('Invalid M-Pesa callback signature.');
        }

        $payload = $result['data'] ?? [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $paymentId = (int) ($metadata['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            throw new InvalidArgumentException('M-Pesa callback payload is missing the payment id.');
        }

        $payment = Payment::query()->findOrFail($paymentId);
        if ($payment->purpose !== 'wallet_topup' || !in_array($this->resolvedProviderType($payment), ['mpesa_stk', 'daraja', 'kopokopo'], true)) {
            throw new InvalidArgumentException('M-Pesa callback does not target a wallet top-up payment.');
        }

        $topic = strtolower((string) ($payload['topic'] ?? $payload['eventType'] ?? ''));
        $resourceStatus = strtolower((string) ($payload['resourceStatus'] ?? ''));
        if (str_contains($topic, 'reversed') || $resourceStatus === 'reversed') {
            $failed = $this->failPayment($payment, 'M-Pesa transaction was reversed.', $payload);
            $this->recordBillingCallbackAttempt($failed, 'kopokopo_webhook', 'failed', [
                'error_code' => 'reversed',
                'error_message' => 'M-Pesa transaction was reversed.',
                'response_meta' => [
                    'topic' => $payload['topic'] ?? $payload['eventType'] ?? null,
                    'resourceStatus' => $payload['resourceStatus'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                    'metadata_payment_id' => $metadata['payment_id'] ?? null,
                ],
            ]);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeTopupPayment($payment, $payload, [
            'transaction_reference' => $payload['reference'] ?? $payment->transaction_reference,
        ]);
        $this->recordBillingCallbackAttempt($completed['payment'], 'kopokopo_webhook', 'success', [
            'response_meta' => [
                'topic' => $payload['topic'] ?? $payload['eventType'] ?? null,
                'resourceStatus' => $payload['resourceStatus'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'metadata_payment_id' => $metadata['payment_id'] ?? null,
            ],
        ]);

        return array_merge($completed, [
            'status' => 'completed',
        ]);
    }

    public function handlePawaPayCallback(string $rawBody, array $payload, array $headers = [], array $requestContext = []): array
    {
        $depositId = trim((string) ($payload['depositId'] ?? ''));
        if ($depositId === '') {
            throw new InvalidArgumentException('pawaPay callback payload is missing the deposit id.');
        }

        $callbackStatus = strtoupper(trim((string) ($payload['status'] ?? 'UNKNOWN')));
        $payment = $this->resolveHostedCheckoutPaymentByProviderReference('pawapay', $depositId);
        $payment->loadMissing(['client.platform', 'platform', 'routingDecisions', 'providerTransactions']);

        $decision = $this->latestPinnedDecision($payment);
        $profile = $this->resolvePawaPayProfile($payment, $decision);
        $providerTransaction = $payment->providerTransactions
            ->where('provider_type_key', 'pawapay')
            ->first(function ($transaction) use ($depositId) {
                return $transaction->provider_transaction_id === $depositId
                    || $transaction->compatibility_reference === $depositId;
            });

        $dedupeKey = hash('sha256', implode('|', ['pawapay', $depositId, $callbackStatus]));
        $event = BillingWebhookEvent::query()
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($event && $event->processing_status === 'processed') {
            $freshPayment = $payment->fresh() ?? $payment;

            return [
                'payment' => $freshPayment,
                'status' => (string) ($freshPayment->status ?? $payment->status),
                'duplicate' => true,
            ];
        }

        $security = $this->pawaPayCallbackSecurityAssessment($rawBody, $headers, $requestContext, $profile);

        if (!$event) {
            $event = BillingWebhookEvent::query()->create([
                'provider_type_key' => 'pawapay',
                'provider_profile_id' => $decision?->provider_profile_id,
                'market_id' => (int) $payment->platform_id,
                'provider_event_id' => $depositId,
                'dedupe_key' => $dedupeKey,
                'headers_json' => $headers,
                'raw_body' => $rawBody,
                'payload_json' => $payload,
                'signature_status' => $security['signature_status'],
                'verification_meta_json' => $security['meta'],
                'processing_status' => 'pending',
                'retry_count' => 0,
                'last_error' => null,
                'billing_provider_transaction_id' => $providerTransaction?->id,
                'payment_id' => (int) $payment->id,
                'received_at' => now(),
            ]);
        } else {
            $event->forceFill([
                'provider_profile_id' => $decision?->provider_profile_id,
                'market_id' => (int) $payment->platform_id,
                'provider_event_id' => $depositId,
                'headers_json' => $headers,
                'raw_body' => $rawBody,
                'payload_json' => $payload,
                'signature_status' => $security['signature_status'],
                'verification_meta_json' => $security['meta'],
                'processing_status' => 'pending',
                'retry_count' => ((int) $event->retry_count) + 1,
                'last_error' => null,
                'billing_provider_transaction_id' => $providerTransaction?->id,
                'payment_id' => (int) $payment->id,
                'processed_at' => null,
            ])->save();
        }

        BillingProxySession::query()
            ->where('payment_id', $payment->id)
            ->where('provider_reference', $depositId)
            ->whereNull('callback_at')
            ->update(['callback_at' => now()]);

        try {
            $verification = $this->providerStatusQueryOrchestrator->verify($payment, [
                'deposit_id' => $depositId,
                'provider_reference' => $depositId,
            ]);

            // PawaPay sometimes sends the callback before their verify API reflects COMPLETED.
            // If the callback explicitly says COMPLETED but verify still returns pending,
            // wait briefly and retry once to allow propagation.
            if ($callbackStatus === 'COMPLETED' && ($verification['status'] ?? 'pending') === 'pending') {
                sleep(3);
                $verification = $this->providerStatusQueryOrchestrator->verify($payment, [
                    'deposit_id' => $depositId,
                    'provider_reference' => $depositId,
                ]);
            }

            $decisionResult = $this->providerStatusQueryOrchestrator->decideMutation($payment, $verification, [
                'deposit_id' => $depositId,
                'provider_reference' => $depositId,
            ]);
            $verifiedData = is_array($verification['data'] ?? null) ? $verification['data'] : [];

            if (($decisionResult['decision'] ?? null) === 'apply_failed') {
                $failed = $this->failPayment(
                    $payment,
                    (string) ($decisionResult['message'] ?? $verification['message'] ?? 'pawaPay transaction did not complete successfully.'),
                    $verifiedData
                );
                $this->recordBillingCallbackAttempt($failed, 'pawapay_callback', 'failed', [
                    'error_message' => (string) ($decisionResult['message'] ?? $verification['message'] ?? 'pawaPay transaction did not complete successfully.'),
                    'response_meta' => [
                        'deposit_id' => $depositId,
                        'callback_status' => $callbackStatus,
                        'provider_status' => $verifiedData['status'] ?? null,
                        'verification_status' => $verification['status'] ?? null,
                        'decision' => $decisionResult,
                    ],
                ]);

                $this->markBillingWebhookEventProcessed($event, $failed, $security['meta'], $verification, $decisionResult);

                return [
                    'payment' => $failed,
                    'status' => 'failed',
                ];
            }

            if (($decisionResult['decision'] ?? null) === 'apply_completed') {
                $completed = $this->completeVerifiedPayment($payment, $verifiedData, [
                    'transaction_reference' => $depositId,
                ]);
                $this->recordBillingCallbackAttempt($completed['payment'], 'pawapay_callback', 'success', [
                    'response_meta' => [
                        'deposit_id' => $depositId,
                        'callback_status' => $callbackStatus,
                        'provider_status' => $verifiedData['status'] ?? null,
                        'provider_transaction_id' => $verifiedData['providerTransactionId'] ?? null,
                        'verification_status' => $verification['status'] ?? null,
                        'decision' => $decisionResult,
                    ],
                ]);

                $this->markBillingWebhookEventProcessed($event, $completed['payment'], $security['meta'], $verification, $decisionResult);

                return array_merge($completed, [
                    'status' => 'completed',
                ]);
            }

            $freshPayment = $payment->fresh() ?? $payment;
            $this->recordBillingCallbackAttempt($freshPayment, 'pawapay_callback', 'success', [
                'response_meta' => [
                    'deposit_id' => $depositId,
                    'callback_status' => $callbackStatus,
                    'provider_status' => $verifiedData['status'] ?? null,
                    'provider_transaction_id' => $verifiedData['providerTransactionId'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                    'decision' => $decisionResult,
                ],
            ]);

            $this->markBillingWebhookEventProcessed($event, $freshPayment, $security['meta'], $verification, $decisionResult);

            return [
                'payment' => $freshPayment,
                'status' => (string) ($decisionResult['winning_status'] ?? $payment->status),
            ];
        } catch (Throwable $exception) {
            $event->forceFill([
                'signature_status' => $security['signature_status'],
                'verification_meta_json' => $security['meta'],
                'processing_status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 65535),
            ])->save();

            throw $exception;
        }
    }

    public function paymentPayload(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'transaction_uuid' => $payment->transaction_uuid,
            'reference_number' => $payment->reference_number,
            'transaction_reference' => $payment->transaction_reference,
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'source' => $payment->source,
            'provider' => $payment->provider_key,
            'provider_environment' => $payment->provider_environment,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => $payment->currency,
            'completed_at' => optional($payment->completed_at)->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
        ];
    }

    private function initiatePaystack(Payment $payment, array $context, ?Request $request = null): array
    {
        $requestMeta = $this->hostedCheckoutRequestMeta($payment, $request);
        $attemptStartedAt = microtime(true);

        try {
            $action = $this->hostedCheckoutService->initializePaystack($payment, $context);
        } catch (RuntimeException $exception) {
            $this->failPayment($payment, $exception->getMessage());
            $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'failed', [
                'provider' => 'paystack',
                'error_code' => 'hosted_checkout_init_failed',
                'error_message' => $exception->getMessage(),
                'http_status' => 422,
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'response_meta' => [
                    'billing_surface' => 'wallet_topup',
                ],
            ]);
            throw $exception;
        }

        $storedAction = $action;
        unset($storedAction['provider_payload']);

        $payment->forceFill([
            'status' => 'pending',
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'paystack' => $action['provider_payload'] ?? null,
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $storedAction,
            ]),
        ])->save();

        $this->billingProviderTransactionRecorder->recordInitiation($payment, $context, $action, [
            'reason_code' => 'initial_initiation',
        ]);

        $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'success', [
            'provider' => 'paystack',
            'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
            'request_meta' => $requestMeta,
            'response_meta' => [
                'billing_surface' => 'wallet_topup',
                'provider_reference' => $action['provider_reference'] ?? null,
                'checkout_url' => $action['url'] ?? null,
            ],
        ]);

        unset($action['provider_payload']);

        return $action;
    }

    private function initiatePesapal(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        $requestMeta = $this->hostedCheckoutRequestMeta($payment, $request);
        $attemptStartedAt = microtime(true);

        try {
            $action = $this->pesapalCompatibilityAdapter->initialize($payment, $context, $options);
        } catch (RuntimeException $exception) {
            $this->failPayment($payment, $exception->getMessage());
            $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'failed', [
                'provider' => 'pesapal',
                'error_code' => 'hosted_checkout_init_failed',
                'error_message' => $exception->getMessage(),
                'http_status' => 422,
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'response_meta' => [
                    'billing_surface' => 'wallet_topup',
                ],
            ]);
            throw $exception;
        }

        $storedAction = $action;
        unset($storedAction['provider_payload']);

        $payment->forceFill([
            'status' => 'pending',
            'transaction_reference' => $action['provider_reference'] !== '' ? $action['provider_reference'] : $payment->transaction_reference,
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'pesapal' => $action['provider_payload'] ?? null,
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $storedAction,
            ]),
        ])->save();

        $this->billingProviderTransactionRecorder->recordInitiation($payment, $context, $action, [
            'reason_code' => 'initial_initiation',
        ]);

        $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'success', [
            'provider' => 'pesapal',
            'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
            'request_meta' => $requestMeta,
            'response_meta' => [
                'billing_surface' => 'wallet_topup',
                'provider_reference' => $action['provider_reference'] ?? null,
                'checkout_url' => $action['url'] ?? null,
            ],
        ]);

        unset($action['provider_payload']);

        return $action;
    }

    private function initiatePawaPay(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        $requestMeta = $this->hostedCheckoutRequestMeta($payment, $request);
        $attemptStartedAt = microtime(true);

        try {
            $action = $this->pawaPayCompatibilityAdapter->initialize($payment, $context, $options);
        } catch (RuntimeException $exception) {
            $this->failPayment($payment, $exception->getMessage());
            $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'failed', [
                'provider' => 'pawapay',
                'error_code' => 'hosted_checkout_init_failed',
                'error_message' => $exception->getMessage(),
                'http_status' => 422,
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'response_meta' => [
                    'billing_surface' => 'wallet_topup',
                ],
            ]);
            throw $exception;
        }

        $storedAction = $action;
        unset($storedAction['provider_payload']);

        $payment->forceFill([
            'status' => 'pending',
            'transaction_reference' => $action['provider_reference'] !== '' ? $action['provider_reference'] : $payment->transaction_reference,
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'pawapay' => $action['provider_payload'] ?? null,
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $storedAction,
            ]),
        ])->save();

        $this->billingProviderTransactionRecorder->recordInitiation($payment, $context, $action, [
            'reason_code' => 'initial_initiation',
        ]);

        $this->paymentAttemptService->record($payment, 'hosted_checkout_init', 'success', [
            'provider' => 'pawapay',
            'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
            'request_meta' => $requestMeta,
            'response_meta' => [
                'billing_surface' => 'wallet_topup',
                'provider_reference' => $action['provider_reference'] ?? null,
                'checkout_url' => $action['url'] ?? null,
            ],
        ]);

        unset($action['provider_payload']);

        return $action;
    }

    private function hostedCheckoutRequestMeta(Payment $payment, ?Request $request = null): array
    {
        $extra = [
            'channel' => 'hosted_checkout',
            'billing_surface' => 'wallet_topup',
            'requested_provider' => (string) $payment->provider_key,
            'platform_id' => (int) $payment->platform_id,
            'client_id' => (int) $payment->client_id,
            'payment_purpose' => (string) $payment->purpose,
        ];

        return $request instanceof Request
            ? $this->paymentAttemptService->requestMetaFromRequest($request, $extra)
            : $extra;
    }

    private function initiateMpesaStk(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        $action = $this->dispatchMpesaStk($payment, $context, $options, $request);
        $storedAction = $action;
        unset($storedAction['provider_payload']);

        $payment->forceFill([
            'status' => 'pending',
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'mpesa_stk' => $action['provider_payload'] ?? null,
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $storedAction,
                'last_retry_at' => now()->toIso8601String(),
                'retry_count' => 0,
            ]),
        ])->save();

        $this->billingProviderTransactionRecorder->recordInitiation($payment, $context, $action, [
            'reason_code' => 'initial_initiation',
        ]);

        unset($action['provider_payload']);

        return $action;
    }

    private function dispatchMpesaStk(Payment $payment, array $context, array $options = [], ?Request $request = null): array
    {
        $credentials = $context['provider_credentials'];
        $transport = (string) ($credentials['transport'] ?? 'django_proxy');
        $phone = trim((string) ($options['phone'] ?? $payment->phone ?? ''));
        $requestMeta = $this->requestMetaFromRequest($request, [
            'channel' => 'wallet_topup_stk',
            'phone' => $phone,
            'amount' => (float) $payment->amount,
            'purpose' => $payment->purpose,
        ]);
        $provider = 'kopokopo_direct';
        $providerEnvironment = $payment->provider_environment ?: ($context['environment'] ?? null);
        $kopokopoConfig = is_array($context['provider_direct_config'] ?? null)
            ? $context['provider_direct_config']
            : $this->kopokopoConfigService->currentConfig(masked: false);
        $upstreamUrl = trim((string) ($kopokopoConfig['base_url'] ?? ''));
        $attemptStartedAt = microtime(true);
        $providerResponse = null;
        $paymentAlreadyFailed = false;

        try {
            if ($phone === '') {
                throw new InvalidArgumentException('A valid phone number is required for M-Pesa STK push.');
            }

            if ($transport !== 'direct_provider') {
                throw new InvalidArgumentException('M-Pesa wallet top-up requires the direct_provider transport for this CRM contract.');
            }

            $result = $this->kopokopoCompatibilityAdapter->initiateStkPush(
                $phone,
                (float) $payment->amount,
                $this->billingModeService->buildAbsoluteUrl($payment->platform, '/api/billing/mpesa/callback'),
                [
                    'payment_id' => (int) $payment->id,
                    'platform_id' => (int) $payment->platform_id,
                    'client_id' => (int) $payment->client_id,
                    'purpose' => $payment->purpose,
                    'reference_number' => $payment->reference_number,
                ],
                $kopokopoConfig
            );
            $providerResponse = is_array($result) ? $result : null;

            if (($result['status'] ?? null) !== 'success') {
                $message = (string) ($result['error'] ?? $result['data'] ?? 'M-Pesa STK push failed.');
                $this->failPayment($payment, $message, $providerResponse ?? []);
                $paymentAlreadyFailed = true;

                throw new RuntimeException($message);
            }

            $providerReference = (string) ($result['location'] ?? '');
            if ($providerReference !== '') {
                $payment->forceFill([
                    'transaction_reference' => $providerReference,
                ])->save();
            }

            $this->paymentAttemptService->record($payment, 'stk_initiate', 'success', [
                'provider' => $provider,
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'response_meta' => array_filter([
                    'transport' => $transport,
                    'upstream_url' => $upstreamUrl !== '' ? $upstreamUrl : null,
                    'provider_environment' => $providerEnvironment,
                    'provider_reference' => $providerReference !== '' ? $providerReference : null,
                    'provider_response' => $providerResponse,
                ], static fn ($value) => $value !== null && $value !== ''),
                'created_by' => $this->actorIdFromRequest($request),
            ]);

            return [
                'type' => 'stk_pending',
                'message' => 'STK push sent. Complete the prompt on your phone.',
                'retry_available' => true,
                'poll_after_seconds' => (int) data_get($context, 'system.topup_poll_interval_seconds', 10),
                'provider_reference' => $providerReference,
                'provider_payload' => $result,
            ];
        } catch (Throwable $exception) {
            $this->paymentAttemptService->record($payment, 'stk_initiate', 'failed', [
                'provider' => $provider,
                'error_code' => $exception instanceof InvalidArgumentException ? 'preflight_exception' : 'initiation_failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'response_meta' => array_filter([
                    'transport' => $transport,
                    'upstream_url' => $upstreamUrl !== '' ? $upstreamUrl : null,
                    'provider_environment' => $providerEnvironment,
                    'provider_response' => $providerResponse,
                ], static fn ($value) => $value !== null && $value !== ''),
                'created_by' => $this->actorIdFromRequest($request),
            ]);

            if (!$paymentAlreadyFailed) {
                $this->failPayment($payment, $exception->getMessage(), $providerResponse ?? []);
            }

            throw $exception;
        }
    }

    private function normalizeAutoSubscribe(Platform $platform, array $walletConfig, mixed $payload): ?array
    {
        if (!is_array($payload) || empty($payload['enabled'])) {
            return null;
        }

        $allowCombined = (bool) data_get($walletConfig, 'allow_combined_topup_subscribe', false);
        if (!$allowCombined) {
            throw new InvalidArgumentException('Combined top-up and subscribe is disabled for this market.');
        }

        $productId = (int) ($payload['product_id'] ?? 0);
        $duration = trim((string) ($payload['duration'] ?? ''));
        if ($productId <= 0 || $duration === '') {
            throw new InvalidArgumentException('Auto-subscribe requires both product_id and duration.');
        }

        $product = \App\Models\Product::query()
            ->where('platform_id', (int) $platform->id)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->findOrFail($productId);

        $this->walletCheckoutService->resolveSubscriptionPricing($product, $duration);

        return [
            'enabled' => true,
            'product_id' => $productId,
            'duration' => $duration,
        ];
    }

    private function assertAmountWithinBounds(Client $client, float $amount, array $context): void
    {
        $providerConfig = $context['provider_config'];
        $wallet = $context['wallet'];
        $minAmount = round((float) ($providerConfig['min_amount'] ?? 0), 2);
        $maxAmount = round((float) ($providerConfig['max_amount'] ?? 0), 2);
        $maxWalletBalance = round((float) ($wallet['max_wallet_balance'] ?? 0), 2);
        $currentBalance = round((float) ($client->wallet_balance ?? 0), 2);

        if ($minAmount > 0 && $amount < $minAmount) {
            throw new InvalidArgumentException('Top-up amount is below the provider minimum.');
        }

        if ($maxAmount > 0 && $amount > $maxAmount) {
            throw new InvalidArgumentException('Top-up amount exceeds the provider maximum.');
        }

        if ($maxWalletBalance > 0 && ($currentBalance + $amount) > $maxWalletBalance) {
            throw new InvalidArgumentException('Top-up would exceed the wallet balance limit for this market.');
        }
    }

    private function resumeActionForPayment(Payment $payment): array
    {
        $resume = is_array($payment->payment_data) ? ($payment->payment_data['resume'] ?? null) : null;
        if (is_array($resume)) {
            return $resume;
        }

        if (in_array($this->resolvedProviderType($payment), ['mpesa_stk', 'daraja', 'kopokopo'], true)) {
            return [
                'type' => 'stk_pending',
                'message' => 'Payment is still awaiting completion on the phone prompt.',
                'retry_available' => in_array((string) $payment->status, ['initiated', 'pending', 'failed'], true),
                'poll_after_seconds' => 10,
            ];
        }

        return [
            'type' => 'redirect',
            'url' => null,
            'provider_reference' => $payment->transaction_reference,
        ];
    }

    private function completeVerifiedPayment(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        return $this->paymentCompletionService->complete($payment, $providerPayload, $options);
    }

    private function resolveHostedCheckoutPaymentByReference(string $providerTypeKey, string $referenceNumber): Payment
    {
        return Payment::query()
            ->where('reference_number', $referenceNumber)
            ->where(function ($query) use ($providerTypeKey) {
                $query->where('provider_key', $providerTypeKey)
                    ->orWhereHas('routingDecisions', function ($decisionQuery) use ($providerTypeKey) {
                        $decisionQuery->where('immutable_until_terminal_state', true)
                            ->where('provider_type_key', $providerTypeKey);
                    });
            })
            ->latest('id')
            ->firstOrFail();
    }

    private function resolveHostedCheckoutPaymentByProviderReference(string $providerTypeKey, string $providerReference): Payment
    {
        return Payment::query()
            ->where(function ($query) use ($providerReference) {
                $query->where('transaction_reference', $providerReference)
                    ->orWhereHas('providerTransactions', function ($transactionQuery) use ($providerReference) {
                        $transactionQuery->where('provider_transaction_id', $providerReference)
                            ->orWhere('compatibility_reference', $providerReference);
                    });
            })
            ->where(function ($query) use ($providerTypeKey) {
                $query->where('provider_key', $providerTypeKey)
                    ->orWhereHas('routingDecisions', function ($decisionQuery) use ($providerTypeKey) {
                        $decisionQuery->where('immutable_until_terminal_state', true)
                            ->where('provider_type_key', $providerTypeKey);
                    });
            })
            ->latest('id')
            ->firstOrFail();
    }

    private function resolvedProviderType(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->provider_type_key
            ?? $payment->provider_key
            ?? ''
        )));
    }

    private function resolvedEnvironment(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->environment
            ?? $payment->provider_environment
            ?? 'production'
        )));
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        if ($payment->relationLoaded('routingDecisions')) {
            return $payment->routingDecisions->first();
        }

        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function requestMetaFromRequest(?Request $request, array $extra = []): ?array
    {
        if (!$request) {
            return null;
        }

        return $this->paymentAttemptService->requestMetaFromRequest($request, $extra);
    }

    private function actorIdFromRequest(?Request $request): ?int
    {
        return $request?->user()?->id ?: null;
    }

    private function recordBillingCallbackAttempt(Payment $payment, string $provider, string $status, array $attributes = []): void
    {
        $this->paymentAttemptService->record($payment, 'callback_update', $status, [
            'provider' => $provider,
            'error_code' => $attributes['error_code'] ?? null,
            'error_message' => $attributes['error_message'] ?? null,
            'response_meta' => is_array($attributes['response_meta'] ?? null) ? $attributes['response_meta'] : null,
        ]);
    }

    private function markBillingWebhookEventProcessed(
        BillingWebhookEvent $event,
        Payment $payment,
        array $securityMeta,
        array $verification,
        array $decision
    ): void {
        $providerTransaction = $this->billingProviderTransactionRecorder->latestAttempt($payment, 'pawapay');

        $event->forceFill([
            'billing_provider_transaction_id' => $providerTransaction?->id,
            'payment_id' => (int) $payment->id,
            'verification_meta_json' => array_merge($securityMeta, [
                'verification' => [
                    'status' => $verification['status'] ?? null,
                    'provider_reference' => $verification['provider_reference'] ?? null,
                    'checked_at' => $verification['checked_at'] ?? null,
                ],
                'decision' => $decision,
            ]),
            'processing_status' => 'processed',
            'processed_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function pawaPayCallbackSecurityAssessment(
        string $rawBody,
        array $headers,
        array $requestContext = [],
        ?BillingProviderProfile $profile = null
    ): array
    {
        $signature = $this->firstHeaderValue($headers, 'Signature');
        $signatureInput = $this->firstHeaderValue($headers, 'Signature-Input');
        $signatureDate = $this->firstHeaderValue($headers, 'Signature-Date');
        $contentDigest = $this->firstHeaderValue($headers, 'Content-Digest');
        $signed = $signature !== '' || $signatureInput !== '';

        $meta = [
            'signed_callback' => $signed,
            'signature_present' => $signature !== '',
            'signature_input_present' => $signatureInput !== '',
            'signature_date' => $signatureDate !== '' ? $signatureDate : null,
            'content_digest_present' => $contentDigest !== '',
        ];

        if ($signed && ($signature === '' || $signatureInput === '')) {
            throw new RuntimeException('pawaPay callback signature headers are incomplete.');
        }

        if ($contentDigest === '') {
            if ($signed) {
                throw new RuntimeException('pawaPay callback content digest is missing.');
            }

            return [
                'signature_status' => 'unsigned',
                'meta' => array_merge($meta, [
                    'content_digest_status' => 'missing',
                ]),
            ];
        }

        if (!preg_match('/^\s*(sha-256|sha-512)=:([^:]+):\s*$/i', $contentDigest, $matches)) {
            throw new RuntimeException('pawaPay callback content digest format is not supported.');
        }

        $algorithm = strtolower($matches[1]);
        $expectedDigest = trim((string) $matches[2]);
        $computedDigest = base64_encode(hash(str_replace('-', '', $algorithm), $rawBody, true));

        if (!hash_equals($expectedDigest, $computedDigest)) {
            throw new RuntimeException('pawaPay callback content digest mismatch.');
        }

        $baseMeta = array_merge($meta, [
            'content_digest_status' => 'valid',
            'content_digest_algorithm' => $algorithm,
        ]);

        if (!$signed) {
            return [
                'signature_status' => 'unsigned',
                'meta' => $baseMeta,
            ];
        }

        $parsedSignature = $this->parsePawaPaySignatureHeader($signature);
        $parsedInput = $this->parsePawaPaySignatureInput($signatureInput);

        if ($parsedSignature['label'] !== $parsedInput['label']) {
            throw new RuntimeException('pawaPay callback signature headers do not reference the same signature label.');
        }

        $signatureBase = $this->buildPawaPaySignatureBase($parsedInput, $headers, $requestContext);
        $publicKey = $this->resolvePawaPayCallbackPublicKey($profile, (string) ($parsedInput['params']['keyid'] ?? ''));

        if (!$this->verifyPawaPayCallbackSignature(
            $signatureBase,
            $parsedSignature['signature'],
            $publicKey['key'],
            (string) ($parsedInput['params']['alg'] ?? '')
        )) {
            throw new RuntimeException('pawaPay callback signature is invalid.');
        }

        return [
            'signature_status' => 'verified',
            'meta' => array_merge($baseMeta, [
                'signature_label' => $parsedInput['label'],
                'signature_algorithm' => $parsedInput['params']['alg'] ?? null,
                'signature_key_id' => $parsedInput['params']['keyid'] ?? null,
                'signature_components' => $parsedInput['components'],
                'signature_base' => $signatureBase,
                'public_key_id' => $publicKey['id'],
            ]),
        ];
    }

    private function resolvePawaPayProfile(Payment $payment, ?BillingRoutingDecision $decision): ?BillingProviderProfile
    {
        $profileId = $decision?->provider_profile_id;
        if ($profileId) {
            return BillingProviderProfile::query()->find($profileId);
        }

        return BillingProviderProfile::query()
            ->where('provider_type_key', 'pawapay')
            ->where('market_id', (int) $payment->platform_id)
            ->where('active', true)
            ->first();
    }

    private function parsePawaPaySignatureHeader(string $signature): array
    {
        if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*=\s*:([^:]+):\s*$/', $signature, $matches)) {
            throw new RuntimeException('pawaPay callback signature header format is not supported.');
        }

        $decoded = base64_decode(trim((string) $matches[2]), true);
        if ($decoded === false) {
            throw new RuntimeException('pawaPay callback signature is not valid base64.');
        }

        return [
            'label' => trim((string) $matches[1]),
            'signature' => $decoded,
        ];
    }

    private function parsePawaPaySignatureInput(string $signatureInput): array
    {
        if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*=\s*(\([^)]*\))(.*)$/', $signatureInput, $matches)) {
            throw new RuntimeException('pawaPay callback signature input format is not supported.');
        }

        preg_match_all('/"([^"]+)"/', $matches[2], $components);
        $componentList = array_values(array_filter($components[1] ?? [], fn ($component) => trim((string) $component) !== ''));
        if ($componentList === []) {
            throw new RuntimeException('pawaPay callback signature input does not list any signed components.');
        }

        $params = [];
        if (preg_match_all('/;\s*([A-Za-z0-9_-]+)=("([^"]*)"|([0-9]+))/', $matches[3], $paramMatches, PREG_SET_ORDER)) {
            foreach ($paramMatches as $paramMatch) {
                $params[strtolower(trim((string) $paramMatch[1]))] = $paramMatch[3] !== ''
                    ? $paramMatch[3]
                    : $paramMatch[4];
            }
        }

        return [
            'label' => trim((string) $matches[1]),
            'components' => $componentList,
            'params' => $params,
            'signature_params' => trim((string) ($matches[2] . $matches[3])),
        ];
    }

    private function buildPawaPaySignatureBase(array $signatureInput, array $headers, array $requestContext): string
    {
        $lines = [];

        foreach ($signatureInput['components'] as $component) {
            $lowerComponent = strtolower(trim((string) $component));

            if (str_starts_with($lowerComponent, '@')) {
                $value = match ($lowerComponent) {
                    '@method' => strtoupper(trim((string) ($requestContext['method'] ?? ''))),
                    '@authority' => trim((string) ($requestContext['authority'] ?? $this->firstHeaderValue($headers, 'Host'))),
                    '@path' => (string) ($requestContext['path'] ?? ''),
                    default => '',
                };

                if ($value === '') {
                    throw new RuntimeException(sprintf('pawaPay callback signature requires unsupported or missing derived component [%s].', $component));
                }

                $lines[] = sprintf('"%s": %s', $lowerComponent, $value);

                continue;
            }

            $headerValue = $this->firstHeaderValue($headers, $component);
            if ($headerValue === '') {
                throw new RuntimeException(sprintf('pawaPay callback signature is missing signed header [%s].', $component));
            }

            $lines[] = sprintf('"%s": %s', $lowerComponent, $headerValue);
        }

        $lines[] = sprintf('"@signature-params": %s', (string) $signatureInput['signature_params']);

        return implode("\n", $lines);
    }

    private function resolvePawaPayCallbackPublicKey(?BillingProviderProfile $profile, string $keyId): array
    {
        if ($profile === null) {
            throw new RuntimeException('pawaPay callback verification could not resolve the active provider profile.');
        }

        $keyId = trim($keyId);
        if ($keyId === '') {
            throw new RuntimeException('pawaPay callback signature is missing the key id.');
        }

        $apiKey = trim((string) data_get($profile->secrets_json, 'api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('pawaPay callback verification credentials are incomplete.');
        }

        $baseUrl = trim((string) data_get($profile->config_json, 'base_url', ''));
        if ($baseUrl === '') {
            $baseUrl = strtolower(trim((string) $profile->environment)) === 'production'
                ? 'https://api.pawapay.io'
                : 'https://api.sandbox.pawapay.io';
        }

        $response = Http::timeout(20)
            ->withToken($apiKey)
            ->acceptJson()
            ->get(rtrim($baseUrl, '/') . '/v2/public-key/http');

        if (!$response->successful()) {
            throw new RuntimeException('Could not fetch the pawaPay callback public keys.');
        }

        foreach ((array) $response->json() as $entry) {
            if (trim((string) ($entry['id'] ?? '')) !== $keyId) {
                continue;
            }

            $publicKey = trim((string) ($entry['key'] ?? ''));
            if ($publicKey === '') {
                break;
            }

            return [
                'id' => $keyId,
                'key' => $publicKey,
            ];
        }

        throw new RuntimeException('The pawaPay callback key id is not available from the public keys endpoint.');
    }

    private function verifyPawaPayCallbackSignature(
        string $signatureBase,
        string $signature,
        string $publicKey,
        string $algorithm
    ): bool {
        $algorithm = strtolower(trim($algorithm));
        $loadedKey = PublicKeyLoader::load($publicKey);

        if ($loadedKey instanceof EC) {
            $hash = match ($algorithm) {
                'ecdsa-p256-sha256' => 'sha256',
                'ecdsa-p384-sha384' => 'sha384',
                default => null,
            };

            if ($hash === null) {
                throw new RuntimeException('Unsupported pawaPay callback signature algorithm.');
            }

            return $loadedKey
                ->withHash($hash)
                ->verify($signatureBase, $signature);
        }

        if ($loadedKey instanceof RSA) {
            $key = match ($algorithm) {
                'rsa-v1_5-sha256' => $loadedKey
                    ->withHash('sha256')
                    ->withPadding(RSA::SIGNATURE_PKCS1),
                'rsa-pss-sha512' => $loadedKey
                    ->withHash('sha512')
                    ->withPadding(RSA::SIGNATURE_PSS),
                default => null,
            };

            if ($key === null) {
                throw new RuntimeException('Unsupported pawaPay callback signature algorithm.');
            }

            return $key->verify($signatureBase, $signature);
        }

        throw new RuntimeException('Unsupported pawaPay callback public key type.');
    }

    private function firstHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                return trim((string) ($value[0] ?? ''));
            }

            return trim((string) $value);
        }

        return '';
    }

    private function topupReference(int $platformId, int $clientId, string $provider, string $idempotencyKey): string
    {
        $hash = strtoupper(substr(hash('sha256', implode('|', [$platformId, $clientId, $provider, $idempotencyKey])), 0, 18));

        return 'WTU-' . $hash;
    }
}
