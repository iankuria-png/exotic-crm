<?php

namespace App\Services\Scraper;

use App\Models\Client;
use App\Models\Platform;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Support\CityNormalizer;
use App\Support\ClientLifecycleState;

/**
 * "Where are we thin, and who has profiles there?" for one market.
 *
 * Our side uses the same definitions as Clients → Locations (ClientLocationController):
 * cities grouped by CityNormalizer::canonicalKey, Available = Active + Unlockable, where
 * Active is Client::isActiveProfile() and Unlockable is a published, expired profile with
 * a WordPress post. Competitor side uses each scraper site's latest finished run in the
 * market (all pages it read), or its latest check (first page only) when it never ran.
 */
class LocationSupplyService
{
    /**
     * @return array{sites: array<int, array>, rows: array<int, array>, totals: array}
     */
    public function forMarket(Platform $platform): array
    {
        $ours = $this->ourInventory($platform);
        $sites = $this->competitorSites($platform);

        // Pre-normalise every competitor location string once.
        $labels = [];
        foreach ($sites as $i => $site) {
            foreach ($site['location_counts'] as $entry) {
                $norm = LocationFilter::normalize($entry['label']);
                if ($norm !== '') {
                    $labels[] = ['site' => $i, 'padded' => ' '.$norm.' ', 'count' => (int) $entry['count']];
                }
            }
        }
        $match = function (string $place) use ($labels, $sites): array {
            $needle = ' '.LocationFilter::core($place).' ';
            $bySite = [];
            if (trim($needle) === '') {
                return ['total' => 0, 'by_site' => []];
            }
            foreach ($labels as $l) {
                if (str_contains($l['padded'], $needle)) {
                    $bySite[$l['site']] = ($bySite[$l['site']] ?? 0) + $l['count'];
                }
            }
            arsort($bySite);
            $out = [];
            foreach ($bySite as $i => $count) {
                $out[] = ['site_id' => $sites[$i]['id'], 'count' => $count];
            }

            return ['total' => array_sum($bySite), 'by_site' => $out];
        };

        $rows = [];
        $ourNorms = [];
        foreach ($ours as $city) {
            $ourNorms[] = LocationFilter::core($city['place']);
            $rows[] = ['place' => $city['place'], 'kind' => 'ours', 'ours' => $city['counts'], 'competitors' => $match($city['place'])];
        }

        // Places competitors list where we have no profiles at all.
        $weighted = [];
        foreach ($sites as $site) {
            foreach ($site['location_counts'] as $entry) {
                for ($k = 0; $k < min(50, (int) $entry['count']); $k++) {
                    $weighted[] = $entry['label'];
                }
            }
        }
        foreach (LocationFilter::facets($weighted, 60) as $facet) {
            $norm = LocationFilter::core($facet['label']);
            $covered = false;
            foreach ($ourNorms as $ourNorm) {
                if ($ourNorm === $norm || str_contains(' '.$ourNorm.' ', ' '.$norm.' ') || str_contains(' '.$norm.' ', ' '.$ourNorm.' ')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                // A region whose competitor listings mostly name areas we already have
                // ("Kilimani, Nairobi") is a parent of our places, not a gap.
                $within = 0;
                $total = 0;
                foreach ($labels as $l) {
                    if (! str_contains($l['padded'], ' '.$norm.' ')) {
                        continue;
                    }
                    $total += $l['count'];
                    foreach ($ourNorms as $ourNorm) {
                        if ($ourNorm !== '' && str_contains($l['padded'], ' '.$ourNorm.' ')) {
                            $within += $l['count'];
                            break;
                        }
                    }
                }
                $covered = $total > 0 && $within >= 0.3 * $total;
            }
            if (! $covered) {
                $rows[] = ['place' => $facet['label'], 'kind' => 'competitor_only', 'ours' => ['available' => 0, 'active' => 0, 'unlockable' => 0, 'clients' => 0], 'competitors' => $match($facet['label'])];
            }
        }

        usort($rows, fn ($a, $b) => [$a['ours']['available'], -$a['competitors']['total'], $a['place']] <=> [$b['ours']['available'], -$b['competitors']['total'], $b['place']]);

        return [
            'sites' => array_map(fn ($s) => array_diff_key($s, ['location_counts' => true]), $sites),
            'rows' => $rows,
            'totals' => [
                'our_places' => count($ours),
                'low_places' => count(array_filter($rows, fn ($r) => $r['kind'] === 'ours' && $r['ours']['available'] <= 5)),
                'competitor_only_places' => count(array_filter($rows, fn ($r) => $r['kind'] === 'competitor_only')),
                'our_available' => array_sum(array_map(fn ($c) => $c['counts']['available'], $ours)),
                'competitor_profiles_seen' => array_sum(array_column($sites, 'profiles_seen')),
            ],
        ];
    }

    /**
     * @return array<int, array{place: string, counts: array{available: int, active: int, unlockable: int, clients: int}}>
     */
    public function ourInventory(Platform $platform): array
    {
        $grouped = [];
        Client::query()
            ->where('platform_id', (int) $platform->id)
            ->notClosed()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select(['id', 'platform_id', 'city', 'profile_status', 'lifecycle_state', 'needs_payment', 'notactive', 'verified', 'wp_post_id'])
            ->chunkById(2000, function ($clients) use (&$grouped) {
                foreach ($clients as $client) {
                    if (! LocationFilter::isPlace($client->city)) {
                        continue;
                    }
                    $key = CityNormalizer::canonicalKey($client->city);
                    $label = CityNormalizer::normalizeLabel($client->city, 120);
                    if ($key === null || $label === null) {
                        continue;
                    }
                    $grouped[$key] ??= ['labels' => [], 'active' => 0, 'unlockable' => 0, 'clients' => 0];
                    $grouped[$key]['labels'][$label] = ($grouped[$key]['labels'][$label] ?? 0) + 1;
                    $grouped[$key]['clients']++;
                    if ($client->isActiveProfile()) {
                        $grouped[$key]['active']++;
                    }
                    if ((string) $client->profile_status === 'publish' && (int) $client->wp_post_id > 0 && $client->lifecycle_state === ClientLifecycleState::EXPIRED) {
                        $grouped[$key]['unlockable']++;
                    }
                }
            });

        $out = [];
        foreach ($grouped as $city) {
            arsort($city['labels']);
            $out[] = ['place' => (string) array_key_first($city['labels']), 'counts' => [
                'available' => $city['active'] + $city['unlockable'],
                'active' => $city['active'],
                'unlockable' => $city['unlockable'],
                'clients' => $city['clients'],
            ]];
        }

        return $out;
    }

    /**
     * @return array<int, array>
     */
    public function competitorSites(Platform $platform): array
    {
        $sources = ScraperSource::query()->where('platform_id', (int) $platform->id)->orderBy('name')->get();
        $latestRuns = ScraperRun::query()
            ->whereIn('scraper_source_id', $sources->pluck('id'))
            ->whereIn('status', ['success', 'partial', 'empty'])
            ->orderByDesc('id')
            ->get(['id', 'scraper_source_id', 'status', 'result', 'completed_at'])
            ->unique('scraper_source_id')
            ->keyBy('scraper_source_id');

        $out = [];
        foreach ($sources as $source) {
            $run = $latestRuns->get($source->id);
            $profile = (array) ($source->site_profile ?? []);
            $counts = (array) ($run?->result['location_counts'] ?? []);
            $basis = 'run';
            $asOf = optional($run?->completed_at)->toIso8601String();
            if ($counts === []) {
                $counts = (array) ($profile['location_counts'] ?? $profile['locations'] ?? []);
                $basis = $counts === [] ? 'none' : 'check';
                $asOf = optional($source->last_probed_at)->toIso8601String();
            }
            $running = ScraperRun::query()->where('scraper_source_id', $source->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subHour())->exists();
            $reason = match (true) {
                ! $source->is_active => 'Paused',
                ! $source->compliance_ack_robots || ! $source->compliance_ack_tos => 'Checks not confirmed',
                $running => 'Already running',
                in_array($source->health_status, ['blocked', 'error'], true) && $source->fetch_driver !== 'capture' => 'Blocked: '.($profile['headline'] ?? 'see site'),
                default => null,
            };
            $out[] = [
                'id' => (int) $source->id,
                'name' => (string) $source->name,
                'host' => \App\Services\Scraper\Fetch\UrlTools::host((string) $source->source_url),
                'health_status' => $source->health_status,
                'runnable' => $reason === null,
                'not_runnable_reason' => $reason,
                'basis' => $basis,
                'as_of' => $asOf,
                'profiles_seen' => array_sum(array_map(fn ($c) => (int) ($c['count'] ?? 0), $counts)),
                'location_counts' => array_values(array_filter($counts, fn ($c) => isset($c['label']))),
            ];
        }

        return $out;
    }
}
