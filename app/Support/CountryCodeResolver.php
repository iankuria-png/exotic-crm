<?php

namespace App\Support;

use Illuminate\Support\Str;

class CountryCodeResolver
{
    private const MAP = [
        'burundi' => 'BI',
        'congo' => 'CG',
        'cote d ivoire' => 'CI',
        'cote divoire' => 'CI',
        'democratic republic of congo' => 'CD',
        'drc' => 'CD',
        'ghana' => 'GH',
        'ivory coast' => 'CI',
        'kenya' => 'KE',
        'rwanda' => 'RW',
        'south africa' => 'ZA',
        'tanzania' => 'TZ',
        'uganda' => 'UG',
        'zambia' => 'ZM',
    ];

    public static function alpha2(?string $countryName): ?string
    {
        $normalized = Str::of((string) $countryName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return null;
        }

        return self::MAP[$normalized] ?? null;
    }
}
