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
        if ($normalized === '') {
            return null;
        }

        $normalizedPrefix = preg_replace('/\D/', '', (string) $prefix);
        if (!is_string($normalizedPrefix) || $normalizedPrefix === '') {
            $normalizedPrefix = '254';
        }

        if (str_starts_with($normalized, '0')) {
            $normalized = $normalizedPrefix . substr($normalized, 1);
        }

        return $normalized !== '' ? $normalized : null;
    }
}
