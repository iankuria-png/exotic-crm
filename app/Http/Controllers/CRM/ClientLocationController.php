<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CityGeocode;
use App\Models\Client;
use App\Services\CityPerformanceService;
use App\Services\MarketAuthorizationService;
use App\Services\WpSyncService;
use App\Support\CityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClientLocationController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly CityPerformanceService $cityPerformanceService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this client market.'
        );

        if (!$selectedPlatformId) {
            $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

            if (is_array($accessiblePlatformIds) && count($accessiblePlatformIds) === 1) {
                $selectedPlatformId = (int) $accessiblePlatformIds[0];
            }
        }

        if (!$selectedPlatformId) {
            return response()->json([
                'message' => 'Select a market to view client locations.',
            ], 422);
        }

        $clients = Client::query()
            ->where('platform_id', (int) $selectedPlatformId)
            ->notClosed()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->get([
                'id',
                'platform_id',
                'city',
                'profile_status',
                'needs_payment',
                'notactive',
                'verified',
                'wp_post_id',
            ]);

        $grouped = $this->groupClients($clients);
        $analyticsStatus = 'ok';
        $analyticsByPostId = [];
        $marketAverage = null;

        if ($grouped->isNotEmpty()) {
            try {
                [$analyticsByPostId, $analyticsStatus, $marketAverage] = $this->loadAnalytics(
                    (int) $selectedPlatformId,
                    $validated['from'] ?? null,
                    $validated['to'] ?? null
                );
            } catch (\Throwable) {
                $analyticsStatus = 'unavailable';
            }
        }

        $canonicalKeys = $grouped->keys()->all();
        $geocodes = CityGeocode::query()
            ->where('platform_id', (int) $selectedPlatformId)
            ->whereIn('canonical_key', $canonicalKeys)
            ->get()
            ->keyBy('canonical_key');

        $locations = [];
        $ungeocoded = [];
        $mappedClientCount = 0;
        $totalViews = $analyticsStatus === 'unavailable' ? null : 0;
        $totalContactActions = $analyticsStatus === 'ok' ? 0 : null;

        foreach ($grouped as $canonicalKey => $city) {
            $aggregate = $this->aggregateEngagement(
                $city['wp_post_ids'],
                $analyticsByPostId,
                $analyticsStatus
            );
            $geocode = $geocodes->get($canonicalKey);
            $mapped = $geocode !== null
                && $geocode->latitude !== null
                && $geocode->longitude !== null;

            if (!$mapped) {
                $ungeocoded[] = $city['display_city'];
            } else {
                $mappedClientCount += $city['client_count'];
            }

            if ($totalViews !== null && $aggregate['engagement'] !== null) {
                $totalViews += (int) ($aggregate['engagement']['views'] ?? 0);
            }

            if ($totalContactActions !== null && $aggregate['engagement'] !== null) {
                $totalContactActions += (int) ($aggregate['engagement']['contact_actions'] ?? 0);
            }

            $locations[] = [
                'canonical_key' => $canonicalKey,
                'display_city' => $city['display_city'],
                'latitude' => $mapped ? (float) $geocode->latitude : null,
                'longitude' => $mapped ? (float) $geocode->longitude : null,
                'mapped' => $mapped,
                'client_count' => $city['client_count'],
                'active_count' => $city['active_count'],
                'verified_count' => $city['verified_count'],
                'published_count' => $aggregate['published_count'],
                'engagement' => $aggregate['engagement'],
                'channels' => $aggregate['channels'],
                'top_channel' => $aggregate['top_channel'],
            ];
        }

        usort($locations, static function (array $left, array $right): int {
            return $right['client_count'] <=> $left['client_count']
                ?: strcmp((string) $left['display_city'], (string) $right['display_city']);
        });

        if ($analyticsStatus === 'unavailable') {
            $locations = array_map(function (array $location): array {
                $location['performance'] = [
                    'index' => null,
                    'band' => 'unavailable',
                ];

                return $location;
            }, $locations);
        } else {
            $scored = $this->cityPerformanceService->score(array_map(function (array $location): array {
                return [
                    'client_count' => $location['client_count'],
                    'views' => (int) data_get($location, 'engagement.views', 0),
                    'contact_rate' => (float) data_get($location, 'engagement.contact_rate', 0),
                ];
            }, $locations));

            foreach ($locations as $index => $location) {
                $locations[$index]['performance'] = $scored[$index]['performance'];
            }
        }

        $marketAveragePayload = null;
        if ($analyticsStatus !== 'unavailable' && is_array($marketAverage)) {
            $marketAveragePayload = [
                'views' => (float) ($marketAverage['views'] ?? 0),
                'contact_rate' => (float) ($marketAverage['contact_rate'] ?? 0),
            ];
        }

        return response()->json([
            'period' => [
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'platform_id' => (int) $selectedPlatformId,
            'analytics_status' => $analyticsStatus,
            'locations' => $locations,
            'ungeocoded' => array_values(array_unique($ungeocoded)),
            'totals' => [
                'located_client_count' => (int) $grouped->sum('client_count'),
                'mapped_client_count' => $mappedClientCount,
                'views' => $totalViews,
                'contact_actions' => $totalContactActions,
            ],
            'market_average' => $marketAveragePayload,
        ]);
    }

    private function groupClients(Collection $clients): Collection
    {
        $grouped = [];

        foreach ($clients as $client) {
            $canonicalKey = CityNormalizer::canonicalKey($client->city);
            $displayCity = CityNormalizer::normalizeLabel($client->city, 120);

            if ($canonicalKey === null || $displayCity === null) {
                continue;
            }

            if (!isset($grouped[$canonicalKey])) {
                $grouped[$canonicalKey] = [
                    'display_counts' => [],
                    'client_count' => 0,
                    'active_count' => 0,
                    'verified_count' => 0,
                    'wp_post_ids' => [],
                ];
            }

            $grouped[$canonicalKey]['client_count']++;
            $grouped[$canonicalKey]['display_counts'][$displayCity] = ($grouped[$canonicalKey]['display_counts'][$displayCity] ?? 0) + 1;

            if ($client->isActiveProfile()) {
                $grouped[$canonicalKey]['active_count']++;
            }

            if ((bool) $client->verified) {
                $grouped[$canonicalKey]['verified_count']++;
            }

            $wpPostId = (int) ($client->wp_post_id ?? 0);
            if ($wpPostId > 0) {
                $grouped[$canonicalKey]['wp_post_ids'][$wpPostId] = $wpPostId;
            }
        }

        return collect($grouped)->map(function (array $city): array {
            arsort($city['display_counts']);

            return [
                'display_city' => (string) array_key_first($city['display_counts']),
                'client_count' => $city['client_count'],
                'active_count' => $city['active_count'],
                'verified_count' => $city['verified_count'],
                'wp_post_ids' => array_values($city['wp_post_ids']),
            ];
        });
    }

    private function loadAnalytics(int $platformId, ?string $from, ?string $to): array
    {
        $payloadProfiles = [];
        $analyticsStatus = 'ok';
        $marketAverage = null;
        $page = 1;
        $totalPages = 1;

        do {
            $payload = WpSyncService::forPlatform($platformId)->getAnalyticsBulk(array_filter([
                'status' => 'publish',
                'from' => $from,
                'to' => $to,
                'per_page' => 500,
                'page' => $page,
            ], fn ($value) => $value !== null && $value !== ''));

            $profiles = is_array($payload['profiles'] ?? null) ? $payload['profiles'] : [];
            $payloadProfiles = array_merge($payloadProfiles, $profiles);
            $marketAverage = is_array($payload['market_averages'] ?? null) ? $payload['market_averages'] : $marketAverage;
            $totalPages = max(1, (int) ($payload['total_pages'] ?? 1));

            foreach ($profiles as $profile) {
                if (!array_key_exists('contacts', $profile)) {
                    $analyticsStatus = 'partial';
                    break;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        $analyticsByPostId = [];

        foreach ($payloadProfiles as $profile) {
            $postId = (int) ($profile['wp_post_id'] ?? 0);
            if ($postId <= 0) {
                continue;
            }

            $analyticsByPostId[$postId] = [
                'views' => (int) ($profile['views'] ?? 0),
                'unique' => $analyticsStatus === 'ok' ? (int) ($profile['unique'] ?? 0) : null,
                'contact_rate' => (float) ($profile['contact_rate'] ?? 0),
                'channels' => $analyticsStatus === 'ok'
                    ? [
                        'phone' => (int) data_get($profile, 'contacts.phone', 0),
                        'whatsapp' => (int) data_get($profile, 'contacts.whatsapp', 0),
                        'viber' => (int) data_get($profile, 'contacts.viber', 0),
                    ]
                    : null,
            ];
        }

        return [$analyticsByPostId, $analyticsStatus, $marketAverage];
    }

    private function aggregateEngagement(array $wpPostIds, array $analyticsByPostId, string $analyticsStatus): array
    {
        if ($analyticsStatus === 'unavailable') {
            return [
                'engagement' => null,
                'channels' => null,
                'top_channel' => null,
                'published_count' => 0,
            ];
        }

        $views = 0;
        $publishedCount = 0;
        $profileUniqueVisits = $analyticsStatus === 'ok' ? 0 : null;
        $channels = $analyticsStatus === 'ok' ? ['phone' => 0, 'whatsapp' => 0, 'viber' => 0] : null;
        $weightedRateNumerator = 0.0;
        $weightedRateDenominator = 0;

        foreach ($wpPostIds as $wpPostId) {
            $analytics = $analyticsByPostId[(int) $wpPostId] ?? null;
            if (!is_array($analytics)) {
                continue;
            }

            $publishedCount++;
            $views += (int) ($analytics['views'] ?? 0);

            if ($analyticsStatus === 'ok') {
                $profileUniqueVisits += (int) ($analytics['unique'] ?? 0);
                foreach (['phone', 'whatsapp', 'viber'] as $channel) {
                    $channels[$channel] += (int) data_get($analytics, "channels.$channel", 0);
                }
            } else {
                $weightedRateNumerator += (float) ($analytics['contact_rate'] ?? 0) * (int) ($analytics['views'] ?? 0);
                $weightedRateDenominator += (int) ($analytics['views'] ?? 0);
            }
        }

        $contactActions = null;
        $contactRate = 0.0;
        $topChannel = null;

        if ($analyticsStatus === 'ok') {
            $contactActions = array_sum($channels);
            $contactRate = $views > 0 ? round(($contactActions / $views) * 100, 2) : 0.0;
            $topChannel = $this->resolveTopChannel($channels);
        } elseif ($weightedRateDenominator > 0) {
            $contactRate = round($weightedRateNumerator / $weightedRateDenominator, 2);
        }

        return [
            'engagement' => [
                'views' => $views,
                'profile_unique_visits' => $profileUniqueVisits,
                'contact_actions' => $contactActions,
                'contact_rate' => $contactRate,
            ],
            'channels' => $channels,
            'top_channel' => $topChannel,
            'published_count' => $publishedCount,
        ];
    }

    private function resolveTopChannel(array $channels): ?string
    {
        $max = max($channels);
        if ($max <= 0) {
            return null;
        }

        $leaders = array_keys(array_filter($channels, static fn (int $count): bool => $count === $max));

        return count($leaders) === 1 ? $leaders[0] : null;
    }
}
