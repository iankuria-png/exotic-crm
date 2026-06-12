<?php

namespace App\Support;

class CityNormalizer
{
    public static function fromWpPayload(array $payload): ?string
    {
        $taxonomies = is_array($payload['taxonomies'] ?? null) ? $payload['taxonomies'] : [];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $candidates = [
            data_get($taxonomies, 'city.name'),
            data_get($taxonomies, 'city.0.name'),
            $payload['city'] ?? null,
            $meta['city'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeLabel($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public static function normalizeLabel(mixed $value, int $maxLength = 100): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $normalized) === 1) {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    public static function canonicalKey(mixed $value, int $maxLength = 120): ?string
    {
        $normalized = self::normalizeLabel($value, $maxLength);
        if ($normalized === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($normalized));
        if (!is_string($collapsed) || $collapsed === '') {
            return null;
        }

        return mb_strtolower($collapsed);
    }
}
