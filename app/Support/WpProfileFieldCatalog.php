<?php

namespace App\Support;

class WpProfileFieldCatalog
{
    public const RATE_FIELDS = [
        'incall',
        'outcall',
        'rate30min_incall',
        'rate30min_outcall',
        'rate1h_incall',
        'rate1h_outcall',
        'rate2h_incall',
        'rate2h_outcall',
        'rate3h_incall',
        'rate3h_outcall',
        'rate6h_incall',
        'rate6h_outcall',
        'rate12h_incall',
        'rate12h_outcall',
        'rate24h_incall',
        'rate24h_outcall',
    ];

    public const ARRAY_FIELDS = [
        'services',
        'availability',
    ];

    public const ENUM_FIELDS = [
        'gender',
        'ethnicity',
        'build',
        'haircolor',
        'hairlength',
        'bustsize',
        'looks',
        'smoker',
        'language1level',
        'language2level',
        'language3level',
    ];

    public const STRING_FIELDS = [
        'name',
        'phone',
        'email',
        'content',
        'birthday',
        'height',
        'weight',
        'whatsapp',
        'instagram',
        'twitter',
        'telegram',
        'website',
        'facebook',
        'snapchat',
        'education',
        'occupation',
        'sports',
        'hobbies',
        'zodiacsign',
        'sexualorientation',
        'language1',
        'language2',
        'language3',
        'extraservices',
        'personal_phone',
    ];

    public static function enumMaps(): array
    {
        return [
            'gender' => [
                '1' => 'Female',
                '2' => 'Male',
                '3' => 'Couple',
                '4' => 'Gay',
                '5' => 'Transsexual',
            ],
            'ethnicity' => [
                '1' => 'Latin',
                '2' => 'Caucasian',
                '3' => 'Black',
                '4' => 'White',
                '5' => 'MiddleEast',
                '6' => 'Asian',
                '7' => 'Indian',
                '8' => 'Aborigine',
                '9' => 'Native American',
                '10' => 'Other',
            ],
            'build' => [
                '1' => 'Skinny',
                '2' => 'Slim',
                '3' => 'Regular',
                '4' => 'Curvy',
                '5' => 'Fat',
            ],
            'services' => [
                '1' => 'BDSM',
                '2' => 'Couples',
                '3' => 'Domination',
                '4' => 'Escort',
                '5' => 'Massage',
                '6' => 'Fetish',
                '7' => 'Mature',
                '8' => 'GFE',
            ],
            'haircolor' => [
                '1' => 'Black',
                '2' => 'Blonde',
                '3' => 'Brown',
                '4' => 'Brunette',
                '5' => 'Chestnut',
                '6' => 'Auburn',
                '7' => 'Dark-blonde',
                '8' => 'Golden',
                '9' => 'Red',
                '10' => 'Grey',
                '11' => 'Silver',
                '12' => 'White',
                '13' => 'Other',
            ],
            'hairlength' => [
                '1' => 'Bald',
                '2' => 'Short',
                '3' => 'Shoulder',
                '4' => 'Long',
                '5' => 'Very Long',
            ],
            'bustsize' => [
                '1' => 'Very small',
                '2' => 'Small (A)',
                '3' => 'Medium (B)',
                '4' => 'Large (C)',
                '5' => 'Very Large (D)',
                '6' => 'Enormous (E+)',
            ],
            'looks' => [
                '1' => 'Nothing Special',
                '2' => 'Average',
                '3' => 'Sexy',
                '4' => 'Ultra Sexy',
            ],
            'smoker' => [
                '1' => 'Yes',
                '2' => 'No',
            ],
            'availability' => [
                '1' => 'Incall',
                '2' => 'Outcall',
            ],
            'languagelevel' => [
                '1' => 'Minimal',
                '2' => 'Conversational',
                '3' => 'Fluent',
            ],
        ];
    }

    public static function editableFields(): array
    {
        return array_values(array_unique(array_merge(
            [
                'region_id',
                'city_id',
                'currency',
            ],
            self::STRING_FIELDS,
            self::ENUM_FIELDS,
            self::ARRAY_FIELDS,
            self::RATE_FIELDS
        )));
    }

    public static function createProvisioningFields(): array
    {
        return array_values(array_unique(array_merge(
            [
                'region_id',
                'city_id',
                'currency',
                'bio',
                'post_status',
                'username',
                'password',
                'signup_source',
                'provision_request_id',
            ],
            self::STRING_FIELDS,
            self::ENUM_FIELDS,
            self::ARRAY_FIELDS,
            self::RATE_FIELDS
        )));
    }

    public static function legacyHeightCodeToCm(): array
    {
        return [
            '1' => '128',
            '2' => '134',
            '3' => '140',
            '4' => '146',
            '5' => '152',
            '6' => '155',
            '7' => '158',
            '8' => '162',
            '9' => '165',
            '10' => '168',
            '11' => '171',
            '12' => '174',
            '13' => '177',
            '14' => '180',
            '15' => '183',
            '16' => '189',
            '17' => '195',
            '18' => '201',
            '19' => '207',
            '20' => '213',
        ];
    }
}
