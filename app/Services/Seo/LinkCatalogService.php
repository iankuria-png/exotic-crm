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

        // City/neighborhood terms fetched from WP
        $catalog = array_merge($catalog, $this->fetchLocationEntries($platformId));

        // Curated attribute pages from WP option (set by admin)
        $catalog = array_merge($catalog, $this->fetchAttributeEntries($platformId));

        return $catalog;
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
