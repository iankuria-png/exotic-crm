<?php

namespace App\Support;

/**
 * Canonical taxonomy for the profile lifecycle. This is authoritative on the CRM
 * side and is published to WordPress as the `crm_lifecycle_state` postmeta so the
 * public site can react (hide contact methods, lock editing, exclude from listings)
 * without taking the profile offline — preserving its SEO value.
 *
 * Semantics (while the WP post remains `publish`):
 * - active   → fully visible, contacts on, editing allowed.
 * - expired  → published & indexed, contacts hidden + notice, editing disabled.
 * - archived → published & indexed, contacts hidden, editing disabled, excluded
 *              from city/category listings.
 * - removed  → trashed/deleted in WordPress (handled by ClientDeletionService).
 */
final class ClientLifecycleState
{
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';
    public const ARCHIVED = 'archived';
    public const REMOVED = 'removed';

    public const ALL = [
        self::ACTIVE,
        self::EXPIRED,
        self::ARCHIVED,
        self::REMOVED,
    ];

    /** States that are published to WordPress as a meta value (removed = deletion). */
    public const PUBLISHABLE = [
        self::ACTIVE,
        self::EXPIRED,
        self::ARCHIVED,
    ];

    public const LABELS = [
        self::ACTIVE => 'Active',
        self::EXPIRED => 'Expired',
        self::ARCHIVED => 'Archived',
        self::REMOVED => 'Removed',
    ];

    public static function isValid(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /**
     * States where the profile is publicly reachable but must not generate leads:
     * contacts hidden and editing disabled on the website.
     */
    public static function isPubliclyRestricted(?string $state): bool
    {
        return in_array((string) $state, [self::EXPIRED, self::ARCHIVED], true);
    }

    /** Normalise an inbound value (e.g. from WP sync) to a known state, defaulting to active. */
    public static function normalize(?string $state): string
    {
        $state = strtolower(trim((string) $state));

        return self::isValid($state) ? $state : self::ACTIVE;
    }
}
