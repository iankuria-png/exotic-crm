<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentAttemptService
{
    public function record(Payment $payment, string $attemptType, string $status, array $attributes = []): ?PaymentAttempt
    {
        try {
            return PaymentAttempt::query()->create([
                'payment_id' => (int) $payment->id,
                'attempt_type' => mb_substr(trim($attemptType), 0, 50),
                'provider' => $attributes['provider'] ?? null,
                'status' => mb_substr(trim($status), 0, 30),
                'error_code' => $attributes['error_code'] ?? null,
                'error_message' => $attributes['error_message'] ?? null,
                'http_status' => isset($attributes['http_status']) ? (int) $attributes['http_status'] : null,
                'latency_ms' => isset($attributes['latency_ms']) ? max(0, (int) $attributes['latency_ms']) : null,
                'request_meta' => is_array($attributes['request_meta'] ?? null) ? $attributes['request_meta'] : null,
                'response_meta' => is_array($attributes['response_meta'] ?? null) ? $attributes['response_meta'] : null,
                'created_by' => isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('PaymentAttemptService failed to record attempt', [
                'payment_id' => $payment->id,
                'attempt_type' => $attemptType,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function requestMetaFromRequest(Request $request, array $extra = []): array
    {
        $userAgent = (string) ($request->userAgent() ?? '');
        $origin = trim((string) ($request->header('Origin') ?? ''));
        $referrer = trim((string) ($request->header('Referer') ?? ''));
        $hasBrowserHeaders = $origin !== '' || $referrer !== '';
        $contextType = $hasBrowserHeaders
            ? 'browser'
            : ($this->looksServerSideRequest($userAgent) ? 'server' : 'unknown');

        $meta = [
            'request_id' => (string) ($request->header('X-Request-Id') ?? ''),
            'context_type' => $contextType,
            'origin_url' => $hasBrowserHeaders ? $origin : null,
            'referrer' => $hasBrowserHeaders ? $referrer : null,
            'ip_hash' => $hasBrowserHeaders ? $this->hashIp($request->ip()) : null,
            'user_agent' => $hasBrowserHeaders && $userAgent !== '' ? $userAgent : null,
            'user_agent_family' => $hasBrowserHeaders ? $this->userAgentFamily($userAgent) : null,
            'device_type' => $hasBrowserHeaders ? $this->deviceType($userAgent) : null,
        ];

        $merged = array_merge($meta, $extra);

        return array_filter($merged, static fn($value) => $value !== null && $value !== '');
    }

    private function userAgentFamily(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'edg/')) return 'Edge';
        if (str_contains($ua, 'chrome/')) return 'Chrome';
        if (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) return 'Safari';
        if (str_contains($ua, 'firefox/')) return 'Firefox';
        if (str_contains($ua, 'postman')) return 'Postman';
        if (str_contains($ua, 'curl/')) return 'cURL';

        return 'Other';
    }

    private function deviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function hashIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }

        $salt = (string) config('app.key', 'payment_attempt_salt');

        return hash('sha256', $ip . '|' . $salt);
    }

    private function looksServerSideRequest(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $ua = strtolower($userAgent);

        foreach (['wordpress', 'wp-http', 'guzzlehttp', 'laravel', 'curl/', 'postman', 'insomnia'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }
}
