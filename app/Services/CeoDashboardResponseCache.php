<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived response cache for the CEO dashboard's heavy read endpoints.
 *
 * Every dashboard load used to recompute ~57s of revenue aggregation from scratch (summary
 * alone 17-22s). Responses are cached per user + exact query + EAT day:
 *  - fresh (<= fresh seconds): served from cache ("hit");
 *  - stale (<= stale window): served immediately, then recomputed once after the response
 *    has been sent ("stale"), so nobody waits on the recompute;
 *  - missing: computed in-request under a build lock so concurrent identical requests
 *    share one computation ("miss").
 * The X-Dashboard-Cache / X-Dashboard-Generated-At headers expose the state; the SPA refetches
 * a stale response shortly afterwards. Errors (validation, aborts) are never cached.
 */
class CeoDashboardResponseCache
{
    private const PREFIX = 'ceo-dashboard:response:v1:';

    /** Fresh lifetime per endpoint in seconds. */
    private const FRESH_SECONDS = [
        'recent-payments' => 30,
    ];

    private const DEFAULT_FRESH_SECONDS = 120;

    /** How long a stale response may still be served while it refreshes. Exceeds the warm interval. */
    private const STALE_SECONDS = 1500;

    private const BUILD_LOCK_SECONDS = 120;

    private const BUILD_WAIT_SECONDS = 60;

    /** The dashboard's default first-load requests, warmed on a schedule. */
    public const DEFAULT_VIEW = ['horizon' => '30d', 'currency_mode' => 'flat', 'reporting_currency' => 'USD'];

    public function __construct(private readonly CeoDashboardDataService $dashboardData) {}

    public function enabled(): bool
    {
        return (bool) config('crm.ceo_dashboard_cache.enabled', true);
    }

    /**
     * @param  callable(): array  $compute
     */
    public function respond(Request $request, string $endpoint, callable $compute): JsonResponse
    {
        if (! $this->enabled() || ! $request->user()) {
            return response()->json($compute());
        }

        $key = $this->key($request, $endpoint);
        $entry = $this->read($key);

        if ($entry !== null) {
            if ($this->age($entry) <= $this->freshSeconds($endpoint)) {
                return $this->response($entry, 'hit');
            }

            app()->terminating(fn () => $this->refresh($key, $compute));

            return $this->response($entry, 'stale');
        }

        return $this->response($this->build($key, $compute), 'miss');
    }

    /**
     * Recompute and store the default first-load view for one CEO user.
     *
     * @return array<string, float> endpoint => seconds taken
     */
    public function warmDefaultView(User $user): array
    {
        $timings = [];

        foreach ($this->defaultViewRequests() as $endpoint => [$method, $params]) {
            $request = Request::create('/api/crm/dashboard/ceo/'.$endpoint, 'GET', $params);
            $previous = app()->bound('request') ? app('request') : null;
            app()->instance('request', $request);
            // Rebinding 'request' resets its user resolver to the (empty) console guard, so set it after.
            $request->setUserResolver(fn () => $user);

            $started = microtime(true);
            try {
                $this->store($this->key($request, $endpoint), $this->dashboardData->{$method}($request));
                $timings[$endpoint] = round(microtime(true) - $started, 2);
            } catch (Throwable $e) {
                Log::warning('CEO dashboard cache warm failed', ['endpoint' => $endpoint, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                $timings[$endpoint] = -1.0;
            } finally {
                if ($previous) {
                    app()->instance('request', $previous);
                }
            }
        }

        return $timings;
    }

    /**
     * Exactly the parameters the SPA sends on first load (see resources/js/pages/CeoDashboard.jsx).
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public function defaultViewRequests(): array
    {
        $base = self::DEFAULT_VIEW;

        return [
            'summary' => ['summary', $base],
            'product-revenue' => ['productRevenue', $base + ['analysis' => 1, 'product' => 'all', 'bucket' => 'auto', 'analysis_view' => 'trend']],
            'market-pie' => ['marketPie', $base],
            'agent-performance' => ['agentPerformance', $base],
            'recent-payments' => ['recentPayments', $base + ['limit' => 10, 'channel' => 'all']],
        ];
    }

    public function key(Request $request, string $endpoint): string
    {
        $params = collect($request->query())
            ->except(['_'])
            ->map(fn ($value) => is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : $value))
            ->sortKeys()
            ->all();

        return self::PREFIX.$endpoint.':'.$request->user()->getKey().':'
            .now('Africa/Nairobi')->toDateString().':'
            .sha1(json_encode($params));
    }

    private function refresh(string $key, callable $compute): void
    {
        $lock = $this->lock($key.':refresh', self::BUILD_LOCK_SECONDS);
        if ($lock && ! $lock->get()) {
            return; // another worker is already refreshing this entry
        }

        try {
            @set_time_limit(180);
            $this->store($key, $compute());
        } catch (Throwable $e) {
            Log::warning('CEO dashboard cache refresh failed', ['key' => $key, 'error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return array{json: string, at: int}
     */
    private function build(string $key, callable $compute): array
    {
        $lock = $this->lock($key.':build', self::BUILD_LOCK_SECONDS);
        if (! $lock) {
            return $this->store($key, $compute());
        }

        try {
            return $lock->block(self::BUILD_WAIT_SECONDS, function () use ($key, $compute) {
                // Another request may have built it while this one waited for the lock.
                return $this->read($key) ?? $this->store($key, $compute());
            });
        } catch (LockTimeoutException) {
            return $this->read($key) ?? $this->store($key, $compute());
        }
    }

    /**
     * @return array{json: string, at: int}
     */
    private function store(string $key, array $payload): array
    {
        $entry = ['json' => (new JsonResponse($payload))->getContent(), 'at' => now()->getTimestamp()];

        try {
            $this->cache()->put($key, $entry, self::DEFAULT_FRESH_SECONDS + self::STALE_SECONDS);
        } catch (Throwable $e) {
            Log::warning('CEO dashboard cache write failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        return $entry;
    }

    /**
     * @return array{json: string, at: int}|null
     */
    private function read(string $key): ?array
    {
        try {
            $entry = $this->cache()->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_array($entry) && is_string($entry['json'] ?? null) && is_int($entry['at'] ?? null) ? $entry : null;
    }

    private function response(array $entry, string $state): JsonResponse
    {
        return JsonResponse::fromJsonString($entry['json'])->withHeaders([
            'X-Dashboard-Cache' => $state,
            'X-Dashboard-Generated-At' => gmdate('c', $entry['at']),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function age(array $entry): int
    {
        return max(0, now()->getTimestamp() - $entry['at']);
    }

    private function freshSeconds(string $endpoint): int
    {
        return self::FRESH_SECONDS[$endpoint] ?? self::DEFAULT_FRESH_SECONDS;
    }

    private function lock(string $name, int $seconds): ?\Illuminate\Contracts\Cache\Lock
    {
        $store = $this->cache()->getStore();

        return $store instanceof LockProvider ? $store->lock($name, $seconds) : null;
    }

    private function cache(): Repository
    {
        return Cache::store();
    }
}
