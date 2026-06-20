<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WpProfileFieldValidator
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array{
     *   currency_catalog_ids?: array<int, int|string>,
     *   current_currency_id?: int|string|null
     * }  $context
     * @return array<string, mixed>
     */
    public static function validate(array $fields, array $context = []): array
    {
        $normalized = [];
        $errors = [];
        $allowed = array_flip(WpProfileFieldCatalog::editableFields());
        $enumMaps = WpProfileFieldCatalog::enumMaps();

        foreach ($fields as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!array_key_exists($key, $allowed)) {
                $errors[$key] = 'This field is not editable via CRM.';
                continue;
            }

            if (in_array($key, WpProfileFieldCatalog::RATE_FIELDS, true)) {
                if ($value === null || $value === '') {
                    $normalized[$key] = null;
                    continue;
                }

                if (!is_numeric($value)) {
                    $errors[$key] = 'Rates must be numeric.';
                    continue;
                }

                $normalized[$key] = (string) $value;
                continue;
            }

            if (in_array($key, WpProfileFieldCatalog::ARRAY_FIELDS, true)) {
                if ($value === null || $value === '') {
                    $normalized[$key] = null;
                    continue;
                }

                if (!is_array($value)) {
                    $errors[$key] = 'This field must be an array.';
                    continue;
                }

                $optionKey = $key === 'availability' ? 'availability' : 'services';
                $validCodes = array_map('strval', array_keys($enumMaps[$optionKey] ?? []));
                $items = [];

                foreach ($value as $item) {
                    $candidate = trim((string) $item);
                    if ($candidate === '') {
                        continue;
                    }

                    if (!in_array($candidate, $validCodes, true)) {
                        $errors[$key] = 'One or more selected values are invalid.';
                        continue 2;
                    }

                    if (!in_array($candidate, $items, true)) {
                        $items[] = $candidate;
                    }
                }

                $normalized[$key] = $items === [] ? null : $items;
                continue;
            }

            if (in_array($key, WpProfileFieldCatalog::ENUM_FIELDS, true)) {
                if ($value === null || $value === '') {
                    $normalized[$key] = null;
                    continue;
                }

                $candidate = trim((string) $value);
                $lookupKey = str_starts_with($key, 'language') ? 'languagelevel' : $key;
                if (!array_key_exists($candidate, $enumMaps[$lookupKey] ?? [])) {
                    $errors[$key] = 'This field must be selected from the approved list.';
                    continue;
                }

                $normalized[$key] = $candidate;
                continue;
            }

            switch ($key) {
                case 'currency':
                    if ($value === null || $value === '') {
                        $errors[$key] = 'Currency must be selected from the approved list.';
                        break;
                    }

                    if (!is_numeric($value) || (int) $value <= 0) {
                        $errors[$key] = 'Currency must be a valid numeric identifier.';
                        break;
                    }

                    $currencyId = (int) $value;
                    $catalogIds = array_values(array_unique(array_map('intval', $context['currency_catalog_ids'] ?? [])));
                    $currentCurrencyId = isset($context['current_currency_id']) && $context['current_currency_id'] !== ''
                        ? (int) $context['current_currency_id']
                        : null;

                    if (!in_array($currencyId, $catalogIds, true) && $currencyId !== $currentCurrencyId) {
                        $errors[$key] = 'Currency must be selected from the approved list.';
                        break;
                    }

                    $normalized[$key] = $currencyId;
                    break;

                case 'region_id':
                case 'city_id':
                    // Location pair semantics are validated after the loop so omission is preserved.
                    $normalized[$key] = $value;
                    break;

                case 'birthday':
                    if ($value === null || $value === '') {
                        $normalized[$key] = null;
                        break;
                    }

                    $raw = trim((string) $value);
                    try {
                        $birthday = CarbonImmutable::createFromFormat('Y-m-d', $raw);
                    } catch (\Throwable) {
                        $errors[$key] = 'Birthday must use YYYY-MM-DD format.';
                        break;
                    }

                    if ($birthday->format('Y-m-d') !== $raw) {
                        $errors[$key] = 'Birthday must use YYYY-MM-DD format.';
                        break;
                    }

                    if ($birthday->addYears(18)->isAfter(now())) {
                        $errors[$key] = 'Profile owners must be at least 18 years old.';
                        break;
                    }

                    $normalized[$key] = $raw;
                    break;

                case 'height':
                    if ($value === null || $value === '') {
                        $normalized[$key] = null;
                        break;
                    }

                    $raw = trim((string) $value);
                    $normalized[$key] = WpProfileFieldCatalog::legacyHeightCodeToCm()[$raw] ?? $raw;
                    break;

                default:
                    $normalized[$key] = self::normalizeStringField($key, $value, $errors);
                    break;
            }
        }

        self::validateLocationPair($normalized, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $errors
     */
    private static function validateLocationPair(array &$fields, array &$errors): void
    {
        $hasRegion = array_key_exists('region_id', $fields);
        $hasCity = array_key_exists('city_id', $fields);

        if (!$hasRegion && !$hasCity) {
            return;
        }

        if (!$hasRegion || !$hasCity) {
            $errors['location'] = 'Region and city must be provided together.';
            unset($fields['region_id'], $fields['city_id']);

            return;
        }

        $region = $fields['region_id'];
        $city = $fields['city_id'];

        if ($region === null && $city === null) {
            $fields['region_id'] = null;
            $fields['city_id'] = null;

            return;
        }

        if (
            !is_numeric($region)
            || !is_numeric($city)
            || (int) $region <= 0
            || (int) $city <= 0
        ) {
            $errors['location'] = 'Region and city must be valid identifiers or both null.';
            unset($fields['region_id'], $fields['city_id']);

            return;
        }

        $fields['region_id'] = (int) $region;
        $fields['city_id'] = (int) $city;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function normalizeStringField(string $key, mixed $value, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $limits = [
            'name' => 255,
            'phone' => 50,
            'email' => 255,
            'content' => 5000,
            'birthday' => 10,
            'height' => 50,
            'weight' => 50,
            'whatsapp' => 255,
            'instagram' => 255,
            'twitter' => 255,
            'telegram' => 255,
            'website' => 255,
            'facebook' => 255,
            'snapchat' => 255,
            'education' => 255,
            'occupation' => 255,
            'sports' => 255,
            'hobbies' => 255,
            'zodiacsign' => 255,
            'sexualorientation' => 255,
            'language1' => 120,
            'language2' => 120,
            'language3' => 120,
            'extraservices' => 1000,
            'personal_phone' => 50,
        ];

        $limit = $limits[$key] ?? 255;
        if (mb_strlen($string) > $limit) {
            $errors[$key] = sprintf('This field may not exceed %d characters.', $limit);

            return null;
        }

        return $string;
    }
}
