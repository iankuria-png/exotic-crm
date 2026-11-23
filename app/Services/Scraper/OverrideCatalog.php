<?php

namespace App\Services\Scraper;

/**
 * Ways staff can get past a blocker without defeating a site's bot protection.
 * Automatic challenge/CAPTCHA solving, stealth fingerprints and proxy rotation are
 * deliberately absent.
 */
class OverrideCatalog
{
    public const OPTIONS = [
        'browser_capture' => [
            'label' => 'Capture from your browser',
            'detail' => 'Open the page yourself, pass any check as a person, then one click sends the page to the CRM.',
            'kind' => 'capture',
        ],
        'upload_html' => [
            'label' => 'Upload or paste the page',
            'detail' => 'Save the page from your browser (or copy its source) and give it to the CRM to extract.',
            'kind' => 'capture',
        ],
        'render_worker' => [
            'label' => 'Render with the browser worker',
            'detail' => 'Load the page in the headless browser worker so JavaScript-built listings appear.',
            'kind' => 'driver',
        ],
        'sitemap_route' => [
            'label' => 'Use the sitemap',
            'detail' => 'Collect profile pages from the site\'s sitemap instead of the listing page.',
            'kind' => 'route',
        ],
        'follow_profiles' => [
            'label' => 'Open each profile',
            'detail' => 'Visit every profile link to read the phone number from the profile page.',
            'kind' => 'setting',
        ],
        'slow_down' => [
            'label' => 'Slow down and retry later',
            'detail' => 'Raise the delay between pages and run again later.',
            'kind' => 'setting',
        ],
        'custom_headers' => [
            'label' => 'Send a consent cookie or header',
            'detail' => 'Add the cookie the site sets when a visitor confirms the age notice, or a language header.',
            'kind' => 'setting',
        ],
        'manual_selectors' => [
            'label' => 'Set the selectors',
            'detail' => 'Point the scraper at the listing cards, name, phone and link elements.',
            'kind' => 'setting',
        ],
        'check_url' => [
            'label' => 'Fix the address',
            'detail' => 'Use the listing page address that shows profiles for this market.',
            'kind' => 'setting',
        ],
    ];

    /**
     * @param array<int, string> $keys
     * @param array<string, bool> $available
     */
    public function describe(array $keys, array $available = []): array
    {
        $out = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if (!isset(self::OPTIONS[$key])) {
                continue;
            }
            $out[] = ['key' => $key] + self::OPTIONS[$key] + ['available' => $available[$key] ?? true];
        }

        return $out;
    }
}
