<?php

namespace App\Services\Scraper\Fetch;

final class UrlTools
{
    public static function resolve(string $baseUrl, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        $lower = strtolower($href);
        foreach (['javascript:', 'mailto:', 'tel:', 'data:', 'whatsapp:', 'sms:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return null;
            }
        }
        if (preg_match('#^https?://#i', $href)) {
            return self::stripFragment($href);
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($href, '//')) {
            return self::stripFragment($base['scheme'] . ':' . $href);
        }
        if (str_starts_with($href, '/')) {
            return self::stripFragment($origin . $href);
        }
        if (str_starts_with($href, '?')) {
            return self::stripFragment($origin . ($base['path'] ?? '/') . $href);
        }

        $path = $base['path'] ?? '/';
        $dir = str_ends_with($path, '/') ? $path : (dirname($path) === '\\' ? '/' : rtrim(dirname($path), '/') . '/');
        $segments = [];
        foreach (explode('/', $dir . $href) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return self::stripFragment($origin . '/' . ltrim(implode('/', $segments), '/'));
    }

    public static function host(string $url): string
    {
        return strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?? '');
    }

    public static function sameSite(string $a, string $b): bool
    {
        $ha = self::host($a);
        $hb = self::host($b);

        return $ha !== '' && $ha === $hb;
    }

    private static function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
