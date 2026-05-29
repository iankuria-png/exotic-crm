<?php

namespace App\Services\Messaging\Sidecar;

class HmacSigner
{
    public function sign(string $body, ?string $secret = null, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?: time();
        $secret = $secret ?: (string) config('services.whatsapp.sidecar_hmac_secret');
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public function verify(string $body, ?string $header, array $secrets, ?int $skewSeconds = null): bool
    {
        if (!$header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
        $provided = (string) ($parts['v1'] ?? '');
        $skewSeconds = $skewSeconds ?: (int) config('services.whatsapp.sidecar_clock_skew_seconds', 300);

        if (!$timestamp || !$provided || abs(time() - $timestamp) > $skewSeconds) {
            return false;
        }

        foreach (array_filter($secrets) as $secret) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, (string) $secret);
            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }
}
