<?php

namespace App\Services\Scraper;

use App\Models\Platform;
use App\Services\WpSyncService;

/**
 * Turns a scraped place ("Kilimani Nairobi in Yaya Centre 💋", "Nakuru town") into one of the
 * market's real WordPress locations (region → city term), loosely:
 *   exact city name → city named inside the text (most specific wins) → near spelling →
 *   region only (when that region has no child cities).
 * Each option carries how many of our profiles are already there, so thin cities are visible.
 */
class PlacementMatcher
{
    public function __construct(private readonly LocationSupplyService $supply) {}

    /**
     * @return array<int, array{region_id: int, region: string, city_id: ?int, city: ?string, label: string, our_available: int}>
     */
    public function options(Platform $platform): array
    {
        $raw = WpSyncService::forPlatform((int) $platform->id)->getLocations();
        $regions = $raw['locations'] ?? $raw;

        $ours = [];
        foreach ($this->supply->ourInventory($platform) as $city) {
            $ours[LocationFilter::core($city['place'])] = ($ours[LocationFilter::core($city['place'])] ?? 0) + $city['counts']['available'];
        }

        $options = [];
        foreach ((array) $regions as $region) {
            $regionId = (int) ($region['id'] ?? 0);
            $regionName = trim((string) ($region['name'] ?? ''));
            if ($regionId <= 0 || $regionName === '') {
                continue;
            }
            $cities = (array) ($region['cities'] ?? []);
            if ($cities === []) {
                $options[] = ['region_id' => $regionId, 'region' => $regionName, 'city_id' => null, 'city' => null, 'label' => $regionName,
                    'our_available' => (int) ($ours[LocationFilter::core($regionName)] ?? 0)];

                continue;
            }
            foreach ($cities as $city) {
                $cityId = (int) ($city['id'] ?? 0);
                $cityName = trim((string) ($city['name'] ?? ''));
                if ($cityId <= 0 || $cityName === '') {
                    continue;
                }
                $options[] = ['region_id' => $regionId, 'region' => $regionName, 'city_id' => $cityId, 'city' => $cityName,
                    'label' => $cityName.' · '.$regionName, 'our_available' => (int) ($ours[LocationFilter::core($cityName)] ?? 0)];
            }
        }
        usort($options, fn ($a, $b) => [$a['our_available'], $a['label']] <=> [$b['our_available'], $b['label']]);

        return $options;
    }

    /**
     * Clean a scraped place for matching and display: drop emoji/symbols, phone-ish digits,
     * marketing words and collapse spaces.
     */
    public static function sanitize(?string $place): string
    {
        $place = html_entity_decode((string) $place, ENT_QUOTES | ENT_HTML5);
        $place = (string) preg_replace('/[^\p{L}\p{N}\s,\-\'’.]/u', ' ', $place);
        $place = (string) preg_replace('/\b\d{5,}\b/u', ' ', $place);
        $place = (string) preg_replace('/\b(escorts?|call ?girls?|massage|hot|sexy|vip)\b/iu', ' ', $place);

        return trim((string) preg_replace('/\s+/u', ' ', $place), ' ,.-');
    }

    /**
     * @param  array<int, array>  $options
     * @return array{place: string, clean: string, match: ?array, confidence: string, alternatives: array<int, array>}
     */
    public function match(?string $place, array $options): array
    {
        $clean = self::sanitize($place);
        $text = ' '.LocationFilter::normalize($clean).' ';
        $best = null;
        $scored = [];

        foreach ($options as $option) {
            $cityCore = $option['city'] !== null ? LocationFilter::core($option['city']) : null;
            $regionCore = LocationFilter::core($option['region']);
            $score = 0;
            $why = null;

            if ($cityCore !== null && $cityCore !== '') {
                if (trim($text) === $cityCore || LocationFilter::core($clean) === $cityCore) {
                    [$score, $why] = [100, 'exact'];
                } elseif (str_contains($text, ' '.$cityCore.' ')) {
                    // Longer (more specific) names beat short ones; a matching region adds a little.
                    $score = 70 + min(15, mb_strlen($cityCore) / 2) + (str_contains($text, ' '.$regionCore.' ') ? 5 : 0);
                    $why = 'contained';
                } else {
                    $near = $this->nearSpelling($cityCore, $text);
                    if ($near >= 0.84) {
                        [$score, $why] = [(int) round(55 + 20 * $near), 'similar'];
                    }
                }
            } elseif ($regionCore !== '' && str_contains($text, ' '.$regionCore.' ')) {
                [$score, $why] = [60, 'region'];
            }

            if ($score > 0) {
                $scored[] = $option + ['score' => $score, 'why' => $why];
            }
        }

        // Only a region named (e.g. "Nairobi"): place in that region's thinnest city.
        if ($scored === []) {
            foreach ($options as $option) {
                if ($option['city'] !== null && str_contains($text, ' '.LocationFilter::core($option['region']).' ')) {
                    $scored[] = $option + ['score' => 50 - min(40, $option['our_available']) / 100, 'why' => 'region_thinnest'];
                }
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['our_available']] <=> [$a['score'], $b['our_available']]);
        $best = $scored[0] ?? null;
        $confidence = match (true) {
            $best === null => 'none',
            $best['why'] === 'exact' => 'exact',
            in_array($best['why'], ['contained', 'region'], true) => 'close',
            $best['why'] === 'region_thinnest' => 'region',
            default => 'guess',
        };

        return [
            'place' => (string) $place,
            'clean' => $clean,
            'match' => $best ? array_diff_key($best, ['score' => true]) : null,
            'confidence' => $confidence,
            'alternatives' => array_map(fn ($o) => array_diff_key($o, ['score' => true]), array_slice($scored, 1, 3)),
        ];
    }

    /**
     * Best similarity (0–1) between a city name and any same-length run of words in the text.
     */
    private function nearSpelling(string $cityCore, string $text): float
    {
        $words = array_values(array_filter(explode(' ', trim($text))));
        $n = max(1, count(explode(' ', $cityCore)));
        $best = 0.0;
        for ($i = 0; $i + $n <= count($words); $i++) {
            $chunk = implode(' ', array_slice($words, $i, $n));
            if (abs(mb_strlen($chunk) - mb_strlen($cityCore)) > 3 || mb_strlen($cityCore) < 4) {
                continue;
            }
            similar_text($cityCore, $chunk, $percent);
            $best = max($best, $percent / 100);
        }

        return $best;
    }
}
