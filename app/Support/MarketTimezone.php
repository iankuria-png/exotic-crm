<?php

namespace App\Support;

use DateTimeZone;

final class MarketTimezone
{
    /**
     * Legacy or invalid values we still accept on input and normalize on save/runtime.
     *
     * @var array<string, string>
     */
    private const LEGACY_ALIASES = [
        'Africa/Yamoussoukro' => 'Africa/Abidjan',
    ];

    /**
     * @var array<string, string>|null
     */
    private static ?array $validIdentifierLookup = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $legacyAliasLookup = null;

    /**
     * @return array<string, string>
     */
    public static function legacyAliases(): array
    {
        return self::LEGACY_ALIASES;
    }

    public static function normalize(?string $timezone): ?string
    {
        $candidate = trim((string) $timezone);
        if ($candidate === '') {
            return null;
        }

        $lowerCandidate = strtolower($candidate);
        $aliasLookup = self::legacyAliasLookup();
        if (array_key_exists($lowerCandidate, $aliasLookup)) {
            $candidate = $aliasLookup[$lowerCandidate];
            $lowerCandidate = strtolower($candidate);
        }

        $validLookup = self::validIdentifierLookup();

        return $validLookup[$lowerCandidate] ?? null;
    }

    public static function resolve(?string $timezone, ?string $fallback = 'UTC'): string
    {
        return self::normalize($timezone)
            ?? self::normalize($fallback)
            ?? 'UTC';
    }

    public static function isValid(?string $timezone): bool
    {
        return self::normalize($timezone) !== null;
    }

    public static function validationMessage(): string
    {
        return 'Timezone must be a valid PHP/IANA timezone identifier, for example Africa/Nairobi.';
    }

    public static function requiredValidationMessage(): string
    {
        return 'Timezone is required and must be a valid PHP/IANA timezone identifier, for example Africa/Nairobi.';
    }

    /**
     * @return array<string, string>
     */
    private static function validIdentifierLookup(): array
    {
        if (self::$validIdentifierLookup !== null) {
            return self::$validIdentifierLookup;
        }

        self::$validIdentifierLookup = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            self::$validIdentifierLookup[strtolower($identifier)] = $identifier;
        }

        return self::$validIdentifierLookup;
    }

    /**
     * @return array<string, string>
     */
    private static function legacyAliasLookup(): array
    {
        if (self::$legacyAliasLookup !== null) {
            return self::$legacyAliasLookup;
        }

        self::$legacyAliasLookup = [];

        foreach (self::LEGACY_ALIASES as $legacy => $canonical) {
            self::$legacyAliasLookup[strtolower($legacy)] = $canonical;
        }

        return self::$legacyAliasLookup;
    }
}
