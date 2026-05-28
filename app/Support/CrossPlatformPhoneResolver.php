<?php

namespace App\Support;

class CrossPlatformPhoneResolver
{
    public function resolve(?string $sourcePhone, ?string $sourcePrefix, ?string $targetPrefix): ?string
    {
        $phone = PhoneNormalizer::normalize($sourcePhone, $sourcePrefix ?: '254');
        if (!$phone) {
            return null;
        }

        $source = $this->digits($sourcePrefix ?: '');
        $target = $this->digits($targetPrefix ?: '');

        if ($target === '') {
            return $phone;
        }

        if ($source !== '' && $source !== $target && str_starts_with($phone, $source)) {
            $phone = $target . ltrim(substr($phone, strlen($source)), '0');
        }

        return PhoneNormalizer::normalize($phone, $target);
    }

    private function digits(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return is_string($digits) ? $digits : '';
    }
}
