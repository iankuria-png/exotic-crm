<?php

namespace App\Services\Scraper;

/**
 * Location filtering and suggestions for scraped profiles. Matching is case- and
 * accent-insensitive and works on whole words, so "Kilimani" matches
 * "Kilimani Nairobi in Yaya Centre" and "South B" matches "South B Nairobi".
 */
class LocationFilter
{
    private const STOPWORDS = [
        'in', 'at', 'of', 'the', 'and', 'near', 'along', 'off', 'town', 'city', 'kenya', 'estate', 'area',
        'road', 'rd', 'street', 'st', 'drive', 'avenue', 'ave', 'centre', 'center', 'mall', 'stage', 'county',
        'escort', 'escorts', 'from', 'based',
    ];

    /** @var array<int, string> */
    private array $needles;

    /**
     * @param  array<int, string>  $locations
     */
    public function __construct(array $locations, private readonly bool $includeUnknown = false)
    {
        $this->needles = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $locations))));
    }

    public static function fromSettings(?array $filters): self
    {
        return new self((array) ($filters['locations'] ?? []), (bool) ($filters['include_unknown_location'] ?? false));
    }

    public function active(): bool
    {
        return $this->needles !== [];
    }

    public function matches(?string $city): bool
    {
        if (! $this->active()) {
            return true;
        }
        $haystack = self::normalize($city);
        if ($haystack === '') {
            return $this->includeUnknown;
        }
        $padded = ' '.$haystack.' ';
        foreach ($this->needles as $needle) {
            if (str_contains($padded, ' '.$needle.' ')) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_D);
            $value = (string) preg_replace('/\p{Mn}+/u', '', $value);
        }
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Suggested places with how many profiles mention them, most common first.
     * Words that almost always appear together ("Ongata Rongai", "Uasin Gishu") stay joined.
     *
     * @param  array<int, ?string>  $cities
     * @return array<int, array{label: string, count: int}>
     */
    public static function facets(array $cities, int $limit = 30): array
    {
        $uni = [];
        $bi = [];
        $labels = [];
        foreach ($cities as $city) {
            $raw = trim((string) $city);
            if ($raw === '') {
                continue;
            }
            $seenU = [];
            $seenB = [];
            // Commas, "in", dashes and slashes separate places; pairs never span them.
            foreach (preg_split('/\s*(?:,|;|\||\/|\s-\s|\bin\b|\bnear\b|\bat\b)\s*/iu', $raw) ?: [] as $segment) {
                preg_match_all('/[\p{L}\p{N}]+/u', $segment, $m);
                $words = $m[0];
                foreach ($words as $i => $word) {
                    $key = self::normalize($word);
                    if ($key !== '' && ! in_array($key, self::STOPWORDS, true) && ! ctype_digit($key)) {
                        if (! isset($seenU[$key])) {
                            $uni[$key] = ($uni[$key] ?? 0) + 1;
                            $seenU[$key] = true;
                        }
                        $labels[$key] ??= self::title($word);
                    }
                    if (isset($words[$i + 1])) {
                        $next = self::normalize($words[$i + 1]);
                        if ($key !== '' && $next !== '' && ! in_array($key, self::STOPWORDS, true) && ! in_array($next, self::STOPWORDS, true)) {
                            $bkey = $key.' '.$next;
                            if (! isset($seenB[$bkey])) {
                                $bi[$bkey] = ($bi[$bkey] ?? 0) + 1;
                                $seenB[$bkey] = true;
                            }
                            $labels[$bkey] ??= self::title($word).' '.self::title($words[$i + 1]);
                        }
                    }
                }
            }
        }

        // Keep a two-word name only when both words almost always appear together.
        $pairs = [];
        foreach ($bi as $bkey => $count) {
            [$a, $b] = explode(' ', $bkey, 2);
            if ($a !== $b && ($count >= 2 || mb_strlen($a) < 3 || mb_strlen($b) < 3) && $count >= 0.8 * ($uni[$a] ?? PHP_INT_MAX) && $count >= 0.8 * ($uni[$b] ?? PHP_INT_MAX)) {
                $pairs[$bkey] = [$a, $b, $count];
            }
        }
        // In a chain like "Eldoret Uasin" + "Uasin Gishu", keep the later pair and the lone first word.
        foreach ($pairs as $x => [$xa, $xb]) {
            foreach ($pairs as $y => [$ya]) {
                if ($x !== $y && $xb === $ya) {
                    unset($pairs[$x]);
                    break;
                }
            }
        }
        $terms = [];
        $absorbed = [];
        foreach ($pairs as $bkey => [$a, $b, $count]) {
            $terms[$bkey] = $count;
            $absorbed[$a] = true;
            $absorbed[$b] = true;
        }
        foreach ($uni as $key => $count) {
            if (! isset($absorbed[$key]) && mb_strlen($key) >= 3) {
                $terms[$key] = $count;
            }
        }

        arsort($terms);
        $out = [];
        foreach (array_slice($terms, 0, $limit, true) as $key => $count) {
            $out[] = ['label' => $labels[$key] ?? $key, 'count' => (int) $count];
        }

        return $out;
    }

    private static function title(string $word): string
    {
        return mb_strlen($word) <= 2 ? mb_strtoupper($word) : mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
    }
}
