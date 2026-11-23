<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\FetchRequest;
use App\Services\Scraper\Fetch\HttpFetchDriver;
use App\Services\Scraper\Fetch\UrlTools;

/**
 * Finds a site's sitemap and pulls profile-looking URLs from it, within a small request
 * budget. Used as an alternative route when listing pages are blocked.
 */
class SitemapReader
{
    private const PROFILE_HINT = '#/(escort|escorts|profile|profiles|model|models|girl|girls|ads?|listing|listings|companion)s?/[^/]+/?$#i';

    public function __construct(private readonly HttpFetchDriver $http)
    {
    }

    /**
     * @param array<int, string> $declared sitemaps listed in robots.txt
     * @return array{found: bool, sitemap_url: ?string, status: ?int}
     */
    public function locate(string $siteUrl, array $declared = []): array
    {
        $origin = $this->origin($siteUrl);
        $candidates = array_values(array_unique(array_merge($declared, [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml'])));
        foreach (array_slice($candidates, 0, 4) as $url) {
            $result = $this->http->fetch(new FetchRequest($url, ['Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.1'], [], 12));
            if ($result->status >= 200 && $result->status < 300 && preg_match('/<(urlset|sitemapindex)\b/i', substr($result->html, 0, 4000))) {
                return ['found' => true, 'sitemap_url' => $url, 'status' => $result->status];
            }
        }

        return ['found' => false, 'sitemap_url' => null, 'status' => null];
    }

    /**
     * @return array{urls: array<int, string>, sitemaps_read: int, errors: array<int, string>}
     */
    public function profileUrls(string $sitemapUrl, ?string $profilePattern, int $limit = 150, int $maxSitemaps = 4): array
    {
        $queue = [$sitemapUrl];
        $seen = [];
        $urls = [];
        $errors = [];
        $read = 0;
        $pattern = $profilePattern && @preg_match($profilePattern, '') !== false ? $profilePattern : self::PROFILE_HINT;

        while ($queue !== [] && $read < $maxSitemaps && count($urls) < $limit) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $result = $this->http->fetch(new FetchRequest($url, ['Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.1'], [], 15));
            $read++;
            if (!$result->ok()) {
                $errors[] = sprintf('%s → %s', $url, $result->error ?? ('HTTP ' . $result->status));
                continue;
            }
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#si', $result->html, $m);
            $locs = array_map(fn ($l) => html_entity_decode(trim($l)), $m[1] ?? []);
            if (preg_match('/<sitemapindex\b/i', $result->html)) {
                // Prefer child sitemaps that look like profile/listing collections.
                usort($locs, fn ($a, $b) => (int) preg_match('/escort|profile|model|girl|listing|ad/i', $b) <=> (int) preg_match('/escort|profile|model|girl|listing|ad/i', $a));
                foreach ($locs as $loc) {
                    if (UrlTools::sameSite($loc, $sitemapUrl)) {
                        $queue[] = $loc;
                    }
                }
                continue;
            }
            foreach ($locs as $loc) {
                if (UrlTools::sameSite($loc, $sitemapUrl) && preg_match($pattern, (string) parse_url($loc, PHP_URL_PATH))) {
                    $urls[$loc] = true;
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }
        }

        return ['urls' => array_keys($urls), 'sitemaps_read' => $read, 'errors' => $errors];
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
