<?php

namespace App\Services\Seo;

use App\Services\Seo\Llm\BioGenerationRun;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Provides per-platform link catalogs (city/neighborhood terms + curated attribute pages).
 * Cached per platform to avoid repeated WP calls.
 */
class LinkCatalogService
{
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Canonical service landing pages available on every market.
     *
     * Use market-relative URLs so the same generated bio works on Exotic Kenya,
     * Exotic Uganda, and future platform domains without baking one host into CRM.
     *
     * The real WordPress discovery pages use an "-escorts" suffix on the slug
     * (e.g. /bdsm-escorts/, /massage-escorts/); the bare slugs used previously
     * 404. Only slugs verified to resolve on the live markets are listed here —
     * "Mature" and a generic "Escort" have no landing page and were removed.
     * These slugs are per-market and admin-editable in WordPress, so this map is
     * a fallback; Phase 2 sources the authoritative slugs live from WP.
     */
    private const SERVICE_PAGES = [
        'BDSM' => 'bdsm-escorts',
        'Couples' => 'couples-escorts',
        'Domination' => 'domination-escorts',
        'Massage' => 'massage-escorts',
        'Fetish' => 'fetish-escorts',
        'GFE' => 'gfe-escorts',
        'Incall' => 'incall-escorts',
        'Outcall' => 'outcall-escorts',
    ];

    /**
     * Attribute landing pages that follow the same per-market root slug pattern.
     * Keep this list conservative: only include attributes that have canonical pages.
     */
    private const ATTRIBUTE_PAGES = [
        'Curvy' => 'curvy-escorts',
    ];

    private const BLOCKED_ATTRIBUTE_KEYWORDS = [
        'black',
    ];

    public function forPlatform(int $platformId, ?BioGenerationRun $run = null): array
    {
        $key = "seo_link_catalog_platform_{$platformId}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $fallback = Cache::get($key.'_last_good') ?? $this->buildCatalog([]);
        if (Cache::get($key.'_unavailable') || ($run !== null && $run->readTimeout() <= 0)) {
            return $fallback;
        }

        try {
            $sync = WpSyncService::forPlatform($platformId);
            if ($run !== null) {
                $sync = $sync->withReadBudget(fn (): float => $run->readTimeout());
            }
            $data = $sync->getLinkCatalog();
            if (! is_array($data['locations'] ?? null) || ! is_array($data['attribute_pages'] ?? null)) {
                throw new \RuntimeException('Incomplete link catalogue.');
            }
            $catalog = $this->buildCatalog($data);
            Cache::put($key, $catalog, now()->addMinutes(self::CACHE_TTL_MINUTES));
            Cache::put($key.'_last_good', $catalog, now()->addDay());
            Cache::forget($key.'_unavailable');

            return $catalog;
        } catch (\Throwable) {
            Cache::put($key.'_unavailable', true, 15);
            Log::warning('LinkCatalogService: catalogue unavailable', ['platform_id' => $platformId]);

            return $fallback;
        }
    }

    public function invalidate(int $platformId): void
    {
        foreach (['', '_last_good', '_unavailable'] as $suffix) {
            Cache::forget("seo_link_catalog_platform_{$platformId}".$suffix);
        }
    }

    private function buildCatalog(array $data): array
    {
        return array_merge($this->servicePageEntries(), $this->attributePageEntries(),
            $this->fetchLocationEntries($data), $this->fetchAttributeEntries($data));
    }

    private function servicePageEntries(): array
    {
        $entries = [];

        foreach (self::SERVICE_PAGES as $keyword => $slug) {
            $entries[] = [
                'keyword' => $keyword,
                'url' => "/{$slug}/",
                'category' => "service:{$slug}",
                'priority' => 8,
            ];
        }

        return $entries;
    }

    private function attributePageEntries(): array
    {
        $entries = [];

        foreach (self::ATTRIBUTE_PAGES as $keyword => $slug) {
            $entries[] = [
                'keyword' => $keyword,
                'url' => "/{$slug}/",
                'category' => "attribute:{$slug}",
                'priority' => 7,
            ];
        }

        return $entries;
    }

    private function fetchLocationEntries(array $data): array
    {
        $entries = [];
        foreach (($data['locations'] ?? []) as $item) {
            $keyword = (string) ($item['name'] ?? '');
            $url = (string) ($item['url'] ?? '');
            if ($keyword === '' || $url === '' || in_array(strtolower(trim($keyword)), self::BLOCKED_ATTRIBUTE_KEYWORDS, true)) {
                continue;
            }
            $entries[] = ['keyword' => $keyword, 'url' => $url, 'category' => 'location', 'priority' => 10];
        }

        return $entries;
    }

    private function fetchAttributeEntries(array $data): array
    {
        $entries = [];
        foreach (($data['attribute_pages'] ?? []) as $item) {
            $keyword = (string) ($item['keyword'] ?? '');
            $url = (string) ($item['url'] ?? '');
            if ($keyword === '' || $url === '') {
                continue;
            }
            $entries[] = ['keyword' => $keyword, 'url' => $url,
                'category' => (string) ($item['category'] ?? 'attribute'), 'priority' => (int) ($item['priority'] ?? 5)];
        }

        return $entries;
    }
}
