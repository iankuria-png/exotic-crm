<?php

namespace App\Support;

use App\Models\User;
use App\Services\MarketAuthorizationService;

/**
 * Database Observatory permission matrix.
 *
 * ┌──────────────────────────────────────────────┬───────┬───────────┬────────┐
 * │ Action                                       │ Admin │ Sub-admin │ Others │
 * ├──────────────────────────────────────────────┼───────┼───────────┼────────┤
 * │ view — overview, runs, findings, coverage,   │   ✓   │ assigned  │   ✗    │
 * │        events, inventory, rules, export      │       │ markets   │        │
 * │ operate — scan now, pause, resume, stop,     │   ✓   │     ✗     │   ✗    │
 * │           triage, suppress                   │       │           │        │
 * │ configure — rules, lists, schedules, limits, │   ✓   │     ✗     │   ✗    │
 * │             connections, preflight, tests    │       │           │        │
 * └──────────────────────────────────────────────┴───────┴───────────┴────────┘
 *
 * Sub-admins never see raw connection settings. Market scope always comes
 * from MarketAuthorizationService; no assignments means no markets.
 */
class DbScannerPermissions
{
    public static function canView(?User $user): bool
    {
        return in_array($user?->role, [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN], true);
    }

    public static function canOperate(?User $user): bool
    {
        return $user?->role === MarketAuthorizationService::ROLE_ADMIN;
    }

    public static function canConfigure(?User $user): bool
    {
        return $user?->role === MarketAuthorizationService::ROLE_ADMIN;
    }

    public static function capabilities(?User $user): array
    {
        return [
            'view' => self::canView($user),
            'operate' => self::canOperate($user),
            'configure' => self::canConfigure($user),
        ];
    }
}
