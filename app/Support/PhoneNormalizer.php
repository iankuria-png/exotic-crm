<?php

namespace App\Support;

class PhoneNormalizer
{
    public const VERSION = 2;

    private const NATIONAL_LENGTHS = ['254' => 9, '255' => 9, '256' => 9, '250' => 9, '233' => 9, '234' => 10, '221' => 9, '225' => 10, '229' => 10, '260' => 9, '263' => 9, '27' => 9, '216' => 8, '245' => 9];

    private const SIGNIFICANT_ZERO = ['225', '229'];

    public static function normalize(?string $phone, string $prefix = '254'): ?string
    {
        return self::parse($phone, $prefix)['canonical'];
    }

    public static function parse(?string $phone, string $prefix = '254'): array
    {
        $miss = fn ($reason) => ['canonical' => null, 'version' => self::VERSION, 'reason' => $reason];
        if ($phone === null || trim($phone) === '') {
            return $miss('missing');
        }
        $prefix = preg_replace('/\D/', '', $prefix);
        if (! $prefix || strlen($prefix) > 3) {
            return $miss('country_required');
        }
        $input = trim($phone);
        if (! preg_match('/^\+?[0-9\s().\/-]+$/D', $input)) {
            return $miss('invalid_characters');
        }
        $value = preg_replace('/\D/', '', $input);
        $international = str_starts_with($input, '+') || str_starts_with($value, '00');
        if (str_starts_with($value, '00')) {
            $value = substr($value, 2);
        }
        if (! $international) {
            // An already international digit string is canonical only when long enough.
            $nationalLengths = self::NATIONAL_LENGTHS;
            $nationalLength = $nationalLengths[$prefix] ?? 9;
            // Preserve a recognisable foreign international number even without a plus.
            if (! str_starts_with($value, '0') && strlen($value) >= 11 && ! str_starts_with($value, $prefix)) {
                foreach ($nationalLengths as $foreignPrefix => $length) {
                    if (str_starts_with($value, (string) $foreignPrefix) && strlen($value) === strlen((string) $foreignPrefix) + $length) {
                        $international = true;
                        break;
                    }
                }
            }
            if (! $international && ! (str_starts_with($value, $prefix) && strlen($value) > $nationalLength)) {
                // In Côte d’Ivoire 01/05/07 are national digits, not a trunk prefix.
                if (! in_array($prefix, self::SIGNIFICANT_ZERO, true) && str_starts_with($value, '0')) {
                    $value = substr($value, 1);
                }
                $value = $prefix.$value;
            }
        }
        foreach (self::NATIONAL_LENGTHS as $dialCode => $length) {
            $dialCode = (string) $dialCode;
            if (! in_array($dialCode, self::SIGNIFICANT_ZERO, true) && str_starts_with($value, $dialCode.'0') && strlen($value) === strlen($dialCode) + $length + 1) {
                $value = $dialCode.substr($value, strlen($dialCode) + 1);
                break;
            }
        }
        if (strlen($value) < 7 || strlen($value) > 15 || str_starts_with($value, '0')) {
            return $miss('invalid_length');
        }
        if (str_starts_with($value, '225') && ! preg_match('/^225[0-9]{10}$/D', $value)) {
            return $miss('invalid_country_length');
        }
        if (str_starts_with($value, '229') && ! preg_match('/^22901[0-9]{8}$/D', $value)) {
            return $miss('invalid_country_length');
        }

        return ['canonical' => $value, 'version' => self::VERSION, 'reason' => null];
    }
}
