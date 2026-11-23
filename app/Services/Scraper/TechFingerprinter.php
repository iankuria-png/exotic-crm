<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\FetchResult;

/**
 * Identifies a site's stack from headers, cookies and markup. Each hit carries the
 * evidence that triggered it so staff can see why the CRM thinks so.
 */
class TechFingerprinter
{
    /**
     * @return array<int, array{key: string, name: string, category: string, version: ?string, evidence: string}>
     */
    public function detect(FetchResult $result, ?HtmlDocument $doc = null): array
    {
        $html = $result->html;
        $headers = $result->headers;
        $cookies = strtolower($headers['set-cookie'] ?? '');
        $server = strtolower($headers['server'] ?? '');
        $found = [];

        $add = function (string $key, string $name, string $category, string $evidence, ?string $version = null) use (&$found): void {
            if (!isset($found[$key])) {
                $found[$key] = compact('key', 'name', 'category', 'version', 'evidence');
            }
        };

        // CDN / WAF
        if (str_contains($server, 'cloudflare') || isset($headers['cf-ray'])) {
            $add('cloudflare', 'Cloudflare', 'cdn_waf', 'cf-ray / server header');
        }
        if (isset($headers['x-sucuri-id']) || str_contains($server, 'sucuri')) {
            $add('sucuri', 'Sucuri WAF', 'cdn_waf', 'x-sucuri-id header');
        }
        if (isset($headers['x-akamai-transformed']) || str_contains($server, 'akamai')) {
            $add('akamai', 'Akamai', 'cdn_waf', 'akamai header');
        }
        if (isset($headers['x-amz-cf-id'])) {
            $add('cloudfront', 'Amazon CloudFront', 'cdn_waf', 'x-amz-cf-id header');
        }
        if (isset($headers['x-served-by']) && str_contains(strtolower($headers['x-served-by']), 'cache-')) {
            $add('fastly', 'Fastly', 'cdn_waf', 'x-served-by header');
        }
        if (str_contains($html, 'ddos-guard') || str_contains($server, 'ddos-guard')) {
            $add('ddos_guard', 'DDoS-Guard', 'cdn_waf', 'ddos-guard marker');
        }

        // Servers / hosting
        if (str_contains($server, 'litespeed') || isset($headers['x-litespeed-cache'])) {
            $add('litespeed', 'LiteSpeed', 'server', 'server header');
        } elseif (str_contains($server, 'nginx')) {
            $add('nginx', 'nginx', 'server', 'server header');
        } elseif (str_contains($server, 'apache')) {
            $add('apache', 'Apache', 'server', 'server header');
        }
        if (isset($headers['x-powered-by']) && preg_match('/php\/?([\d.]+)?/i', $headers['x-powered-by'], $m)) {
            $add('php', 'PHP', 'language', 'x-powered-by header', $m[1] ?? null);
        }

        // CMS
        $isWordPress = str_contains($html, '/wp-content/') || str_contains($html, '/wp-includes/') || str_contains($html, 'wp-json');
        if ($isWordPress) {
            $version = null;
            foreach ($doc?->metaContent('generator') ?? [] as $generator) {
                if (preg_match('/WordPress\s*([\d.]+)/i', $generator, $m)) {
                    $version = $m[1];
                }
            }
            $add('wordpress', 'WordPress', 'cms', 'wp-content / wp-json paths', $version);
            if (preg_match_all('#/wp-content/themes/([A-Za-z0-9_-]+)/#', $html, $m)) {
                foreach (array_unique($m[1]) as $theme) {
                    $add('theme:' . strtolower($theme), 'Theme: ' . $theme, 'theme', 'wp-content/themes path');
                }
            }
            if (preg_match_all('#/wp-content/plugins/([A-Za-z0-9_-]+)/#', $html, $m)) {
                foreach (array_slice(array_unique($m[1]), 0, 8) as $plugin) {
                    $add('plugin:' . strtolower($plugin), 'Plugin: ' . $plugin, 'plugin', 'wp-content/plugins path');
                }
            }
        }
        foreach ($doc?->metaContent('generator') ?? [] as $generator) {
            if (preg_match('/WP Rocket\s*([\d.]+)?/i', $generator, $m)) {
                $add('wp_rocket', 'WP Rocket', 'cache', 'generator meta', $m[1] ?? null);
            } elseif (preg_match('/Site Kit by Google/i', $generator)) {
                $add('site_kit', 'Google Site Kit', 'analytics', 'generator meta');
            } elseif (preg_match('/Joomla/i', $generator)) {
                $add('joomla', 'Joomla', 'cms', 'generator meta');
            } elseif (preg_match('/Drupal\s*([\d.]+)?/i', $generator, $m)) {
                $add('drupal', 'Drupal', 'cms', 'generator meta', $m[1] ?? null);
            } elseif (preg_match('/Wix\.com/i', $generator)) {
                $add('wix', 'Wix', 'cms', 'generator meta');
            }
        }
        if (str_contains($html, 'cdn.shopify.com')) {
            $add('shopify', 'Shopify', 'cms', 'cdn.shopify.com assets');
        }
        if (str_contains($html, 'static.squarespace.com') || str_contains($html, 'squarespace-cdn')) {
            $add('squarespace', 'Squarespace', 'cms', 'squarespace assets');
        }

        // Frameworks
        if (preg_match('/(xsrf-token=)/', $cookies) && preg_match('/[a-z0-9_]+_session=/', $cookies)) {
            $add('laravel', 'Laravel', 'framework', 'XSRF-TOKEN + *_session cookies');
        }
        if (str_contains($html, 'wire:') || str_contains($html, 'livewire')) {
            $add('livewire', 'Livewire', 'framework', 'livewire markup');
        }
        if (str_contains($html, 'data-page=') && str_contains($html, '"component"')) {
            $add('inertia', 'Inertia.js', 'framework', 'data-page payload');
        }
        if (str_contains($html, '__NEXT_DATA__') || str_contains($html, '/_next/static/')) {
            $add('nextjs', 'Next.js', 'framework', '__NEXT_DATA__ / _next assets');
        }
        if (str_contains($html, '__NUXT__') || str_contains($html, '/_nuxt/')) {
            $add('nuxt', 'Nuxt', 'framework', '__NUXT__ / _nuxt assets');
        }
        if (preg_match('/data-reactroot|react(-dom)?(\.production)?(\.min)?\.js/i', $html)) {
            $add('react', 'React', 'js_library', 'react markers');
        }
        if (preg_match('/vue(\.runtime)?(\.global)?(\.prod)?(\.min)?\.js|data-v-[0-9a-f]{6,}/i', $html)) {
            $add('vue', 'Vue', 'js_library', 'vue markers');
        }
        if (preg_match('/ng-version="([\d.]+)"/', $html, $m)) {
            $add('angular', 'Angular', 'framework', 'ng-version attribute', $m[1]);
        }
        if (preg_match('/jquery[.-]?([\d.]+)?(\.min)?\.js/i', $html, $m)) {
            $add('jquery', 'jQuery', 'js_library', 'jquery script', isset($m[1]) && $m[1] !== '' ? rtrim($m[1], '.') : null);
        }
        if (preg_match('/bootstrap(\.bundle)?(\.min)?\.(css|js)/i', $html)) {
            $add('bootstrap', 'Bootstrap', 'ui', 'bootstrap asset');
        }
        if (preg_match('/tailwind|class="[^"]*\b(?:flex|grid) [^"]*\b(?:px|py|mx|my)-\d/i', $html)) {
            $add('tailwind', 'Tailwind CSS', 'ui', 'utility classes');
        }

        // Analytics / bot management hints
        if (str_contains($html, 'googletagmanager.com') || str_contains($html, 'gtag(')) {
            $add('google_tag', 'Google Tag / Analytics', 'analytics', 'gtag script');
        }
        if (str_contains($html, 'challenges.cloudflare.com/turnstile')) {
            $add('turnstile', 'Cloudflare Turnstile', 'bot_protection', 'turnstile script');
        }
        if (str_contains($html, 'google.com/recaptcha') || str_contains($html, 'g-recaptcha')) {
            $add('recaptcha', 'reCAPTCHA', 'bot_protection', 'recaptcha script');
        }
        if (str_contains($html, 'hcaptcha.com')) {
            $add('hcaptcha', 'hCaptcha', 'bot_protection', 'hcaptcha script');
        }

        return array_values($found);
    }
}
