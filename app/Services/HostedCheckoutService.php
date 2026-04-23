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

        $phoneNumber = preg_replace('/\D+/', '', (string) ($payment->phone ?: data_get($payment->payment_data, 'customer.phone', '')));
        $phonePrefix = $this->resolvePawaPayPhonePrefix($payment, $context);
        $countryCode = $this->resolvePawaPayCountryCode($payment, $context);
        $currencyCode = $this->resolvePawaPayCurrencyCode($payment, $context, $countryCode, $phonePrefix);

        $payload = [
            'depositId' => $depositId,
            'returnUrl' => $this->pawaPayReturnUrl($payment, $context, $options),
            'amountDetails' => [
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $currencyCode,
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

        if ($countryCode !== null) {
            $payload['country'] = $countryCode;
        } elseif (is_string($phoneNumber) && $phoneNumber !== '') {
            $payload['phoneNumber'] = $phoneNumber;
        } else {
            throw new RuntimeException('pawaPay checkout requires a supported country or customer phone number.');
        }

        $shouldPrefillPhone = (bool) ($options['prefill_phone'] ?? false);
        if ($shouldPrefillPhone && is_string($phoneNumber) && $phoneNumber !== '') {
            $payload['phoneNumber'] = $phoneNumber;
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
            'provider_payload' => $response->json(),
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

    private function resolvePawaPayCountryCode(Payment $payment, array $context): ?string
    {
        $candidates = [
            data_get($context, 'provider_profile_country_code'),
            data_get($context, 'provider_profile.country_code'),
            $payment->platform?->country,
            $payment->platform?->name,
        ];

        foreach ($candidates as $candidate) {
            $countryCode = $this->pawaPayCountryCode((string) $candidate);
            if ($countryCode !== null) {
                return $countryCode;
            }
        }

        $currency = $this->normalizePawaPayCurrencyValue((string) ($payment->currency ?: $payment->platform?->currency_code ?: data_get($context, 'wallet.currency_code', '')));
        $phonePrefix = $this->resolvePawaPayPhonePrefix($payment, $context);

        return $this->pawaPayCountryCodeFromMarketHints($currency, $phonePrefix);
    }

    private function resolvePawaPayPhonePrefix(Payment $payment, array $context): string
    {
        return preg_replace('/\D+/', '', (string) ($payment->platform?->phone_prefix ?: data_get($context, 'phone_prefix', data_get($context, 'wallet.market.phone_prefix', '')))) ?: '';
    }

    private function resolvePawaPayCurrencyCode(Payment $payment, array $context, ?string $countryCode = null, string $phonePrefix = ''): string
    {
        $rawCurrency = $this->normalizePawaPayCurrencyValue((string) ($payment->currency ?: $payment->platform?->currency_code ?: data_get($context, 'wallet.currency_code', 'KES')));
        if ($rawCurrency === '') {
            $rawCurrency = 'KES';
        }

        $resolvedCountryCode = $countryCode;
        if ($resolvedCountryCode === null && $phonePrefix !== '') {
            $resolvedCountryCode = $this->pawaPayCountryCodeFromMarketHints($rawCurrency, $phonePrefix);
        }

        return $this->pawaPayCurrencyCode($rawCurrency, $resolvedCountryCode) ?? $rawCurrency;
    }

    private function pawaPayCountryCode(string $country): ?string
    {
        $normalized = $this->normalizePawaPayCountryValue($country);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->pawaPayCountryDefinitions() as $countryCode => $definition) {
            $candidates = array_merge(
                [$countryCode, (string) ($definition['alpha2'] ?? '')],
                (array) ($definition['aliases'] ?? [])
            );

            foreach ($candidates as $candidate) {
                if ($normalized === $this->normalizePawaPayCountryValue((string) $candidate)) {
                    return $countryCode;
                }
            }
        }

        return null;
    }

    private function pawaPayCountryCodeFromMarketHints(string $currency, string $phonePrefix): ?string
    {
        if ($currency === '' || $phonePrefix === '') {
            return null;
        }

        foreach ($this->pawaPayCountryDefinitions() as $countryCode => $definition) {
            $phonePrefixes = array_map(
                static fn (string $candidate): string => preg_replace('/\D+/', '', $candidate) ?: '',
                (array) ($definition['phone_prefixes'] ?? [])
            );

            if ($this->pawaPayCurrencyMatchesDefinition($currency, $definition) && in_array($phonePrefix, $phonePrefixes, true)) {
                return $countryCode;
            }
        }

        return null;
    }

    private function pawaPayCurrencyCode(string $currency, ?string $countryCode = null): ?string
    {
        $normalized = $this->normalizePawaPayCurrencyValue($currency);
        if ($normalized === '') {
            return null;
        }

        if ($countryCode !== null) {
            $definition = $this->pawaPayCountryDefinitions()[$countryCode] ?? null;
            if (is_array($definition)) {
                $resolved = $this->pawaPayCurrencyCodeForDefinition($normalized, $definition);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        foreach ($this->pawaPayCountryDefinitions() as $definition) {
            $resolved = $this->pawaPayCurrencyCodeForDefinition($normalized, $definition);
            if ($resolved !== null && $resolved === $normalized) {
                return $resolved;
            }
        }

        return match ($normalized) {
            'BIR' => 'ETB',
            default => null,
        };
    }

    private function pawaPayCurrencyMatchesDefinition(string $currency, array $definition): bool
    {
        return $this->pawaPayCurrencyCodeForDefinition($currency, $definition) !== null;
    }

    private function pawaPayCurrencyCodeForDefinition(string $currency, array $definition): ?string
    {
        $normalized = $this->normalizePawaPayCurrencyValue($currency);
        if ($normalized === '') {
            return null;
        }

        $currencies = array_map(
            fn (string $candidate): string => $this->normalizePawaPayCurrencyValue($candidate),
            (array) ($definition['currencies'] ?? [])
        );

        if (in_array($normalized, $currencies, true)) {
            return $normalized;
        }

        foreach ((array) ($definition['currency_aliases'] ?? []) as $alias => $resolvedCurrency) {
            if ($normalized === $this->normalizePawaPayCurrencyValue((string) $alias)) {
                $resolved = $this->normalizePawaPayCurrencyValue((string) $resolvedCurrency);

                return in_array($resolved, $currencies, true) ? $resolved : null;
            }
        }

        return null;
    }

    private function normalizePawaPayCountryValue(string $value): string
    {
        $normalized = Str::upper(Str::ascii(trim($value)));
        $normalized = str_replace(["'", '.', ',', '-', '_', '(', ')'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;

        return trim($normalized);
    }

    private function normalizePawaPayCurrencyValue(string $value): string
    {
        return Str::upper(trim($value));
    }

    private function pawaPayCountryDefinitions(): array
    {
        return [
            'BEN' => [
                'alpha2' => 'BJ',
                'aliases' => ['Benin'],
                'currencies' => ['XOF'],
                'currency_aliases' => ['CFA' => 'XOF'],
                'phone_prefixes' => ['229'],
            ],
            'BFA' => [
                'alpha2' => 'BF',
                'aliases' => ['Burkina Faso'],
                'currencies' => ['XOF'],
                'currency_aliases' => ['CFA' => 'XOF'],
                'phone_prefixes' => ['226'],
            ],
            'CMR' => [
                'alpha2' => 'CM',
                'aliases' => ['Cameroon'],
                'currencies' => ['XAF'],
                'currency_aliases' => ['CFA' => 'XAF'],
                'phone_prefixes' => ['237'],
            ],
            'CIV' => [
                'alpha2' => 'CI',
                'aliases' => ["Cote d'Ivoire", "Cote dIvoire", 'Ivory Coast', "Côte d'Ivoire"],
                'currencies' => ['XOF'],
                'currency_aliases' => ['CFA' => 'XOF'],
                'phone_prefixes' => ['225'],
            ],
            'COD' => [
                'alpha2' => 'CD',
                'aliases' => [
                    'Democratic Republic of the Congo',
                    'Democratic Republic of Congo',
                    'DR Congo',
                    'Congo Kinshasa',
                    'Congo DRC',
                    'DRC',
                ],
                'currencies' => ['CDF', 'USD'],
                'phone_prefixes' => ['243'],
            ],
            'ETH' => [
                'alpha2' => 'ET',
                'aliases' => ['Ethiopia'],
                'currencies' => ['ETB'],
                'currency_aliases' => ['BIR' => 'ETB'],
                'phone_prefixes' => ['251'],
            ],
            'GAB' => [
                'alpha2' => 'GA',
                'aliases' => ['Gabon'],
                'currencies' => ['XAF'],
                'currency_aliases' => ['CFA' => 'XAF'],
                'phone_prefixes' => ['241'],
            ],
            'GHA' => [
                'alpha2' => 'GH',
                'aliases' => ['Ghana'],
                'currencies' => ['GHS'],
                'phone_prefixes' => ['233'],
            ],
            'KEN' => [
                'alpha2' => 'KE',
                'aliases' => ['Kenya'],
                'currencies' => ['KES'],
                'phone_prefixes' => ['254'],
            ],
            'LSO' => [
                'alpha2' => 'LS',
                'aliases' => ['Lesotho'],
                'currencies' => ['LSL'],
                'phone_prefixes' => ['266'],
            ],
            'MWI' => [
                'alpha2' => 'MW',
                'aliases' => ['Malawi'],
                'currencies' => ['MWK'],
                'phone_prefixes' => ['265'],
            ],
            'MOZ' => [
                'alpha2' => 'MZ',
                'aliases' => ['Mozambique'],
                'currencies' => ['MZN'],
                'phone_prefixes' => ['258'],
            ],
            'NGA' => [
                'alpha2' => 'NG',
                'aliases' => ['Nigeria'],
                'currencies' => ['NGN'],
                'phone_prefixes' => ['234'],
            ],
            'COG' => [
                'alpha2' => 'CG',
                'aliases' => ['Republic of the Congo', 'Republic of Congo', 'Congo Brazzaville'],
                'currencies' => ['XAF'],
                'currency_aliases' => ['CFA' => 'XAF'],
                'phone_prefixes' => ['242'],
            ],
            'RWA' => [
                'alpha2' => 'RW',
                'aliases' => ['Rwanda'],
                'currencies' => ['RWF'],
                'phone_prefixes' => ['250'],
            ],
            'SEN' => [
                'alpha2' => 'SN',
                'aliases' => ['Senegal'],
                'currencies' => ['XOF'],
                'currency_aliases' => ['CFA' => 'XOF'],
                'phone_prefixes' => ['221'],
            ],
            'SLE' => [
                'alpha2' => 'SL',
                'aliases' => ['Sierra Leone'],
                'currencies' => ['SLE'],
                'phone_prefixes' => ['232'],
            ],
            'TZA' => [
                'alpha2' => 'TZ',
                'aliases' => ['Tanzania'],
                'currencies' => ['TZS'],
                'phone_prefixes' => ['255'],
            ],
            'UGA' => [
                'alpha2' => 'UG',
                'aliases' => ['Uganda'],
                'currencies' => ['UGX'],
                'phone_prefixes' => ['256'],
            ],
            'ZMB' => [
                'alpha2' => 'ZM',
                'aliases' => ['Zambia'],
                'currencies' => ['ZMW'],
                'phone_prefixes' => ['260'],
            ],
        ];
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
