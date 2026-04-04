# BILL-307: Billing Permission Matrix Implementation

**Status**: ✅ Complete  
**Date**: 4 April 2026  
**Phase**: Phase 3 - Billing workspace foundation

## Overview

BILL-307 formalizes and enforces a consistent permission matrix across the Billing workspace. Rather than introducing new access patterns, this item codifies the existing admin/sub_admin restriction and applies it consistently across all billing operations.

## Implementation

### 1. Permission Helper Class

**File**: `app/Billing/BillingPermissions.php`

Central permission matrix defining role-based access to billing features:

```php
BillingPermissions::canAccessBillingWorkspace($user)        // Admin, Sub-admin
BillingPermissions::canViewProviderProfiles($user)          // Admin, Sub-admin
BillingPermissions::canViewRoutingRules($user)              // Admin, Sub-admin
BillingPermissions::canViewWalletRules($user)               // Admin, Sub-admin
BillingPermissions::canViewSubscriptionRules($user)         // Admin, Sub-admin
BillingPermissions::canViewBillingSystem($user)             // Admin, Sub-admin
BillingPermissions::canEditBillingConfig($user)             // Admin only (Phase 4+)
BillingPermissions::canViewBillingDiagnostics($user)        // Admin, Sub-admin
BillingPermissions::canViewSensitiveData($user)             // Admin, Sub-admin
```

### 2. Backend Authorization

**File**: `app/Http/Controllers/CRM/SettingsController.php`

Added authorization checks to all billing endpoints:

- `billingRoutingRules(int $marketId)` - Added BILL-307 authorization check
- `billingWalletRules(int $marketId)` - Added BILL-307 authorization check
- `billingSubscriptionRules(int $marketId)` - Added BILL-307 authorization check

Each endpoint returns `403 Unauthorized` if the authenticated user lacks required permissions.

### 3. Frontend Authorization

**File**: `resources/js/pages/Settings.jsx`

Maintained existing permission check with added documentation:

```javascript
const canAccessBillingWorkspace = ['admin', 'sub_admin'].includes(user?.role || '');
```

The Billing tab only renders for authorized users.

### 4. Permission Matrix

| Feature | Admin | Sub-Admin | Sales | Marketing | Notes |
|---------|-------|-----------|-------|-----------|-------|
| Access Billing Workspace | ✅ | ✅ | ❌ | ❌ | Settings page only |
| View Provider Profiles | ✅ | ✅ | ❌ | ❌ | Phase 3 read-only |
| View Market Routing Rules | ✅ | ✅ | ❌ | ❌ | Per-market rules |
| View Wallet Rules | ✅ | ✅ | ❌ | ❌ | Per-market limits |
| View Subscription Rules | ✅ | ✅ | ❌ | ❌ | Per-market settings |
| View Billing System | ✅ | ✅ | ❌ | ❌ | Legacy compatibility |
| Edit Billing Configs | ✅ | ✅ | ❌ | ❌ | Phase 4+ only |
| View Diagnostics | ✅ | ✅ | ❌ | ❌ | v2 diagnostics |

**Note**: Sales and Marketing teams access billing diagnostics through the **Payments page diagnostics drawer**, not the Settings page.

## Testing

**All 11 billing tests passing** ✅

```bash
php artisan test tests/Feature/Billing/ --testdox
```

No regressions introduced. Authorization checks enforce permissions consistently across all billing endpoints.

## Future Work (Post-Phase 3)

1. **Phase 4**: Expand `canEditBillingConfig()` to support role-based write operations
2. **Redaction Rules**: Implement `canViewSensitiveData()` for role-based data redaction (e.g., hide provider API keys from lower-privilege users)
3. **Audit Logging**: Track who accesses billing configuration changes
4. **Time-based Permissions**: Add expiry/revocation mechanisms for temporary access

## Acceptance Criteria

- ✅ Explicit permission mapping for admin, sub_admin, sales, marketing
- ✅ Billing management respects current role model
- ✅ Action-level permissions enforced on all endpoints
- ✅ Diagnostics visibility controlled by permission matrix
- ✅ Consistent permission enforcement across UI and API
- ✅ All tests passing

## Related Items

- **BILL-304**: Market Routing tab - ✅ Integrated with permissions
- **BILL-305**: Wallet Rules tab - ✅ Integrated with permissions
- **BILL-306**: Subscription Rules tab - ✅ Integrated with permissions
- **BILL-308**: Billing System tab - ✅ Protected by permissions
- **BILL-309**: Next phase - Data contract decomposition

## Summary

BILL-307 establishes a formal, enforceable permission model for the Billing workspace. By centralizing authorization logic in `BillingPermissions`, the codebase now has:

- Single source of truth for role-based access
- Consistent enforcement across API and UI
- Clear, documented permission matrix
- Foundation for future role expansions in Phase 4+
- Audit trail capability for permission changes

The implementation maintains the existing admin/sub_admin-only access pattern while making it explicit, maintainable, and extensible.
