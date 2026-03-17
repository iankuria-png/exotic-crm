<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
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
        $email = $payment->client?->email
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
                    'email_address' => $payment->client?->email ?: 'wallet@example.test',
                    'phone_number' => $payment->phone,
                    'country_code' => strtoupper(substr((string) ($payment->platform?->country ?: 'KE'), 0, 2)),
                    'first_name' => $payment->client?->name ?: 'Wallet',
                    'last_name' => 'Customer',
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
}
