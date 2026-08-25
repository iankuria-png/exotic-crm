<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactUnlockEventService
{
    public function record(
        Platform $platform,
        int $wpPostId,
        string $eventId,
        string $eventType,
        string $sessionProof,
        array $visitorContext = [],
        ?string $publicToken = null,
        ?Request $request = null
    ): array {
        if (! in_array($eventType, ContactUnlockEvent::TYPES, true)) {
            throw ValidationException::withMessages(['event_type' => 'Unknown contact unlock event.']);
        }

        $client = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', $wpPostId)
            ->first();

        if (! $client) {
            throw ValidationException::withMessages(['wp_post_id' => 'Profile was not found for this market.']);
        }

        $eventHash = $this->hashToken($eventId);
        $sessionHash = $this->hashToken($sessionProof);
        $browser = is_array(data_get($visitorContext, 'browser')) ? data_get($visitorContext, 'browser') : $visitorContext;
        $referrerHost = $this->cleanHost((string) data_get($browser, 'referrer_host', ''));
        $trafficSource = $this->trafficSource($referrerHost);
        $localHour = $this->localHour(data_get($browser, 'timezone_offset_minutes'));
        $unlock = $this->resolveUnlock($publicToken, $sessionHash, (int) $platform->id);

        $event = ContactUnlockEvent::query()->firstOrCreate(
            ['event_id_hash' => $eventHash],
            [
                'platform_id' => (int) $platform->id,
                'client_id' => (int) $client->id,
                'wp_post_id' => $wpPostId,
                'visitor_contact_unlock_id' => $unlock?->id,
                'event_type' => $eventType,
                'scope' => $unlock?->scope,
                'session_hash' => $sessionHash,
                'pageview_id' => $this->stringValue(data_get($browser, 'pageview_id', ''), 64),
                'visitor_phone_hash' => $unlock?->visitor_phone_hash,
                'referrer_host' => $referrerHost,
                'traffic_source' => $trafficSource,
                'local_hour' => $localHour,
                'occurred_at' => now(),
                'metadata_json' => [
                    'locale' => $this->stringValue(data_get($browser, 'locale', ''), 40),
                    'timezone' => $this->stringValue(data_get($browser, 'timezone', ''), 80),
                    'current_path' => $this->stringValue(data_get($browser, 'current_path', ''), 180),
                    'viewport' => [
                        'width' => data_get($browser, 'viewport.width'),
                        'height' => data_get($browser, 'viewport.height'),
                    ],
                    'ip_hash' => $request?->ip() ? $this->hashToken((string) $request->ip()) : null,
                ],
            ]
        );

        return [
            'ok' => true,
            'deduped' => ! $event->wasRecentlyCreated,
        ];
    }

    private function resolveUnlock(?string $publicToken, string $sessionHash, int $platformId): ?VisitorContactUnlock
    {
        $token = trim((string) $publicToken);
        if ($token === '') {
            return null;
        }

        return VisitorContactUnlock::query()
            ->where('platform_id', $platformId)
            ->where('public_token_hash', $this->hashToken($token))
            ->where('session_token_hash', $sessionHash)
            ->first();
    }

    private function trafficSource(string $host): string
    {
        if ($host === '') {
            return 'direct';
        }

        $host = strtolower($host);
        if (str_contains($host, 'google.')) {
            return 'google';
        }
        if (str_contains($host, 'facebook.') || str_contains($host, 'instagram.') || str_contains($host, 't.co')) {
            return 'social';
        }
        if (str_contains($host, 'bing.') || str_contains($host, 'yahoo.') || str_contains($host, 'duckduckgo.')) {
            return 'search';
        }

        return 'referral';
    }

    private function localHour(mixed $timezoneOffset): ?int
    {
        if (! is_numeric($timezoneOffset)) {
            return null;
        }

        return (int) now()->subMinutes((int) $timezoneOffset)->format('G');
    }

    private function cleanHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.-]/', '', $host) ?: '';

        return Str::limit($host, 120, '');
    }

    private function stringValue(mixed $value, int $maxLength): string
    {
        return Str::limit(trim((string) $value), $maxLength, '');
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
