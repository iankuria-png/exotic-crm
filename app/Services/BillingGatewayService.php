<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class BillingGatewayService
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly WalletService $walletService,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly KopokopoService $kopokopoService
    ) {
    }

    public function initiateTopup(
        Client $client,
        string $provider,
        float $amount,
        array $options = []
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

        $action = match ($provider) {
            'paystack' => $this->initiatePaystack($payment, $context),
            'pesapal' => $this->initiatePesapal($payment, $context),
            'mpesa_stk' => $this->initiateMpesaStk($payment, $context, $options),
            default => throw new InvalidArgumentException('Unsupported wallet billing provider.'),
        };

        return [
            'payment' => $payment->fresh(['platform', 'client']),
            'provider' => $provider,
            'replayed' => false,
            'action' => $action,
        ];
    }

    public function retryMpesaTopup(Payment $payment, array $options = []): array
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
        ]);
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
        $payment->loadMissing(['client.platform', 'platform']);
        if ($payment->purpose !== 'wallet_topup') {
            throw new InvalidArgumentException('Only wallet top-up payments can credit a wallet balance.');
        }

        if ($payment->wallet_transaction_id) {
            return [
                'payment' => $payment->fresh(['platform', 'client']),
                'credited' => false,
                'replayed' => true,
                'wallet' => $this->walletService->summary($payment->client, (int) data_get($payment->platform?->wallet_settings, 'recent_transactions_limit', 10)),
            ];
        }

        $transactionReference = $options['transaction_reference']
            ?? data_get($providerPayload, 'reference')
            ?? data_get($providerPayload, 'order_tracking_id')
            ?? $payment->transaction_reference
            ?? $payment->reference_number;

        $payment->forceFill([
            'status' => 'completed',
            'failure_reason' => null,
            'completed_at' => $payment->completed_at ?? now(),
            'transaction_reference' => (string) $transactionReference,
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'completion_payload' => $providerPayload,
            ]),
        ])->save();

        $credit = $this->walletService->credit($payment->client, (float) $payment->amount, [
            'payment' => $payment,
            'reference_type' => 'wallet_topup',
            'reference_id' => (int) $payment->id,
            'idempotency_key' => 'wallet-topup-credit:' . (int) $payment->id,
            'description' => sprintf('Wallet top-up via %s', strtoupper((string) $payment->provider_key)),
            'metadata' => [
                'provider' => $payment->provider_key,
                'provider_environment' => $payment->provider_environment,
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
                $product = \App\Models\Product::query()
                    ->where('platform_id', (int) $payment->platform_id)
                    ->findOrFail($productId);

                $checkout = $this->walletCheckoutService->payForSubscriptionFromWallet(
                    $payment->client,
                    $product,
                    $duration,
                    'wallet-auto-subscribe:' . (int) $payment->id,
                    [
                        'environment' => $payment->provider_environment,
                        'origin' => 'wallet_auto_subscribe',
                        'topup_payment_id' => (int) $payment->id,
                    ]
                );

                $autoSubscribeResult = [
                    'status' => 'completed',
                    'subscription_payment_id' => (int) $checkout['payment']->id,
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
            'wallet' => $this->walletService->summary($credit['client'], (int) data_get($payment->platform?->wallet_settings, 'recent_transactions_limit', 10)),
            'auto_subscribe' => $autoSubscribeResult,
        ];
    }

    public function failTopupPayment(Payment $payment, string $reason, array $providerPayload = []): Payment
    {
        if ($payment->wallet_transaction_id) {
            return $payment;
        }

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 190),
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'failure_payload' => $providerPayload,
            ]),
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
            ->where('purpose', 'wallet_topup')
            ->where('provider_key', 'paystack')
            ->where('reference_number', $reference)
            ->firstOrFail();

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext($payment->platform, 'paystack', requireEnabled: false);
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $expected = hash_hmac('sha512', $rawBody, $secretKey);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Paystack signature.');
        }

        $verification = Http::withToken($secretKey)
            ->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/' . urlencode($reference));

        if (!$verification->successful()) {
            throw new RuntimeException('Could not verify the Paystack transaction.');
        }

        $verifiedData = $verification->json('data') ?? [];
        if (($verifiedData['status'] ?? null) !== 'success') {
            $failed = $this->failTopupPayment(
                $payment,
                (string) ($verifiedData['gateway_response'] ?? 'Paystack transaction did not complete successfully.'),
                $verifiedData
            );

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeTopupPayment($payment, $verifiedData, [
            'transaction_reference' => $verifiedData['reference'] ?? $reference,
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
            ->where('purpose', 'wallet_topup')
            ->where('provider_key', 'pesapal')
            ->where('reference_number', $merchantReference)
            ->firstOrFail();

        $payment->loadMissing(['client.platform', 'platform']);
        $context = $this->billingModeService->providerContext($payment->platform, 'pesapal', requireEnabled: false);
        $trackingId = (string) ($payload['OrderTrackingId'] ?? $payload['order_tracking_id'] ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id', ''));
        if ($trackingId === '') {
            throw new InvalidArgumentException('Pesapal IPN payload is missing the tracking ID.');
        }

        $accessToken = $this->fetchPesapalAccessToken($context);
        $verification = Http::timeout(20)
            ->withToken($accessToken)
            ->get($this->pesapalBaseUrl($payment->provider_environment) . '/Transactions/GetTransactionStatus', [
                'orderTrackingId' => $trackingId,
            ]);

        if (!$verification->successful()) {
            throw new RuntimeException('Could not verify the Pesapal transaction.');
        }

        $verified = $verification->json();
        $statusDescription = strtolower((string) ($verified['payment_status_description'] ?? $verified['status'] ?? ''));
        $statusCode = (int) ($verified['status_code'] ?? 0);

        if ($statusCode !== 1 && !str_contains($statusDescription, 'completed')) {
            $failed = $this->failTopupPayment(
                $payment,
                (string) ($verified['payment_status_description'] ?? 'Pesapal transaction did not complete successfully.'),
                $verified
            );

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeTopupPayment($payment, $verified, [
            'transaction_reference' => $trackingId,
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
            $failed = $this->failTopupPayment($payment, 'M-Pesa transaction was reversed.', $payload);

            return [
                'payment' => $failed,
                'status' => 'failed',
            ];
        }

        $completed = $this->completeTopupPayment($payment, $payload, [
            'transaction_reference' => $payload['reference'] ?? $payment->transaction_reference,
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

    private function initiatePaystack(Payment $payment, array $context): array
    {
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $publicKey = (string) data_get($context, 'provider_credentials.public_key', '');
        $email = $payment->client?->email
            ?: ('wallet+' . (int) $payment->client_id . '@' . ($payment->platform?->domain ?: 'example.test'));

        $response = Http::timeout(20)
            ->withToken($secretKey)
            ->post('https://api.paystack.co/transaction/initialize', [
                'reference' => $payment->reference_number,
                'email' => $email,
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => $payment->currency ?: 'KES',
                'callback_url' => $this->billingModeService->buildAbsoluteUrl(
                    $payment->platform,
                    '/billing/complete',
                    ['payment' => $payment->transaction_uuid]
                ),
                'metadata' => [
                    'payment_id' => (int) $payment->id,
                    'purpose' => $payment->purpose,
                    'platform_id' => (int) $payment->platform_id,
                    'client_id' => (int) $payment->client_id,
                    'auto_subscribe' => data_get($payment->payment_data, 'auto_subscribe'),
                ],
            ]);

        if (!$response->successful() || !($response->json('status') === true)) {
            $message = (string) ($response->json('message') ?? 'Paystack payment initialization failed.');
            $this->failTopupPayment($payment, $message, $response->json() ?? []);
            throw new RuntimeException($message);
        }

        $action = [
            'type' => 'redirect',
            'url' => (string) $response->json('data.authorization_url'),
            'provider_reference' => (string) $response->json('data.reference', $payment->reference_number),
            'access_code' => (string) $response->json('data.access_code', ''),
            'public_key' => $publicKey,
        ];

        $payment->forceFill([
            'status' => 'pending',
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'paystack' => $response->json(),
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $action,
            ]),
        ])->save();

        return $action;
    }

    private function initiatePesapal(Payment $payment, array $context): array
    {
        $accessToken = $this->fetchPesapalAccessToken($context);
        $providerCredentials = $context['provider_credentials'];
        $callbackUrl = $this->billingModeService->buildAbsoluteUrl(
            $payment->platform,
            '/billing/complete',
            ['payment' => $payment->transaction_uuid]
        );

        $response = Http::timeout(20)
            ->withToken($accessToken)
            ->post($this->pesapalBaseUrl($payment->provider_environment) . '/Transactions/SubmitOrderRequest', [
                'id' => $payment->reference_number,
                'currency' => $payment->currency ?: 'KES',
                'amount' => (float) $payment->amount,
                'description' => 'Wallet top-up',
                'callback_url' => $callbackUrl,
                'notification_id' => (string) ($providerCredentials['ipn_id'] ?? ''),
                'billing_address' => [
                    'email_address' => $payment->client?->email ?: 'wallet@example.test',
                    'phone_number' => $payment->phone,
                    'country_code' => strtoupper(substr((string) ($payment->platform?->country ?: 'KE'), 0, 2)),
                    'first_name' => $payment->client?->name ?: 'Wallet',
                    'last_name' => 'Customer',
                ],
            ]);

        if (!$response->successful()) {
            $message = (string) ($response->json('error.message') ?? $response->json('message') ?? 'Pesapal payment initialization failed.');
            $this->failTopupPayment($payment, $message, $response->json() ?? []);
            throw new RuntimeException($message);
        }

        $action = [
            'type' => 'redirect',
            'url' => (string) $response->json('redirect_url'),
            'provider_reference' => (string) $response->json('order_tracking_id', ''),
        ];

        $payment->forceFill([
            'status' => 'pending',
            'transaction_reference' => $action['provider_reference'] !== '' ? $action['provider_reference'] : $payment->transaction_reference,
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'pesapal' => $response->json(),
            ]),
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'resume' => $action,
            ]),
        ])->save();

        return $action;
    }

    private function initiateMpesaStk(Payment $payment, array $context, array $options = []): array
    {
        $action = $this->dispatchMpesaStk($payment, $context, $options);
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

    private function dispatchMpesaStk(Payment $payment, array $context, array $options = []): array
    {
        $credentials = $context['provider_credentials'];
        $transport = (string) ($credentials['transport'] ?? 'django_proxy');
        $phone = trim((string) ($options['phone'] ?? $payment->phone ?? ''));

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

        if (($result['status'] ?? null) !== 'success') {
            $message = (string) ($result['error'] ?? $result['data'] ?? 'M-Pesa STK push failed.');
            $this->failTopupPayment($payment, $message, is_array($result) ? $result : []);
            throw new RuntimeException($message);
        }

        $providerReference = (string) ($result['location'] ?? '');
        if ($providerReference !== '') {
            $payment->forceFill([
                'transaction_reference' => $providerReference,
            ])->save();
        }

        return [
            'type' => 'stk_pending',
            'message' => 'STK push sent. Complete the prompt on your phone.',
            'retry_available' => true,
            'poll_after_seconds' => (int) data_get($context, 'system.topup_poll_interval_seconds', 10),
            'provider_reference' => $providerReference,
            'provider_payload' => $result,
        ];
    }

    private function fetchPesapalAccessToken(array $context): string
    {
        $credentials = $context['provider_credentials'];
        $response = Http::timeout(20)
            ->post($this->pesapalBaseUrl($context['environment']) . '/Auth/RequestToken', [
                'consumer_key' => (string) ($credentials['consumer_key'] ?? ''),
                'consumer_secret' => (string) ($credentials['consumer_secret'] ?? ''),
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Pesapal authentication failed.');
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Pesapal authentication did not return an access token.');
        }

        return $token;
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

    private function pesapalBaseUrl(?string $environment): string
    {
        return $environment === 'production'
            ? 'https://pay.pesapal.com/v3/api'
            : 'https://cybqa.pesapal.com/pesapalv3/api';
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

    private function topupReference(int $platformId, int $clientId, string $provider, string $idempotencyKey): string
    {
        $hash = strtoupper(substr(hash('sha256', implode('|', [$platformId, $clientId, $provider, $idempotencyKey])), 0, 18));

        return 'WTU-' . $hash;
    }
}
