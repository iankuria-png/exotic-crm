<?php

namespace App\Services;

use App\Billing\Support\BillingRoutingDecisionRecorder;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\Routing\ProviderRoutingDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BillingGatewayService
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly PaymentCompletionService $paymentCompletionService,
        private readonly WalletService $walletService,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly KopokopoService $kopokopoService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder
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
            default => throw new InvalidArgumentException('Unsupported hosted checkout billing provider.'),
        };
    }

    public function retryMpesaTopup(Payment $payment, array $options = [], ?Request $request = null): array
    {
        $payment->loadMissing(['client.platform', 'platform', 'client']);
        if ($payment->purpose !== 'wallet_topup' || $payment->provider_key !== 'mpesa_stk') {
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

        $context = $this->billingModeService->providerContext($payment->platform, 'mpesa_stk');
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

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 190),
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'failure_payload' => $providerPayload,
            ]),
            'payment_data' => !empty($paymentData) ? $paymentData : $payment->payment_data,
        ])->save();

        return $payment->fresh();
    }

    public function handlePaystackWebhook(string $rawBody, array $payload, string $signature): array
    {
        $reference = (string) data_get($payload, 'data.reference', '');
        if ($reference === '') {
            throw new InvalidArgumentException('Paystack webhook payload is missing the transaction reference.');
        }

        $payment = Payment::query()
            ->where('provider_key', 'paystack')
            ->where('reference_number', $reference)
            ->firstOrFail();

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext(
            $payment->platform,
            'paystack',
            requireEnabled: false,
            environmentOverride: $payment->provider_environment
        );
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $expected = hash_hmac('sha512', $rawBody, $secretKey);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Paystack signature.');
        }

        $verification = $this->hostedCheckoutService->verifyPaystackTransaction($payment, $context, $reference);
        $verifiedData = is_array($verification['data'] ?? null) ? $verification['data'] : [];
        if (($verification['status'] ?? 'failed') !== 'completed') {
            $failed = $this->failPayment(
                $payment,
                (string) ($verification['message'] ?? 'Paystack transaction did not complete successfully.'),
                $verifiedData
            );
            $this->recordBillingCallbackAttempt($failed, 'paystack_webhook', 'failed', [
                'error_message' => (string) ($verification['message'] ?? 'Paystack transaction did not complete successfully.'),
                'response_meta' => [
                    'reference' => $reference,
                    'gateway_response' => $verifiedData['gateway_response'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                ],
            ]);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeVerifiedPayment($payment, $verifiedData, [
            'transaction_reference' => $verifiedData['reference'] ?? $reference,
        ]);
        $this->recordBillingCallbackAttempt($completed['payment'], 'paystack_webhook', 'success', [
            'response_meta' => [
                'reference' => $verifiedData['reference'] ?? $reference,
                'gateway_response' => $verifiedData['gateway_response'] ?? null,
                'verification_status' => $verification['status'] ?? null,
            ],
        ]);

        return array_merge($completed, [
            'status' => 'completed',
        ]);
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

        $payment = Payment::query()
            ->where('provider_key', 'pesapal')
            ->where('reference_number', $merchantReference)
            ->firstOrFail();

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext(
            $payment->platform,
            'pesapal',
            requireEnabled: false,
            environmentOverride: $payment->provider_environment
        );
        $trackingId = (string) ($payload['OrderTrackingId'] ?? $payload['order_tracking_id'] ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id', ''));
        if ($trackingId === '') {
            throw new InvalidArgumentException('Pesapal IPN payload is missing the tracking ID.');
        }

        $verification = $this->hostedCheckoutService->verifyPesapalTransaction($payment, $context, $trackingId);
        $verified = is_array($verification['data'] ?? null) ? $verification['data'] : [];

        if (($verification['status'] ?? 'failed') !== 'completed') {
            $failed = $this->failPayment(
                $payment,
                (string) ($verification['message'] ?? 'Pesapal transaction did not complete successfully.'),
                $verified
            );
            $this->recordBillingCallbackAttempt($failed, 'pesapal_ipn', 'failed', [
                'error_message' => (string) ($verification['message'] ?? 'Pesapal transaction did not complete successfully.'),
                'response_meta' => [
                    'merchant_reference' => $merchantReference,
                    'tracking_id' => $trackingId,
                    'verification_status' => $verification['status'] ?? null,
                ],
            ]);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeVerifiedPayment($payment, $verified, [
            'transaction_reference' => $trackingId,
        ]);
        $this->recordBillingCallbackAttempt($completed['payment'], 'pesapal_ipn', 'success', [
            'response_meta' => [
                'merchant_reference' => $merchantReference,
                'tracking_id' => $trackingId,
                'verification_status' => $verification['status'] ?? null,
            ],
        ]);

        return array_merge($completed, [
            'status' => 'completed',
        ]);
    }

    public function handleMpesaCallback(string $rawBody, string $signature): array
    {
        $result = $this->kopokopoService->handleWebhook($rawBody, $signature);
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
        if ($payment->purpose !== 'wallet_topup' || $payment->provider_key !== 'mpesa_stk') {
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
            $action = $this->hostedCheckoutService->initializePesapal($payment, $context, $options);
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
        $upstreamUrl = (string) config('services.kopokopo.base_url', '');
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

            $result = $this->kopokopoService->initiateStkPush(
                $phone,
                (float) $payment->amount,
                $this->billingModeService->buildAbsoluteUrl($payment->platform, '/api/billing/mpesa/callback'),
                [
                    'payment_id' => (int) $payment->id,
                    'platform_id' => (int) $payment->platform_id,
                    'client_id' => (int) $payment->client_id,
                    'purpose' => $payment->purpose,
                    'reference_number' => $payment->reference_number,
                ]
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

        if ($payment->provider_key === 'mpesa_stk') {
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

    private function topupReference(int $platformId, int $clientId, string $provider, string $idempotencyKey): string
    {
        $hash = strtoupper(substr(hash('sha256', implode('|', [$platformId, $clientId, $provider, $idempotencyKey])), 0, 18));

        return 'WTU-' . $hash;
    }
}
