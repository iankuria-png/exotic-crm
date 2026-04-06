<?php

namespace App\Billing;

use App\Models\User;

/**
 * BillingPermissions
 *
 * Centralized permission matrix for billing workspace operations.
 * Defines which roles can access, view, edit, and manage billing configurations.
 *
 * Permission Matrix (Phase 3):
 * ┌─────────────────────────────┬───────┬──────────┬───────┬───────────┐
 * │ Action                      │ Admin │ Sub-Admin│ Sales │ Marketing │
 * ├─────────────────────────────┼───────┼──────────┼───────┼───────────┤
 * │ Access Billing Workspace    │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Provider Profiles      │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Market Routing Rules   │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Wallet Rules           │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Subscription Rules     │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Billing System         │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ Edit Billing Settings       │   ✓   │    ✓     │   ✗   │     ✗     │
 * │ View Diagnostics v2         │   ✓   │    ✓     │   ✗   │     ✗     │
 * └─────────────────────────────┴───────┴──────────┴───────┴───────────┘
 *
 * Note: Sales and Marketing roles access diagnostics via Payments page drawer,
 *       not through the Billing workspace in Settings.
 */
class BillingPermissions
{
    /**
     * Roles that have full access to the Billing workspace
     */
    const BILLING_ADMIN_ROLES = ['admin', 'sub_admin'];

    /**
     * Check if user can access the Billing workspace
     *
     * @param User|null $user
     * @return bool
     */
    public static function canAccessBillingWorkspace(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user->role, self::BILLING_ADMIN_ROLES);
    }

    /**
     * Check if user can view provider profiles
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewProviderProfiles(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can view market routing rules
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewRoutingRules(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can view wallet rules
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewWalletRules(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can view subscription rules
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewSubscriptionRules(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can view billing system settings
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewBillingSystem(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can edit billing configurations
     * Phase 4+: This will be used when write operations are implemented
     *
     * @param User|null $user
     * @return bool
     */
    public static function canEditBillingConfig(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        // In Phase 3, only admin can edit. Phase 4 may expand this.
        return $user->role === 'admin';
    }

    /**
     * Check if user can view billing diagnostics
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewBillingDiagnostics(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Check if user can view sensitive data (provider keys, credentials)
     * Phase 3: Only admins and sub-admins see full data
     * Phase 4+: May implement role-based redaction
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewSensitiveData(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Payment-level diagnostics are available to operators, but raw provider payloads
     * stay restricted to billing managers.
     *
     * @param User|null $user
     * @return bool
     */
    public static function canViewRawPaymentDiagnostics(?User $user): bool
    {
        return self::canAccessBillingWorkspace($user);
    }

    /**
     * Route simulation can alter operator decision-making across markets, so keep it
     * on the narrowest admin surface for now.
     *
     * @param User|null $user
     * @return bool
     */
    public static function canUseBillingRouteSimulator(?User $user): bool
    {
        return $user?->role === 'admin';
    }

    /**
     * Cross-market drill-through is broader than normal scoped diagnostics and should
     * remain reserved for admins.
     *
     * @param User|null $user
     * @return bool
     */
    public static function canDrillAcrossBillingMarkets(?User $user): bool
    {
        return $user?->role === 'admin';
    }

    /**
     * Get the list of allowed roles for billing access
     *
     * @return array
     */
    public static function getAllowedRoles(): array
    {
        return self::BILLING_ADMIN_ROLES;
    }

    /**
     * Get a user-friendly description of billing permissions
     *
     * @return string
     */
    public static function getPermissionDescription(): string
    {
        return 'Billing workspace is restricted to administrators and sub-administrators. '
            . 'Sales and Marketing teams access billing diagnostics through the Payments page.';
    }
}
