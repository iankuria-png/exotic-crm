<?php

namespace App\Services;

use App\Models\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class MarketHealthService
{
    private const HEALTH_GATE_CACHE_PREFIX = 'market-health:gate:';

    /**
     * A connection attempt this long is a real network problem. The old 5s was
     * short enough that ordinary page weight on an intercontinental link read
     * as an outage, which then gated real work.
     */
    private const PROBE_TIMEOUT_SECONDS = 15;

    private const PROBE_CONNECT_TIMEOUT_SECONDS = 5;

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DOMAIN_UNREACHABLE = 'domain_unreachable';

    public const STATUS_SERVER_ERROR = 'server_error';

    public const STATUS_AUTH_ERROR = 'auth_error';

    public const STATUS_WP_ERROR = 'wp_error';

    public const STATUS_UNCONFIGURED = 'unconfigured';

    /**
     * Return the small health snapshot used by WordPress callers without
     * reading the platforms table on every request.
     *
     * An empty array is cached for missing platforms too; Cache::remember does
     * not retain null values, which would turn an unknown platform into a
     * database query on every call.
     *
     * @return array{health_status:string|null,health_consecutive_failures:int}|null
     */
    public function cachedPlatformHealth(int $platformId): ?array
    {
        if ($platformId <= 0) {
            return null;
        }

        $ttl = max(5, (int) config('services.exotic_crm_sync.health_gate_ttl_seconds', 30));

        $snapshot = Cache::remember(
            self::HEALTH_GATE_CACHE_PREFIX.$platformId,
            now()->addSeconds($ttl),
            static function () use ($platformId): array {
                $platform = Platform::query()
                    ->whereKey($platformId)
                    ->first(['health_status', 'health_consecutive_failures']);

                return $platform ? [
                    'health_status' => $platform->health_status,
                    'health_consecutive_failures' => (int) ($platform->health_consecutive_failures ?? 0),
                ] : [];
            }
        );

        return $snapshot === [] ? null : $snapshot;
    }

    /**
     * Whether background work should skip this market rather than call it.
     *
     * Deliberately narrow. `server_error` used to gate too, but that status is
     * also what a *timeout* resolves to — see connectionExceptionStatus() — and
     * a slow market is not a down one. Gating on it meant one slow response
     * could stop real work against a site that was serving perfectly well.
     *
     * Only a connection that never established counts: refused, DNS failure,
     * no route. That is the one signal the probe cannot get wrong by being
     * impatient.
     */
    public function shouldFailFast(?string $status, int $consecutiveFailures): bool
    {
        if (! (bool) config('services.exotic_crm_sync.health_gate_enabled', true)) {
            return false;
        }

        $minimumFailures = max(1, (int) config('services.exotic_crm_sync.health_gate_min_failures', 2));

        return $consecutiveFailures >= $minimumFailures
            && $status === self::STATUS_DOMAIN_UNREACHABLE;
    }

    public function forgetCachedPlatformHealth(int $platformId): void
    {
        if ($platformId > 0) {
            Cache::forget(self::HEALTH_GATE_CACHE_PREFIX.$platformId);
        }
    }

    public function probe(Platform $platform): array
    {
        if (! $this->hasWpCredentials($platform)) {
            return [
                'status' => self::STATUS_UNCONFIGURED,
                'error' => 'WordPress sync credentials are incomplete.',
                'latency_ms' => null,
                'probed_at' => now(),
                'http_status' => null,
            ];
        }

        $started = microtime(true);
        $rootUrl = $this->apiRoot((string) $platform->wp_api_url);

        try {
            // 5s used to be the cutoff, which sat on top of normal: healthy
            // markets probe at 1.7-3.7s and this pulls a full public homepage
            // (TZ once timed out at 5s having already received 745KB — it was
            // mid-transfer, not down). Anything under this is slow, not broken.
            $rootResponse = Http::connectTimeout(self::PROBE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::PROBE_TIMEOUT_SECONDS)
                ->get($rootUrl);
        } catch (ConnectionException $exception) {
            return $this->probeResult(
                $this->connectionExceptionStatus($exception),
                $this->trimError($exception->getMessage()),
                $started
            );
        } catch (Throwable $exception) {
            return $this->probeResult(
                self::STATUS_DOMAIN_UNREACHABLE,
                $this->trimError($exception->getMessage()),
                $started
            );
        }

        if ($rootResponse->serverError()) {
            return $this->probeResult(
                self::STATUS_SERVER_ERROR,
                sprintf('Public domain returned HTTP %d.', $rootResponse->status()),
                $started,
                $rootResponse->status()
            );
        }

        $statsUrl = rtrim((string) $platform->wp_api_url, '/').'/stats';
        try {
            $statsResponse = Http::withHeaders($this->headersForPlatform($platform))
                ->connectTimeout(self::PROBE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::PROBE_TIMEOUT_SECONDS)
                ->get($statsUrl);
        } catch (ConnectionException $exception) {
            return $this->probeResult(
                self::STATUS_SERVER_ERROR,
                $this->trimError($exception->getMessage()),
                $started
            );
        } catch (Throwable $exception) {
            return $this->probeResult(
                self::STATUS_WP_ERROR,
                $this->trimError($exception->getMessage()),
                $started
            );
        }

        if (in_array($statsResponse->status(), [401, 403], true)) {
            return $this->probeResult(
                self::STATUS_AUTH_ERROR,
                sprintf('WordPress sync API rejected credentials with HTTP %d.', $statsResponse->status()),
                $started,
                $statsResponse->status()
            );
        }

        if (! $statsResponse->successful()) {
            return $this->probeResult(
                self::STATUS_WP_ERROR,
                $this->trimError($statsResponse->body() ?: sprintf('WordPress sync API returned HTTP %d.', $statsResponse->status())),
                $started,
                $statsResponse->status()
            );
        }

        if (! is_array($statsResponse->json())) {
            return $this->probeResult(
                self::STATUS_WP_ERROR,
                'WordPress sync API returned a malformed stats response.',
                $started,
                $statsResponse->status()
            );
        }

        return $this->probeResult(self::STATUS_HEALTHY, null, $started, $statsResponse->status());
    }

    public function checkAndStore(Platform $platform): array
    {
        $previousStatus = (string) ($platform->health_status ?: self::STATUS_UNCONFIGURED);
        $previousDown = $this->isDown($previousStatus);

        $result = $this->probe($platform);
        $status = (string) $result['status'];
        $currentDown = $this->isDown($status);
        $checkedAt = $result['probed_at'] ?? now();
        $downSince = $platform->health_down_since_at;
        $transitionedDown = ! $previousDown && $currentDown;

        if ($currentDown && (! $previousDown || ! $downSince)) {
            $downSince = $checkedAt;
        }

        $platform->forceFill([
            'health_status' => $status,
            'health_checked_at' => $checkedAt,
            'health_error' => $result['error'] ?? null,
            'health_latency_ms' => $result['latency_ms'] ?? null,
            'health_consecutive_failures' => $currentDown
                ? (int) ($platform->health_consecutive_failures ?? 0) + 1
                : 0,
            'health_down_since_at' => $currentDown ? $downSince : null,
            'health_last_down_notified_at' => $currentDown ? $platform->health_last_down_notified_at : null,
        ])->save();

        $this->forgetCachedPlatformHealth((int) $platform->id);

        return [
            'platform' => $platform->fresh(),
            'transitioned_down' => $transitionedDown,
        ];
    }

    public function isDown(?string $status): bool
    {
        return ! in_array($status, [self::STATUS_HEALTHY, self::STATUS_UNCONFIGURED, null, ''], true);
    }

    public function summarize(Collection $platforms): array
    {
        $summary = [
            'total' => $platforms->count(),
            'healthy' => 0,
            'down' => 0,
            'unconfigured' => 0,
        ];

        foreach ($platforms as $platform) {
            $status = (string) ($platform->health_status ?: self::STATUS_UNCONFIGURED);
            if ($status === self::STATUS_HEALTHY) {
                $summary['healthy']++;
            } elseif ($status === self::STATUS_UNCONFIGURED) {
                $summary['unconfigured']++;
            } elseif ($this->isDown($status)) {
                $summary['down']++;
            }
        }

        return $summary;
    }

    private function probeResult(string $status, ?string $error, float $started, ?int $httpStatus = null): array
    {
        return [
            'status' => $status,
            'error' => $error ? $this->trimError($error) : null,
            'latency_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'probed_at' => now(),
            'http_status' => $httpStatus,
        ];
    }

    private function headersForPlatform(Platform $platform): array
    {
        $headers = [
            'Authorization' => 'Basic '.base64_encode($platform->wp_api_user.':'.$platform->wp_api_password),
        ];

        $sharedKey = $this->sharedKeyForPlatform($platform);
        if ($sharedKey !== null) {
            $headers['X-Exotic-CRM-Sync-Key'] = $sharedKey;
        }

        return $headers;
    }

    private function sharedKeyForPlatform(Platform $platform): ?string
    {
        $sharedKey = trim((string) config('services.exotic_crm_sync.shared_key', ''));
        if ($sharedKey === '' || ! $platform->id) {
            return null;
        }

        $configured = config('services.exotic_crm_sync.shared_key_platform_ids', '');
        $values = is_array($configured)
            ? $configured
            : (preg_split('/[\s,]+/', (string) $configured) ?: []);

        $platformIds = array_values(array_unique(array_filter(array_map(
            static fn ($value) => (int) $value,
            $values
        ), static fn (int $value) => $value > 0)));

        return in_array((int) $platform->id, $platformIds, true) ? $sharedKey : null;
    }

    private function apiRoot(string $baseUrl): string
    {
        return preg_replace('#/wp-json/.*$#', '', rtrim($baseUrl, '/')) ?: rtrim($baseUrl, '/');
    }

    private function hasWpCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }

    private function connectionExceptionStatus(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        foreach (['connection refused', 'timed out', 'timeout', 'operation timed out'] as $needle) {
            if (str_contains($message, $needle)) {
                return self::STATUS_SERVER_ERROR;
            }
        }

        return self::STATUS_DOMAIN_UNREACHABLE;
    }

    private function trimError(string $message): string
    {
        return mb_substr(trim($message), 0, 500);
    }
}
