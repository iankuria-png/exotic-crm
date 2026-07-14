<?php

namespace App\Services\PushNotification\Concerns;

use Illuminate\Support\Arr;

/**
 * Shared classifier for push-provider failure responses. Each provider passes
 * its own prefix (e.g. `webpushr`, `epe`, `wonderpush`, `izooto`) so the codes
 * are namespaced but the mapping table is defined in one place.
 *
 * Output codes:
 *   {prefix}_credentials_missing   — config missing before we can call
 *   {prefix}_unauthorized          — HTTP 401
 *   {prefix}_forbidden             — HTTP 403
 *   {prefix}_not_found             — HTTP 404
 *   {prefix}_validation            — HTTP 422
 *   {prefix}_rate_limited          — HTTP 429 (retryAfterMs surfaced when present)
 *   {prefix}_provider_error        — HTTP 5xx
 *   {prefix}_http_error            — any other non-2xx
 *   {prefix}_rejected              — HTTP 2xx with body signalling failure
 */
trait ClassifiesProviderFailure
{
    /**
     * @return array{0:string,1:string}
     */
    protected function classifyProviderFailure(string $prefix, int $status, array $body): array
    {
        $detail = $this->extractProviderMessage($body);

        // 2xx with an application-level failure flag (e.g. WonderPush returns 200
        // with `success: false` for some validation cases). Prefer the server's
        // own reason string when supplied.
        if ($status >= 200 && $status < 300) {
            return [
                "{$prefix}_rejected",
                $detail !== '' ? $detail : 'Provider rejected the notification.',
            ];
        }

        return match (true) {
            $status === 401 => ["{$prefix}_unauthorized", $detail !== '' ? $detail : 'Auth token invalid or rotated (401).'],
            $status === 403 => ["{$prefix}_forbidden", $detail !== '' ? $detail : 'Credentials not permitted for this action (403).'],
            $status === 404 => ["{$prefix}_not_found", $detail !== '' ? $detail : 'Resource not found (404).'],
            $status === 422 => ["{$prefix}_validation", $detail !== '' ? $detail : 'Payload rejected as invalid (422).'],
            $status === 429 => ["{$prefix}_rate_limited", $this->formatProviderRateLimitMessage($body, $detail)],
            $status >= 500 => ["{$prefix}_provider_error", $detail !== '' ? $detail : "Provider internal error ({$status})."],
            default => ["{$prefix}_http_error", $detail !== '' ? $detail : "Provider returned HTTP {$status}."],
        };
    }

    protected function extractProviderMessage(array $body): string
    {
        foreach (['error.message', 'error', 'message', 'description', 'reason'] as $path) {
            $value = Arr::get($body, $path);
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 240);
            }
        }

        return '';
    }

    protected function formatProviderRateLimitMessage(array $body, string $detail): string
    {
        $retryAfterMs = Arr::get($body, 'retryAfterMs');
        if (is_numeric($retryAfterMs) && (int) $retryAfterMs > 0) {
            $seconds = max(1, (int) ceil(((int) $retryAfterMs) / 1000));
            $base = $detail !== '' ? $detail : 'Rate limit exceeded';
            return "{$base} (retry after {$seconds}s).";
        }

        $retryAfter = Arr::get($body, 'retry_after');
        if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
            $seconds = max(1, (int) $retryAfter);
            $base = $detail !== '' ? $detail : 'Rate limit exceeded';
            return "{$base} (retry after {$seconds}s).";
        }

        return $detail !== '' ? $detail : 'Rate limit exceeded (429).';
    }
}
