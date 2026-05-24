<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductCatalogService
{
    public static function normalizePackageName(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function normalizePackageTier(string $tier, string $name): string
    {
        $tier = strtolower(trim($tier));
        if (in_array($tier, ['basic', 'premium', 'vip', 'vvip', 'custom'], true)) {
            return $tier;
        }

        $slug = strtolower(trim($name));
        if (str_contains($slug, 'vvip')) {
            return 'vvip';
        }
        if (str_contains($slug, 'vip')) {
            return 'vip';
        }
        if (str_contains($slug, 'premium')) {
            return 'premium';
        }
        if (str_contains($slug, 'basic')) {
            return 'basic';
        }

        return 'custom';
    }

    public static function generateUniqueSlugForPlatform(
        int $platformId,
        string $rawName,
        ?int $ignoreProductId = null,
        string $separator = '_'
    ): string {
        $baseSlug = Str::slug($rawName, $separator);
        if ($baseSlug === '') {
            $baseSlug = 'package';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (self::slugExists($platformId, $slug, $ignoreProductId)) {
            $slug = $baseSlug . $separator . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private static function slugExists(int $platformId, string $slug, ?int $ignoreProductId): bool
    {
        return Product::query()
            ->where('platform_id', $platformId)
            ->where('slug', $slug)
            ->when($ignoreProductId, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->exists();
    }
}
