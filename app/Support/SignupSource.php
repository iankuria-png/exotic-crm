<?php

namespace App\Support;

class SignupSource
{
    public const FAST_SIGNUP = 'fast_signup';

    public const FULL_REGISTRATION = 'full_registration';

    public const CRM_MANUAL = 'crm_manual';

    public const CRM_PROVISIONED = 'crm_provisioned';

    public const FIELD = 'field';

    public const EXISTING = 'existing';

    public const LABELS = [
        self::FAST_SIGNUP => 'Fast signup',
        self::FULL_REGISTRATION => 'Full registration',
        self::CRM_MANUAL => 'CRM manual',
        self::CRM_PROVISIONED => 'Provisioned',
        self::FIELD => 'Field sales',
        self::EXISTING => 'Existing / legacy',
    ];

    public static function normalize(?string $source): string
    {
        $key = strtolower(trim((string) $source));

        return array_key_exists($key, self::LABELS) ? $key : self::EXISTING;
    }

    public static function label(?string $source): string
    {
        $key = self::normalize($source);

        return self::LABELS[$key];
    }
}
