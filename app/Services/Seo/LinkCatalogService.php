<?php

namespace App\Services\Seo;

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
        'BDSM'       => 'bdsm-escorts',
        'Couples'    => 'couples-escorts',
        'Domination' => 'domination-escorts',
        'Massage'    => 'massage-escorts',
        'Fetish'     => 'fetish-escorts',
        'GFE'        => 'gfe-escorts',
        'Incall'     => 'incall-escorts',
        'Outcall'    => 'outcall-escorts',
    ];

    /**
     * Attribute landing pages that follow the same per-market root slug pattern.
     * Keep this list conservative: only include attributes that have canonical pages.
     */
    private const ATTRIBUTE_PAGES = [
        'Black' => 'black-escorts',
        'Curvy' => 'curvy-escorts',
    ];

    public function forPlatform(int $platformId): array
    {
        return Cache::remember(
            "seo_link_catalog_platform_{$platformId}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn() => $this->buildCatalog($platformId),
        );
    }

    public function invalidate(int $platformId): void
    {
        Cache::forget("seo_link_catalog_platform_{$platformId}");
    }

    // -------------------------------------------------------------------------

    private function buildCatalog(int $platformId): array
    {
        $catalog = [];

        // Standard service pages are market-relative, e.g. /bdsm/, /couples/.
        // Give each service its own category so the per-category cap does not
        // prevent multiple selected services from being linked in a concise bio.
        $catalog = array_merge($catalog, $this->servicePageEntries());

        // Canonical attribute pages, e.g. /black/, /curvy/.
        $catalog = array_merge($catalog, $this->attributePageEntries());

        // City/neighborhood terms fetched from WP
        $catalog = array_merge($catalog, $this->fetchLocationEntries($platformId));

        // Curated attribute pages from WP option (set by admin)
        $catalog = array_merge($catalog, $this->fetchAttributeEntries($platformId));

        return $catalog;
    }


    private function servicePageEntries(): array
    {
        $entries = [];

        foreach (self::SERVICE_PAGES as $keyword => $slug) {
            $entries[] = [
                'keyword'  => $keyword,
                'url'      => "/{$slug}/",
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
                'keyword'  => $keyword,
                'url'      => "/{$slug}/",
                'category' => "attribute:{$slug}",
                'priority' => 7,
            ];
        }

        return $entries;
    }

    private function fetchLocationEntries(int $platformId): array
    {
        try {
            $wpSync = WpSyncService::forPlatform($platformId);
            $data   = $wpSync->getLinkCatalog();
            $entries = [];

            foreach (($data['locations'] ?? []) as $item) {
                $keyword = (string) ($item['name'] ?? '');
                $url     = (string) ($item['url'] ?? '');
                if ($keyword === '' || $url === '') {
                    continue;
                }

                $entries[] = [
                    'keyword'  => $keyword,
                    'url'      => $url,
                    'category' => 'location',
                    'priority' => 10,
                ];
            }

            return $entries;
        } catch (\Throwable $e) {
            Log::warning('LinkCatalogService: failed to fetch location terms', [
                'platform_id' => $platformId,
                'error'       => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function fetchAttributeEntries(int $platformId): array
    {
        try {
            $wpSync = WpSyncService::forPlatform($platformId);
            $data   = $wpSync->getLinkCatalog();
            $entries = [];

            foreach (($data['attribute_pages'] ?? []) as $item) {
                $keyword = (string) ($item['keyword'] ?? '');
                $url     = (string) ($item['url'] ?? '');
                if ($keyword === '' || $url === '') {
                    continue;
                }

                $entries[] = [
                    'keyword'  => $keyword,
                    'url'      => $url,
                    'category' => (string) ($item['category'] ?? 'attribute'),
                    'priority' => (int) ($item['priority'] ?? 5),
                ];
            }

            return $entries;
        } catch (\Throwable $e) {
            Log::warning('LinkCatalogService: failed to fetch attribute pages', [
                'platform_id' => $platformId,
                'error'       => $e->getMessage(),
            ]);

            return [];
        }
    }
}
