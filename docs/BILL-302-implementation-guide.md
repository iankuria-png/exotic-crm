# BILL-302 Implementation Guide: Providers Tab

**Ticket:** BILL-302  
**Title:** Implement Providers tab with provider catalog display  
**Phase:** 3 (Billing Workspace Foundation)  
**Effort:** 1-2 days  
**Depends On:** BILL-301 ✅ Complete  
**Status:** Ready to Start  

---

## Overview

Implement a read-only Providers tab that displays the provider catalog with capabilities, markets, currencies, and status. This tab allows admins to inspect all available payment providers without editing credentials.

## Acceptance Criteria

✓ Admins can view all configured payment providers  
✓ Provider capabilities are displayed clearly (surfaces, rails, execution modes)  
✓ Market/currency restrictions shown for each provider  
✓ Implementation status badge visible (Active, Beta, Deprecated)  
✓ Clean card-based layout matching Settings page design  
✓ No editing or credential handling in this tab  
✓ Loading and error states handled gracefully  

---

## Architecture

### Data Flow

```
ProvidersTab Component
  ↓
useQuery ('billing-providers-catalog')
  ↓
GET /crm/billing/providers-catalog (Laravel API)
  ↓
SettingsController::providersCatalog()
  ↓
ProviderRegistry::definitions()
  ↓
Return: { providers: [], families: {} }
```

### API Endpoint

**File:** `routes/api.php`

```php
Route::get('/crm/billing/providers-catalog', [SettingsController::class, 'providersCatalog']);
```

**File:** `app/Http/Controllers/CRM/SettingsController.php`

```php
public function providersCatalog()
{
    // Inject ProviderRegistry from service container
    $registry = $this->container->make(BillingProviderRegistry::class);
    
    return response()->json([
        'providers' => array_map(
            fn (ProviderDefinition $def) => [
                'key' => $def->key,
                'label' => $def->label,
                'family' => $def->family->value,
                'status' => $def->meta('status', 'active'),
                'capabilities' => [
                    'surfaces' => array_map(
                        fn (BillingSurface $s) => $s->value,
                        $def->capabilities->surfaces()
                    ),
                    'rails' => array_map(
                        fn (BillingRail $r) => $r->value,
                        $def->capabilities->rails()
                    ),
                    'execution_modes' => array_map(
                        fn (ExecutionMode $e) => $e->value,
                        $def->capabilities->executionModes()
                    ),
                    'transport_modes' => array_map(
                        fn (TransportMode $t) => $t->value,
                        $def->capabilities->transportModes()
                    ),
                ],
                'countries' => $def->countryCodes,
                'currencies' => $def->currencyCodes,
                'aliases' => $def->aliases,
                'restrictions' => $def->restrictions,
            ],
            $registry->definitions()
        ),
        'families' => array_reduce(
            $registry->definitions(),
            function (array $acc, ProviderDefinition $def) {
                $familyKey = $def->family->value;
                if (!isset($acc[$familyKey])) {
                    $acc[$familyKey] = $def->family->value;
                }
                return $acc;
            },
            []
        ),
    ]);
}
```

---

## File Structure

### New Files to Create

```
resources/js/components/billing/ProvidersTab.jsx
```

### Modified Files

```
routes/api.php (add endpoint)
app/Http/Controllers/CRM/SettingsController.php (add method)
```

### Dependencies

- `ProviderRegistry` (already exists at `app/Billing/Providers/ProviderRegistry.php`)
- `ProviderDefinition` (already exists)
- `BillingProviderRegistry` contract (already exists)
- `BillingSurface`, `BillingRail`, `ExecutionMode`, `TransportMode` enums (already exist)

---

## Component Implementation

### ProvidersTab.jsx Structure

**File:** `resources/js/components/billing/ProvidersTab.jsx`

Key features:
- React Query for data fetching with 5-minute stale time
- Card grid layout (responsive: 1 col mobile → 2 cols tablet → 3 cols desktop)
- Loading skeleton (6 placeholder cards)
- Error state with retry instructions
- Empty state message
- Each provider card shows:
  - Provider name and key (monospace)
  - Family badge (colored)
  - Capabilities (surfaces, rails, execution modes)
  - Supported markets (countries)
  - Supported currencies
  - Status badge (Active/Beta/Deprecated)
- Optional: Provider family legend at bottom

### Styling Guidelines (Tailwind)

**Card Container**
```jsx
className="rounded-lg border border-slate-200 bg-white p-4 hover:shadow-sm transition"
```

**Provider Name**
```jsx
className="text-sm font-semibold text-slate-900"
```

**Key (monospace)**
```jsx
className="text-xs text-slate-600 font-mono"
```

**Capability Badges**
```jsx
className="inline-flex items-center rounded bg-slate-100 px-2 py-1 text-xs text-slate-700"
```

**Family Badge (colored)**
```jsx
className="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700"
```

**Status Badge**
```jsx
// Active
"inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-green-50 text-green-700"

// Beta
"inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-yellow-50 text-yellow-700"

// Deprecated/Inactive
"inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-slate-50 text-slate-700"
```

---

## Implementation Steps

### 1. Create ProvidersTab.jsx Component

Follow the component code at the end of this guide. Key points:
- Import `useQuery` from `@tanstack/react-query`
- Import `api` service
- Query key: `'billing-providers-catalog'`
- Fetch from: `/crm/billing/providers-catalog`
- Stale time: 5 minutes (300,000 ms)
- Handle: loading, error, empty states

### 2. Add API Endpoint

**File:** `routes/api.php`

Add after line with other `/crm/settings` routes:

```php
Route::get('/crm/billing/providers-catalog', [SettingsController::class, 'providersCatalog']);
```

### 3. Implement Controller Method

**File:** `app/Http/Controllers/CRM/SettingsController.php`

Add new method that:
- Resolves `BillingProviderRegistry` from service container
- Gets all provider definitions
- Maps each definition to JSON-safe array with all capabilities
- Returns JSON response with providers array and families map

### 4. Register Component in BillingWorkspace

**File:** `resources/js/components/billing/BillingWorkspace.jsx`

The ProvidersTab should already be imported and used in the providers sub-tab. Verify it renders correctly when the "Providers" sub-tab is clicked.

---

## Testing Checklist

### Visual Testing
- [ ] ProvidersTab renders without errors
- [ ] All provider cards display correctly
- [ ] Card grid responsive on mobile (1 col), tablet (2 cols), desktop (3 cols)
- [ ] Status badges show correct colors (green for active, yellow for beta, gray for deprecated)
- [ ] Capabilities display as inline badges
- [ ] Provider key shown in monospace font
- [ ] Family badge distinct from capability badges

### Functional Testing
- [ ] Query triggers on component mount
- [ ] Data loads and displays within reasonable time
- [ ] Clicking refresh manually refetches data
- [ ] Error state displays when API fails
- [ ] Empty state displays when no providers exist
- [ ] Loading skeleton animates smoothly
- [ ] Stale time respected (5 minutes before automatic refetch)

### Integration Testing
- [ ] Clicking "Providers" sub-tab in Billing workspace shows ProvidersTab
- [ ] Switching away and back to Providers tab maintains cached data
- [ ] No console errors or warnings
- [ ] Responsive on different screen sizes
- [ ] No performance degradation with large provider lists

### Regression Testing
- [ ] Other Billing workspace tabs still work
- [ ] Settings page non-billing tabs unaffected
- [ ] No breaking changes to existing functionality

---

## Data Contract

### Response Format

```json
{
  "providers": [
    {
      "key": "mpesa_daraja",
      "label": "M-Pesa (Daraja API)",
      "family": "stk_push",
      "status": "active",
      "capabilities": {
        "surfaces": ["wallet_funding", "subscription"],
        "rails": ["mpesa"],
        "execution_modes": ["stk_push", "redirect"],
        "transport_modes": ["api"]
      },
      "countries": ["KE"],
      "currencies": ["KES"],
      "aliases": ["daraja", "safaricom_api"],
      "restrictions": {
        "tier_minimum": "premium"
      }
    },
    {
      "key": "paypal_hosted",
      "label": "PayPal Hosted Checkout",
      "family": "hosted_checkout",
      "status": "beta",
      "capabilities": {
        "surfaces": ["wallet_funding", "subscription"],
        "rails": ["card", "wallet"],
        "execution_modes": ["redirect"],
        "transport_modes": ["api"]
      },
      "countries": ["US", "GB", "AU"],
      "currencies": ["USD", "GBP", "AUD"],
      "aliases": ["paypal"],
      "restrictions": {}
    }
  ],
  "families": {
    "stk_push": "STK Push",
    "hosted_checkout": "Hosted Checkout",
    "direct_api": "Direct API",
    "crypto": "Cryptocurrency"
  }
}
```

---

## Component Code

See `resources/js/components/billing/ProvidersTab.jsx` (example included in this guide).

Key structure:
```jsx
export default function ProvidersTab() {
  const { data, isLoading, isError, error } = useQuery({...});
  
  // Handle loading, error, empty states
  // Map providers to cards
  // Display provider details: name, family, capabilities, markets, currencies, status
  // Optional: family legend
}
```

---

## Error Handling

### API Errors
- Catch in useQuery error state
- Display user-friendly message
- Show error details (error.message)
- Suggest retry action

### No Providers
- Display "No providers available" message
- Suggest that provider definitions need configuration

### Network Issues
- React Query auto-retry (retry: 1)
- Manual refresh button available
- User can see last known state during retry

---

## Performance Considerations

- **Stale time:** 5 minutes (reduce API calls)
- **Retry:** 1 attempt on failure
- **Caching:** React Query automatic deduplication
- **Rendering:** Card grid with CSS Grid (performant)
- **Loading:** Skeleton loader (feels fast)

---

## Accessibility

- Semantic HTML (buttons, divs as needed)
- Color not sole indicator (use text + icons if possible)
- Loading state announced to screen readers
- Error messages descriptive and actionable

---

## Notes

1. **No Editing:** This is a read-only reference tab. Credential editing happens in BILL-303.
2. **Provider Key:** Show provider key in monospace for developer reference.
3. **Capabilities Matrix:** Shows which surfaces/rails/modes each provider supports.
4. **Market Scope:** Countries and currencies help admins understand geographical limitations.
5. **Status Tracking:** Active/Beta/Deprecated status influences rollout decisions.

---

## Next Steps After BILL-302

Once ProvidersTab is implemented and tested:

1. **BILL-303:** Implement Provider Profiles tab
   - CRUD forms for provider credentials
   - Multiple profiles per provider
   - Market scoping
   - Secret masking

2. **BILL-304:** Implement Market Routing tab
   - Routing rule configuration
   - Primary/fallback chain setup

3. **BILL-305/306:** Wallet Rules and System Settings tabs (parallel)

---

## Frequently Asked Questions

**Q: Why is this read-only in Phase 3?**  
A: To validate the UI/UX before implementing write-backs in Phase 4. Reduces risk of data divergence during development.

**Q: Can admins test providers from this tab?**  
A: No, testing happens in BILL-303 (Provider Profiles tab) where credentials are configured.

**Q: How often does the provider list update?**  
A: Every 5 minutes (stale time). Manual refresh available. Server-side changes appear on next query.

**Q: Are there restrictions on who can see this tab?**  
A: Yes, permissions enforced in SettingsController::providersCatalog() - recommend `admin` and `sub_admin` roles.

---

## Estimation

- Component creation: 2-3 hours
- API endpoint + controller method: 1-2 hours
- Testing: 1-2 hours
- **Total: 1-2 days**

---

**Ready to implement BILL-302? Start with ProvidersTab.jsx component.**
