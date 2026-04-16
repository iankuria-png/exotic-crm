<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class HostedCheckoutService
{
    public function __construct(
        private readonly BillingModeService $billingModeService
    ) {
    }

    public function initializePaystack(Payment $payment, array $context, array $options = []): array
    {
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $publicKey = (string) data_get($context, 'provider_credentials.public_key', '');
        $email = $this->customerEmail($payment)
            ?: ('wallet+' . (int) $payment->client_id . '@' . ($payment->platform?->domain ?: 'example.test'));
        $callbackUrl = $this->callbackUrl($payment, $context, $options);

        $metadata = array_merge([
            'payment_id' => (int) $payment->id,
            'purpose' => $payment->purpose,
            'platform_id' => (int) $payment->platform_id,
            'client_id' => (int) $payment->client_id,
            'auto_subscribe' => data_get($payment->payment_data, 'auto_subscribe'),
        ], is_array($options['metadata'] ?? null) ? $options['metadata'] : []);

        $response = Http::timeout(20)
            ->withToken($secretKey)
            ->post('https://api.paystack.co/transaction/initialize', [
                'reference' => $payment->reference_number,
                'email' => $email,
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => $payment->currency ?: 'KES',
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ]);

        if (!$response->successful() || $response->json('status') !== true) {
            throw new RuntimeException((string) ($response->json('message') ?? 'Paystack payment initialization failed.'));
        }

        return [
            'type' => 'redirect',
            'url' => (string) $response->json('data.authorization_url'),
            'provider_reference' => (string) $response->json('data.reference', $payment->reference_number),
            'access_code' => (string) $response->json('data.access_code', ''),
            'public_key' => $publicKey,
            'provider_payload' => $response->json(),
        ];
    }

    public function initializePesapal(Payment $payment, array $context, array $options = []): array
    {
        $accessToken = $this->fetchPesapalAccessToken($context);
        $providerCredentials = $context['provider_credentials'];
        $callbackUrl = $this->callbackUrl($payment, $context, $options);
        $description = trim((string) ($options['description'] ?? ''));
        $customerFirstName = trim((string) data_get($payment->payment_data, 'customer.first_name', ''));
        $customerLastName = trim((string) data_get($payment->payment_data, 'customer.last_name', ''));
        $customerPhone = trim((string) data_get($payment->payment_data, 'customer.phone', ''));
        if ($description === '') {
            $description = $payment->purpose === 'wallet_topup' ? 'Wallet top-up' : 'Payment';
        }

        $response = Http::timeout(20)
            ->withToken($accessToken)
            ->post($this->pesapalBaseUrl((string) ($context['environment'] ?? $payment->provider_environment)) . '/Transactions/SubmitOrderRequest', [
                'id' => $payment->reference_number,
                'currency' => $payment->currency ?: 'KES',
                'amount' => (float) $payment->amount,
                'description' => $description,
                'callback_url' => $callbackUrl,
                'notification_id' => (string) ($providerCredentials['ipn_id'] ?? ''),
                'billing_address' => [
                    'email_address' => $this->customerEmail($payment) ?: 'wallet@example.test',
                    'phone_number' => $customerPhone !== '' ? $customerPhone : $payment->phone,
                    'country_code' => strtoupper(substr((string) ($payment->platform?->country ?: 'KE'), 0, 2)),
                    'first_name' => $customerFirstName !== '' ? $customerFirstName : ($payment->client?->name ?: 'Wallet'),
                    'last_name' => $customerLastName !== '' ? $customerLastName : 'Customer',
                ],
            ]);

        if (!$response->successful()) {
            $message = (string) ($response->json('error.message') ?? $response->json('message') ?? 'Pesapal payment initialization failed.');
            throw new RuntimeException($message);
        }

        return [
            'type' => 'redirect',
            'url' => (string) $response->json('redirect_url'),
            'provider_reference' => (string) $response->json('order_tracking_id', ''),
            'provider_payload' => $response->json(),
        ];
    }

    public function initializePawaPay(Payment $payment, array $context, array $options = []): array
    {
        $apiKey = trim((string) data_get($context, 'provider_credentials.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('pawaPay credentials are incomplete for the active environment.');
        }

        $depositId = $this->ensurePawaPayDepositId($payment);

        $reason = trim((string) ($options['description'] ?? 'Wallet top-up'));
        if ($reason === '') {
            $reason = 'Wallet top-up';
        }

        $payload = [
            'depositId' => $depositId,
            'returnUrl' => $this->pawaPayReturnUrl($payment, $context, $options),
            'amountDetails' => [
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => strtoupper((string) ($payment->currency ?: 'KES')),
            ],
            'language' => 'EN',
            'reason' => mb_substr($reason, 0, 50),
            'metadata' => [
                ['paymentId' => (string) $payment->id],
                ['referenceNumber' => (string) $payment->reference_number],
                ['platformId' => (string) $payment->platform_id],
                ['clientId' => (string) $payment->client_id],
            ],
        ];

        $validatedPhoneDetails = is_array($options['validated_phone_details'] ?? null)
            ? $options['validated_phone_details']
            : $this->sanitizePawaPayPhone(
                (string) ($payment->phone ?: data_get($payment->payment_data, 'customer.phone', '')),
                array_merge($context, [
                    'phone_prefix' => (string) ($payment->platform?->phone_prefix ?: '254'),
                ]),
                (string) ($payment->platform?->country ?? '')
            );

        $phoneNumber = trim((string) ($validatedPhoneDetails['phoneNumber'] ?? ''));
        if ($phoneNumber !== '') {
            $payload['phoneNumber'] = $phoneNumber;
        }

        $countryCode = trim((string) ($validatedPhoneDetails['country'] ?? ''));
        if ($countryCode === '') {
            $countryCode = (string) ($this->pawaPayCountryCode((string) ($payment->platform?->country ?? '')) ?? '');
        }
        if ($countryCode !== '') {
            $payload['country'] = $countryCode;
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($apiKey)
            ->post($this->pawaPayBaseUrl($context) . '/v2/paymentpage', $payload);

        if (!$response->successful()) {
            throw new RuntimeException(
                (string) (
                    $response->json('failureReason.failureMessage')
                    ?? $response->json('message')
                    ?? 'pawaPay payment page initialization failed.'
                )
            );
        }

        $redirectUrl = trim((string) $response->json('redirectUrl', ''));
        if ($redirectUrl === '') {
            throw new RuntimeException(
                (string) (
                    $response->json('failureReason.failureMessage')
                    ?? $response->json('message')
                    ?? 'pawaPay did not return a payment page URL.'
                )
            );
        }

        return [
            'type' => 'redirect',
            'url' => $redirectUrl,
            'provider_reference' => (string) $response->json('depositId', $depositId),
            'provider_payload' => [
                'predict_provider' => $validatedPhoneDetails['prediction'] ?? null,
                'payment_page' => $response->json(),
            ],
        ];
    }

    public function sanitizePawaPayPhone(?string $phone, array $context, string $expectedCountry = ''): array
    {
        $normalizedPhone = PhoneNormalizer::normalize(
            $phone,
            (string) ($context['phone_prefix'] ?? data_get($context, 'wallet.market.phone_prefix', '254'))
        );

        if ($normalizedPhone === null || $normalizedPhone === '') {
            return [];
        }

        $apiKey = trim((string) data_get($context, 'provider_credentials.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('pawaPay credentials are incomplete for the active environment.');
        }

        $expectedCountryCode = $this->pawaPayCountryCode($expectedCountry);
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($apiKey)
            ->post($this->pawaPayBaseUrl($context) . '/v2/predict-provider', [
                'phoneNumber' => $normalizedPhone,
            ]);

        if (!$response->successful()) {
            $message = (string) (
                $response->json('failureReason.failureMessage')
                ?? $response->json('message')
                ?? 'Please enter a valid phone number for this market.'
            );

            if ($response->status() >= 500) {
                throw new RuntimeException($message !== '' ? $message : 'pawaPay phone validation failed.');
            }

            throw new InvalidArgumentException($message !== '' ? $message : 'Please enter a valid phone number for this market.');
        }

        $sanitizedPhone = trim((string) $response->json('phoneNumber', ''));
        if ($sanitizedPhone === '') {
            throw new InvalidArgumentException('Please enter a valid phone number for this market.');
        }

        $resolvedCountry = strtoupper(trim((string) $response->json('country', '')));
        if ($expectedCountryCode !== null && $resolvedCountry !== '' && $resolvedCountry !== $expectedCountryCode) {
            throw new InvalidArgumentException('Please enter a valid phone number for this market.');
        }

        return [
            'phoneNumber' => $sanitizedPhone,
            'country' => $resolvedCountry !== '' ? $resolvedCountry : $expectedCountryCode,
            'provider' => trim((string) $response->json('provider', '')),
            'prediction' => $response->json(),
        ];
    }

    public function verifyPaystackTransaction(Payment $payment, array $context, string $reference): array
    {
        $secretKey = (string) data_get($context, 'provider_credentials.secret_key', '');
        $verification = Http::withToken($secretKey)
            ->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/' . urlencode($reference));

        if (!$verification->successful()) {
            throw new RuntimeException('Could not verify the Paystack transaction.');
        }

        $verifiedData = $verification->json('data') ?? [];
        $providerStatus = strtolower((string) ($verifiedData['status'] ?? ''));

        return [
            'status' => $providerStatus === 'success'
                ? 'completed'
                : (in_array($providerStatus, ['pending', 'ongoing', 'processing'], true) ? 'pending' : 'failed'),
            'message' => (string) ($verifiedData['gateway_response'] ?? $providerStatus ?: 'Paystack transaction status unavailable.'),
            'data' => $verifiedData,
        ];
    }

    public function verifyPesapalTransaction(Payment $payment, array $context, string $trackingId): array
    {
        $accessToken = $this->fetchPesapalAccessToken($context);
        $verification = Http::timeout(20)
            ->withToken($accessToken)
            ->get($this->pesapalBaseUrl((string) ($context['environment'] ?? $payment->provider_environment)) . '/Transactions/GetTransactionStatus', [
                'orderTrackingId' => $trackingId,
            ]);

        if (!$verification->successful()) {
            throw new RuntimeException('Could not verify the Pesapal transaction.');
        }

        $verified = $verification->json();
        $statusDescription = strtolower((string) ($verified['payment_status_description'] ?? $verified['status'] ?? ''));
        $statusCode = (int) ($verified['status_code'] ?? 0);
        $normalizedStatus = $statusCode === 1 || str_contains($statusDescription, 'completed')
            ? 'completed'
            : ((str_contains($statusDescription, 'pending') || str_contains($statusDescription, 'processing') || str_contains($statusDescription, 'await')) ? 'pending' : 'failed');

        return [
            'status' => $normalizedStatus,
            'message' => (string) ($verified['payment_status_description'] ?? 'Pesapal transaction status unavailable.'),
            'data' => $verified,
        ];
    }

    public function verifyPawaPayDeposit(Payment $payment, array $context, string $depositId): array
    {
        $apiKey = trim((string) data_get($context, 'provider_credentials.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('pawaPay credentials are incomplete for the active environment.');
        }

        $response = Http::timeout(20)
            ->withToken($apiKey)
            ->get($this->pawaPayBaseUrl($context) . '/v2/deposits/' . urlencode($depositId));

        if (!$response->successful()) {
            throw new RuntimeException('Could not verify the pawaPay deposit status.');
        }

        $verified = $response->json();

        // PawaPay wraps deposit lookups: root "status" is the search result ("FOUND" / "NOT_FOUND"),
        // while the actual deposit state lives in "data.status".
        $searchStatus = strtoupper(trim((string) ($verified['status'] ?? '')));
        if ($searchStatus === 'NOT_FOUND') {
            return [
                'status' => 'failed',
                'message' => 'Deposit not found on pawaPay.',
                'data' => [],
            ];
        }

        $depositData = is_array($verified['data'] ?? null) ? $verified['data'] : $verified;
        $providerStatus = strtoupper(trim((string) ($depositData['status'] ?? '')));
        $normalizedStatus = match ($providerStatus) {
            'COMPLETED' => 'completed',
            'FAILED' => 'failed',
            default => 'pending', // ACCEPTED, PROCESSING, IN_RECONCILIATION, etc.
        };

        return [
            'status' => $normalizedStatus,
            'message' => (string) (
                data_get($depositData, 'failureReason.failureMessage')
                ?? ($providerStatus !== '' ? $providerStatus : 'pawaPay transaction status unavailable.')
            ),
            'data' => $depositData,
        ];
    }

    private function callbackUrl(Payment $payment, array $context, array $options): string
    {
        $configured = trim((string) ($options['callback_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return $this->billingModeService->buildAbsoluteUrl(
            $payment->platform,
            '/billing/complete',
            ['payment' => $payment->transaction_uuid],
            (string) ($context['environment'] ?? null)
        );
    }

    private function customerEmail(Payment $payment): string
    {
        $email = trim((string) data_get($payment->payment_data, 'customer.email', ''));
        if ($email !== '') {
            return $email;
        }

        return trim((string) ($payment->client?->email ?? ''));
    }

    private function fetchPesapalAccessToken(array $context): string
    {
        $credentials = $context['provider_credentials'];
        $response = Http::timeout(20)
            ->post($this->pesapalBaseUrl((string) ($context['environment'] ?? 'sandbox')) . '/Auth/RequestToken', [
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

    private function pesapalBaseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://pay.pesapal.com/v3/api'
            : 'https://cybqa.pesapal.com/pesapalv3/api';
    }

    private function pawaPayBaseUrl(array $context): string
    {
        $configured = trim((string) data_get($context, 'provider_credentials.base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return (string) ($context['environment'] ?? 'sandbox') === 'production'
            ? 'https://api.pawapay.io'
            : 'https://api.sandbox.pawapay.io';
    }

    private function ensurePawaPayDepositId(Payment $payment): string
    {
        $existing = trim((string) (
            data_get($payment->payment_data, 'pawapay.deposit_id')
            ?? data_get($payment->raw_payload, 'pawapay.depositId')
            ?? $payment->transaction_reference
            ?? $payment->transaction_uuid
            ?? ''
        ));

        if ($this->isUuidV4($existing)) {
            return $existing;
        }

        $depositId = (string) Str::uuid();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        data_set($paymentData, 'pawapay.deposit_id', $depositId);
        data_set($rawPayload, 'pawapay.depositId', $depositId);

        $payment->forceFill([
            'payment_data' => $paymentData,
            'raw_payload' => $rawPayload,
        ])->save();

        return $depositId;
    }

    private function pawaPayReturnUrl(Payment $payment, array $context, array $options): string
    {
        $configured = trim((string) ($options['callback_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $callbackBaseUrl = trim((string) data_get($context, 'provider_credentials.callback_base_url', ''));
        if ($callbackBaseUrl !== '') {
            return rtrim($callbackBaseUrl, '/') . '/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid);
        }

        return $this->callbackUrl($payment, $context, $options);
    }

    private function pawaPayCountryCode(string $country): ?string
    {
        return match (strtoupper(trim($country))) {
            'GHANA' => 'GHA',
            'GH', 'GHA' => 'GHA',
            'KENYA' => 'KEN',
            'KE', 'KEN' => 'KEN',
            'MALAWI' => 'MWI',
            'MW', 'MWI' => 'MWI',
            'MOZAMBIQUE' => 'MOZ',
            'MZ', 'MOZ' => 'MOZ',
            'TANZANIA' => 'TZA',
            'TZ', 'TZA' => 'TZA',
            'UGANDA' => 'UGA',
            'UG', 'UGA' => 'UGA',
            'ZAMBIA' => 'ZMB',
            'ZM', 'ZMB' => 'ZMB',
            default => null,
        };
    }

    private function isUuidV4(string $value): bool
    {
        return $value !== ''
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) === 1;
    }
}
