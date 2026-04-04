<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class LegacyStkService
{
    public function __construct(
        private readonly WalletSettingsService $walletSettingsService,
        private readonly KopokopoService $kopokopoService,
        private readonly PaymentAttemptService $paymentAttemptService
    ) {
    }

    public function initiate(Payment $payment, array $attributes = []): array
    {
        $payment->loadMissing(['platform', 'client', 'product']);
        $platform = $payment->platform ?: Platform::query()->findOrFail((int) $payment->platform_id);
        $environment = $this->resolveEnvironment($platform, $payment, $attributes['environment'] ?? null);
        $runtimeWallet = $this->walletSettingsService->runtimePlatformConfig($platform);
        $credentials = data_get($runtimeWallet, "credentials.mpesa_stk.{$environment}", []);
        $transport = (string) ($credentials['transport'] ?? 'django_proxy');
        $phone = trim((string) ($attributes['phone'] ?? $payment->phone ?? ''));

        if ($phone === '') {
            throw new InvalidArgumentException('Payment has no valid phone number for STK push.');
        }

        $payload = [
            'payment_id' => (int) $payment->id,
            'product_id' => (int) $payment->product_id,
            'platform_id' => (int) $payment->platform_id,
            'user_id' => (int) $payment->user_id,
            'phone' => $phone,
            'amount' => (float) $payment->amount,
            'duration' => (string) ($attributes['duration'] ?? $payment->duration ?? 'monthly'),
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $attributes['email'] ?? null,
        ];

        if ($transport === 'direct_provider') {
            return $this->initiateDirectProvider($payment, $platform, $environment, $payload);
        }

        return $this->initiateDjangoProxy($environment, $credentials, $payload);
    }

    public function initiateWithTelemetry(
        Payment $payment,
        array $attributes = [],
        ?Request $request = null,
        ?int $actorId = null
    ): array {
        $attemptStartedAt = microtime(true);
        $requestMeta = $request
            ? $this->paymentAttemptService->requestMetaFromRequest($request, array_filter([
                'channel' => 'stk',
                'phone' => $attributes['phone'] ?? $payment->phone,
                'amount' => (float) $payment->amount,
                'duration' => $attributes['duration'] ?? $payment->duration,
            ], static fn ($value) => $value !== null && $value !== ''))
            : null;

        try {
            $result = $this->initiate($payment, $attributes);
        } catch (\Throwable $exception) {
            $this->paymentAttemptService->record($payment, 'stk_initiate', 'failed', [
                'provider' => null,
                'error_code' => 'preflight_exception',
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
                'request_meta' => $requestMeta,
                'created_by' => $actorId,
            ]);

            throw $exception;
        }

        $status = ($result['success'] ?? false) ? 'success' : 'failed';
        $this->paymentAttemptService->record($payment, 'stk_initiate', $status, [
            'provider' => $result['provider'] ?? null,
            'error_code' => $status === 'failed'
                ? (!empty($result['http_status']) ? 'upstream_http_' . $result['http_status'] : 'initiation_failed')
                : null,
            'error_message' => $status === 'failed' ? ($result['message'] ?? null) : null,
            'http_status' => $result['http_status'] ?? null,
            'latency_ms' => (int) round((microtime(true) - $attemptStartedAt) * 1000),
            'request_meta' => $requestMeta,
            'response_meta' => array_filter([
                'message' => $result['message'] ?? null,
                'transport' => $result['transport'] ?? null,
                'upstream_url' => $result['upstream_url'] ?? null,
                'provider_environment' => $result['provider_environment'] ?? null,
                'provider_reference' => $result['provider_reference'] ?? null,
                'provider_response' => $result['provider_response'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'created_by' => $actorId,
        ]);

        return $result;
    }

    private function initiateDjangoProxy(string $environment, array $credentials, array $payload): array
    {
        $baseUrl = rtrim((string) ($credentials['payment_service_base_url'] ?? config('services.django.base_url', '')), '/');
        $organizationCode = trim((string) ($credentials['organization_code'] ?? config('services.sms.org_code', '76')));

        if ($baseUrl === '') {
            throw new InvalidArgumentException('Payment service URL is not configured.');
        }

        if ($organizationCode === '') {
            throw new InvalidArgumentException('Organization code is not configured for STK push.');
        }

        $response = Http::timeout(30)->post("{$baseUrl}/initiate/", array_merge($payload, [
            'organization_code' => $organizationCode,
        ]));
        $body = trim((string) $response->body());
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? $decoded : null;
        $redirectLocation = trim((string) ($response->header('Location') ?? ''));

        if ($response->successful() && ($data['message'] ?? null) === 'Payment initiated') {
            return [
                'success' => true,
                'provider' => 'django_stk',
                'provider_environment' => $environment,
                'transport' => 'django_proxy',
                'upstream_url' => $baseUrl,
                'message' => 'STK push sent. Customer should complete the request on their phone.',
                'http_status' => $response->status(),
                'provider_response' => $data,
            ];
        }

        return [
            'success' => false,
            'provider' => 'django_stk',
            'provider_environment' => $environment,
            'transport' => 'django_proxy',
            'upstream_url' => $baseUrl,
            'message' => $this->proxyFailureMessage($data, $body, $response->status(), $baseUrl, $redirectLocation),
            'http_status' => $response->status(),
            'provider_response' => $data,
            'redirect_location' => $redirectLocation !== '' ? $redirectLocation : null,
            'response_body' => $body !== '' ? mb_substr($body, 0, 2000) : null,
        ];
    }

    private function initiateDirectProvider(Payment $payment, Platform $platform, string $environment, array $payload): array
    {
        $result = $this->kopokopoService->initiateStkPush(
            $payload['phone'],
            $payload['amount'],
            url('/api/payment-callback'),
            [
                'payment_id' => (int) $payment->id,
                'platform_id' => (int) $platform->id,
                'product_id' => (int) $payment->product_id,
                'user_id' => (int) $payment->user_id,
                'client_id' => (int) $payment->client_id,
                'duration' => $payload['duration'],
            ]
        );

        $status = $result['status'] ?? null;
        $successful = $status === 'success' || $status === true || $status === 1;

        if (!$successful) {
            return [
                'success' => false,
                'provider' => 'kopokopo_direct',
                'provider_environment' => $environment,
                'transport' => 'direct_provider',
                'upstream_url' => (string) config('services.kopokopo.base_url', ''),
                'message' => (string) ($result['error'] ?? $result['message'] ?? $result['data'] ?? 'STK push could not be initiated.'),
                'http_status' => null,
                'provider_response' => is_array($result) ? $result : null,
            ];
        }

        return [
            'success' => true,
            'provider' => 'kopokopo_direct',
            'provider_environment' => $environment,
            'transport' => 'direct_provider',
            'upstream_url' => (string) config('services.kopokopo.base_url', ''),
            'message' => 'STK push sent. Customer should complete the request on their phone.',
            'http_status' => null,
            'provider_reference' => (string) ($result['location'] ?? ''),
            'provider_response' => is_array($result) ? $result : null,
        ];
    }

    private function resolveEnvironment(Platform $platform, Payment $payment, mixed $preferredEnvironment): string
    {
        $candidate = strtolower(trim((string) $preferredEnvironment));
        if (in_array($candidate, WalletSettingsService::ENVIRONMENTS, true)) {
            return $candidate;
        }

        $paymentEnvironment = strtolower(trim((string) ($payment->provider_environment ?? '')));
        if (in_array($paymentEnvironment, WalletSettingsService::ENVIRONMENTS, true)) {
            return $paymentEnvironment;
        }

        $runtimeWallet = $this->walletSettingsService->runtimePlatformConfig($platform);
        $modeOverride = strtolower(trim((string) data_get($runtimeWallet, 'mode_override', '')));
        if (in_array($modeOverride, WalletSettingsService::ENVIRONMENTS, true)) {
            return $modeOverride;
        }

        $effectiveMode = strtolower(trim((string) data_get($runtimeWallet, 'effective_mode', '')));
        if (in_array($effectiveMode, WalletSettingsService::ENVIRONMENTS, true)) {
            return $effectiveMode;
        }

        $systemMode = strtolower(trim((string) data_get($this->walletSettingsService->currentSystemConfig(masked: false), 'mode', '')));
        if (in_array($systemMode, WalletSettingsService::ENVIRONMENTS, true)) {
            return $systemMode;
        }

        return app()->environment('production') ? 'production' : 'sandbox';
    }

    private function proxyFailureMessage(?array $data, string $body, int $status, string $baseUrl, string $redirectLocation = ''): string
    {
        $knownMessage = trim((string) ($data['error'] ?? $data['message'] ?? ''));
        if ($knownMessage !== '') {
            return $knownMessage;
        }

        $normalizedBody = strtolower($body);
        $normalizedRedirect = strtolower($redirectLocation);
        if (
            ($normalizedBody !== '' && (str_contains($normalizedBody, 'suspendedpage') || str_contains($normalizedBody, 'account has been suspended')))
            || ($normalizedRedirect !== '' && str_contains($normalizedRedirect, 'suspendedpage.cgi'))
        ) {
            return sprintf('Configured payment service is suspended or unavailable: %s', $baseUrl);
        }

        if (
            $status === 522
            || $status === 524
            || ($normalizedBody !== '' && str_contains($normalizedBody, 'connection timed out'))
        ) {
            return sprintf('Configured payment service timed out: %s', $baseUrl);
        }

        if ($status >= 500) {
            return 'Payment service is unavailable.';
        }

        if ($body !== '') {
            return 'Payment service returned an invalid response.';
        }

        return 'STK push could not be initiated.';
    }
}
