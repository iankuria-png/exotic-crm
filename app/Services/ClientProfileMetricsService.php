<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientProfileMetric;
use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Per-client analytics snapshot for dynamic lifecycle copy ("your profile got
 * 145 views last week"). ONE cached bulk call per market via the exotic-crm-sync
 * plugin's /analytics/bulk endpoint — reminders read the snapshot, never live WP.
 *
 * Freshness rule: if a client's snapshot is older than FRESHNESS_DAYS (or the
 * views are zero), the stat variables are withheld so copy degrades to a
 * non-numeric hook — never "0 views", never a stale brag.
 */
class ClientProfileMetricsService
{
    public const FRESHNESS_DAYS = 8;
    public const PERIOD_DAYS = 7;

    /**
     * Fetch + upsert the snapshot for one market. Returns a summary; throws
     * nothing — a broken market must not block sibling markets.
     */
    public function snapshotPlatform(int $platformId): array
    {
        $from = now()->subDays(self::PERIOD_DAYS)->toDateString();
        $to = now()->toDateString();

        try {
            $wpSync = WpSyncService::forPlatform($platformId);
        } catch (\Throwable $exception) {
            return ['platform_id' => $platformId, 'status' => 'failed', 'error' => $exception->getMessage(), 'updated' => 0];
        }

        $perProfile = [];
        $page = 1;

        try {
            while (true) {
                $response = $wpSync->getAnalyticsBulk([
                    'from' => $from,
                    'to' => $to,
                    'page' => $page,
                    'per_page' => 200,
                ]);

                $rows = is_array($response['profiles'] ?? null) ? $response['profiles'] : [];
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $postId = (int) ($row['wp_post_id'] ?? $row['post_id'] ?? 0);
                    if ($postId <= 0) {
                        continue;
                    }

                    $contacts = $row['contacts'] ?? [];
                    $perProfile[$postId] = [
                        'views' => (int) ($row['views'] ?? 0),
                        'unique_views' => (int) ($row['unique'] ?? $row['unique_views'] ?? 0),
                        'contacts' => is_array($contacts)
                            ? (int) array_sum(array_map('intval', $contacts))
                            : (int) $contacts,
                        'engagement' => (int) ($row['engagement'] ?? 0),
                    ];
                }

                if (count($rows) < 200) {
                    break;
                }
                $page++;
            }
        } catch (\Throwable $exception) {
            Log::warning('Lifecycle metrics snapshot failed', [
                'platform_id' => $platformId,
                'error' => $exception->getMessage(),
            ]);

            return ['platform_id' => $platformId, 'status' => 'failed', 'error' => $exception->getMessage(), 'updated' => 0];
        }

        if ($perProfile === []) {
            return ['platform_id' => $platformId, 'status' => 'empty', 'updated' => 0];
        }

        $updated = 0;
        Client::query()
            ->where('platform_id', $platformId)
            ->whereIn('wp_post_id', array_keys($perProfile))
            ->select(['id', 'platform_id', 'wp_post_id'])
            ->chunkById(500, function ($clients) use ($perProfile, $from, $to, &$updated) {
                foreach ($clients as $client) {
                    $metrics = $perProfile[(int) $client->wp_post_id] ?? null;
                    if ($metrics === null) {
                        continue;
                    }

                    $existing = ClientProfileMetric::query()->where('client_id', (int) $client->id)->first();

                    ClientProfileMetric::query()->updateOrCreate(
                        ['client_id' => (int) $client->id],
                        [
                            'platform_id' => (int) $client->platform_id,
                            'wp_post_id' => (int) $client->wp_post_id,
                            'period_start' => $from,
                            'period_end' => $to,
                            'views' => $metrics['views'],
                            'unique_views' => $metrics['unique_views'],
                            'contacts' => $metrics['contacts'],
                            'engagement' => $metrics['engagement'],
                            'previous_views' => (int) ($existing->views ?? 0),
                            'captured_at' => now(),
                        ]
                    );
                    $updated++;
                }
            });

        return ['platform_id' => $platformId, 'status' => 'ok', 'updated' => $updated, 'profiles' => count($perProfile)];
    }

    /**
     * Template variables for one client, freshness-gated.
     *
     * Seeded templates use the phrase variable {{views_hook}} which ALWAYS has
     * a value; the raw numerics ({{profile_views_last_week}}, …) are provided
     * only when a fresh non-zero snapshot exists, so a template that leans on
     * them fails render (and surfaces in preview) instead of sending "0 views".
     */
    public function templateVariables(Client $client): array
    {
        $metric = ClientProfileMetric::query()
            ->where('client_id', (int) $client->id)
            ->first();

        $fresh = $metric
            && $metric->captured_at
            && $metric->captured_at->gte(now()->subDays(self::FRESHNESS_DAYS));

        $views = $fresh ? (int) $metric->views : 0;
        $contacts = $fresh ? (int) $metric->contacts : 0;

        $variables = [
            'views_hook' => $views > 0
                ? sprintf('your profile got %s view%s last week', number_format($views), $views === 1 ? '' : 's')
                : 'clients are searching right now',
            'contacts_hook' => $contacts > 0
                ? sprintf('%s client%s reached out to you last week', number_format($contacts), $contacts === 1 ? '' : 's')
                : 'clients are looking to get in touch',
            'profile_views_last_week' => $views > 0 ? number_format($views) : null,
            'contacts_last_week' => $contacts > 0 ? number_format($contacts) : null,
            'profile_views_trend' => null,
        ];

        if ($views > 0 && $metric && (int) $metric->previous_views > 0) {
            $variables['profile_views_trend'] = $views >= (int) $metric->previous_views ? 'up' : 'down';
        }

        return $variables;
    }

    /** Snapshot freshness summary per market, for the Lifecycle tab health strip. */
    public function freshnessForPlatform(int $platformId): array
    {
        $latest = ClientProfileMetric::query()
            ->where('platform_id', $platformId)
            ->max('captured_at');

        $count = ClientProfileMetric::query()
            ->where('platform_id', $platformId)
            ->count();

        return [
            'snapshot_count' => (int) $count,
            'last_captured_at' => $latest ? Carbon::parse($latest)->toIso8601String() : null,
            'is_fresh' => $latest ? Carbon::parse($latest)->gte(now()->subDays(self::FRESHNESS_DAYS)) : false,
        ];
    }
}
