<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function normalize(?string $phone, string $prefix = '254'): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', (string) $phone);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $normalized = ltrim($normalized, '+');
        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }
        if ($normalized === '') {
            return null;
        }

        $normalizedPrefix = preg_replace('/\D/', '', (string) $prefix);
        if (!is_string($normalizedPrefix) || $normalizedPrefix === '') {
            $normalizedPrefix = '254';
        }

        if (str_starts_with($normalized, '0')) {
            $normalized = $normalizedPrefix . ltrim(substr($normalized, 1), '0');
        } elseif (!str_starts_with($normalized, $normalizedPrefix) && strlen($normalized) <= 10) {
            $normalized = $normalizedPrefix . ltrim($normalized, '0');
        }

        return $normalized !== '' ? $normalized : null;
    }
}
