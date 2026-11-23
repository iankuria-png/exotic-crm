<?php

namespace App\Services\Scraper;

/**
 * Normalises and clamps per-site crawl settings against config ceilings.
 */
class CrawlSettings
{
    public const ROUTES = ['listing', 'sitemap'];

    public static function normalize(?array $input): array
    {
        $input = $input ?? [];
        $defaults = (array) config('scraper.defaults');
        $limits = (array) config('scraper.limits');
        $clamp = fn ($v, $min, $max) => max($min, min($max, (int) $v));

        $headers = [];
        foreach ((array) ($input['headers'] ?? []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && preg_match('/^[A-Za-z0-9-]{1,60}$/', $name) && ! in_array(strtolower($name), ['host', 'user-agent', 'content-length', 'cookie'], true)) {
                $headers[] = ['name' => $name, 'value' => mb_substr((string) ($row['value'] ?? ''), 0, 500)];
            }
        }
        $cookies = [];
        foreach ((array) ($input['cookies'] ?? []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && preg_match('/^[A-Za-z0-9_\-.]{1,80}$/', $name)) {
                $cookies[] = ['name' => $name, 'value' => mb_substr((string) ($row['value'] ?? ''), 0, 500)];
            }
        }

        $pagination = [];
        foreach (['next_selector', 'pattern'] as $key) {
            $value = trim((string) ($input['pagination'][$key] ?? ''));
            if ($value !== '') {
                $pagination[$key] = mb_substr($value, 0, 255);
            }
        }
        $profileRules = [];
        foreach (['phone_selector', 'whatsapp_selector', 'name_selector', 'city_selector', 'age_selector'] as $key) {
            $value = trim((string) ($input['profile_rules'][$key] ?? ''));
            if ($value !== '') {
                $profileRules[$key] = mb_substr($value, 0, 255);
            }
        }

        $pattern = trim((string) ($input['profile_url_pattern'] ?? ''));
        if ($pattern !== '' && @preg_match($pattern, '') === false) {
            $pattern = '';
        }

        $locations = self::locations($input['locations'] ?? []);

        return [
            'route' => in_array($input['route'] ?? 'listing', self::ROUTES, true) ? (string) ($input['route'] ?? 'listing') : 'listing',
            'max_pages' => $clamp($input['max_pages'] ?? $defaults['max_pages'] ?? 5, 1, $limits['max_pages'] ?? 25),
            'max_profiles' => $clamp($input['max_profiles'] ?? $defaults['max_profiles'] ?? 0, 0, $limits['max_profiles'] ?? 150),
            'delay_ms' => $clamp($input['delay_ms'] ?? $defaults['delay_ms'] ?? 1500, $limits['min_delay_ms'] ?? 500, $limits['max_delay_ms'] ?? 15000),
            'follow_profiles' => (bool) ($input['follow_profiles'] ?? $defaults['follow_profiles'] ?? false),
            'follow_only_missing_phone' => (bool) ($input['follow_only_missing_phone'] ?? true),
            'stop_when_no_new' => (bool) ($input['stop_when_no_new'] ?? $defaults['stop_when_no_new'] ?? true),
            'pagination' => $pagination,
            'profile_rules' => $profileRules,
            'profile_url_pattern' => $pattern !== '' ? $pattern : null,
            'headers' => $headers,
            'cookies' => $cookies,
            'locations' => $locations,
            'include_unknown_location' => (bool) ($input['include_unknown_location'] ?? false),
        ];
    }

    /**
     * Up to 20 distinct place names, trimmed to 80 characters.
     *
     * @return array<int, string>
     */
    public static function locations(mixed $input): array
    {
        $out = [];
        foreach ((array) $input as $value) {
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
            if ($value === '' || mb_strlen($value) > 80) {
                continue;
            }
            $key = LocationFilter::normalize($value);
            if ($key !== '' && ! isset($out[$key])) {
                $out[$key] = $value;
            }
        }

        return array_slice(array_values($out), 0, 20);
    }

    public static function headerMap(array $settings): array
    {
        $out = [];
        foreach ((array) ($settings['headers'] ?? []) as $row) {
            $out[$row['name']] = $row['value'];
        }

        return $out;
    }
}
