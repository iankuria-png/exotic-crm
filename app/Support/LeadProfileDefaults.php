<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Starter profile facts for leads converted from scraped listings, so customer service
 * opens a profile that is already filled in and only corrects what it knows.
 *
 * Codes follow WpProfileFieldCatalog: gender 1 = Female, looks 3 = Sexy, 4 = Ultra Sexy.
 * Height is centimetres and weight kilograms, stored as plain numbers like the editor does.
 */
final class LeadProfileDefaults
{
    public const FIELDS = ['gender', 'birthday', 'height', 'weight', 'looks'];

    public const BIRTH_YEARS = [2000, 2005];

    public const HEIGHT_CM = [150, 170];

    public const WEIGHT_KG = [50, 80];

    public const LOOKS = ['3', '4'];

    /**
     * @return array{gender: string, birthday: string, height: string, weight: string, looks: string}
     */
    public static function generate(): array
    {
        $first = CarbonImmutable::create(self::BIRTH_YEARS[0], 1, 1);
        $span = (int) $first->diffInDays(CarbonImmutable::create(self::BIRTH_YEARS[1], 12, 31));

        return [
            'gender' => '1',
            'birthday' => $first->addDays(random_int(0, $span))->format('Y-m-d'),
            'height' => (string) random_int(...self::HEIGHT_CM),
            'weight' => (string) random_int(...self::WEIGHT_KG),
            'looks' => self::LOOKS[random_int(0, count(self::LOOKS) - 1)],
        ];
    }

    /**
     * Accept only a set this class could have produced; anything else is dropped.
     *
     * @return array<string, string>|null
     */
    public static function sanitize(mixed $defaults): ?array
    {
        if (! is_array($defaults)) {
            return null;
        }
        $defaults = array_intersect_key($defaults, array_flip(self::FIELDS));
        if (count($defaults) !== count(self::FIELDS)) {
            return null;
        }
        $defaults = array_map(static fn ($value) => (string) $value, $defaults);

        $birthday = CarbonImmutable::createFromFormat('!Y-m-d', $defaults['birthday']) ?: null;
        $valid = $defaults['gender'] === '1'
            && $birthday !== null && $birthday->format('Y-m-d') === $defaults['birthday']
            && $birthday->year >= self::BIRTH_YEARS[0] && $birthday->year <= self::BIRTH_YEARS[1]
            && self::within($defaults['height'], self::HEIGHT_CM)
            && self::within($defaults['weight'], self::WEIGHT_KG)
            && in_array($defaults['looks'], self::LOOKS, true);

        return $valid ? $defaults : null;
    }

    private static function within(string $value, array $range): bool
    {
        return ctype_digit($value) && (int) $value >= $range[0] && (int) $value <= $range[1];
    }
}
