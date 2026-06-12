<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CityGeocodingService
{
    private ?float $nextRequestAt = null;

    public function resolve(string $city, ?string $alpha2, ?int $ratePerMinute = null): array
    {
        $baseUrl = (string) config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org/search');
        $userAgent = trim((string) config('services.nominatim.user_agent', ''));

        if ($userAgent === '') {
            return [
                'status' => 'failed',
                'failure_reason' => 'Missing Nominatim User-Agent configuration.',
            ];
        }

        $effectiveRate = max(1, (int) ($ratePerMinute ?? config('services.nominatim.rate_per_minute', 60)));
        $this->throttle($effectiveRate);

        $query = [
            'format' => 'jsonv2',
            'limit' => 1,
            'q' => $city,
        ];

        if ($alpha2 !== null && $alpha2 !== '') {
            $query['countrycodes'] = strtolower($alpha2);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'application/json',
            ])->timeout(20)->get($baseUrl, $query);
        } catch (\Throwable $exception) {
            return [
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
            ];
        }

        if ($response->failed()) {
            return [
                'status' => 'failed',
                'failure_reason' => sprintf('HTTP %d from geocoder.', $response->status()),
            ];
        }

        $payload = $response->json();
        if (!is_array($payload) || $payload === []) {
            return ['status' => 'unresolved'];
        }

        $match = $payload[0] ?? null;
        if (!is_array($match) || !isset($match['lat'], $match['lon'])) {
            return ['status' => 'unresolved'];
        }

        return [
            'status' => 'resolved',
            'latitude' => (float) $match['lat'],
            'longitude' => (float) $match['lon'],
            'importance' => isset($match['importance']) ? (float) $match['importance'] : null,
            'match_type' => $match['type'] ?? $match['class'] ?? null,
        ];
    }

    private function throttle(int $ratePerMinute): void
    {
        $interval = 60 / max(1, $ratePerMinute);
        $now = microtime(true);

        if ($this->nextRequestAt !== null && $now < $this->nextRequestAt) {
            usleep((int) (($this->nextRequestAt - $now) * 1_000_000));
        }

        $this->nextRequestAt = microtime(true) + $interval;
    }
}
