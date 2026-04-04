# Quick Start: BILL-302 Implementation

**Time to Read:** 5 minutes  
**Time to Implement:** 1-2 days  
**Difficulty:** Medium  

---

## TL;DR

Implement a read-only provider catalog display tab that:
- Shows all available payment providers
- Displays capabilities (surfaces, rails, execution modes)
- Shows market/currency restrictions
- Lists implementation status (Active/Beta)

**Result:** Admins can inspect provider capabilities without editing.

---

## 3-Step Implementation

### Step 1: Create Component (1 hour)

**File:** `resources/js/components/billing/ProvidersTab.jsx`

```jsx
import React from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/services/api';

export default function ProvidersTab() {
  const { data = {}, isLoading, isError, error } = useQuery({
    queryKey: ['billing-providers-catalog'],
    queryFn: () => api.get('/crm/billing/providers-catalog').then((res) => res.data),
    staleTime: 5 * 60 * 1000,
  });

  if (isLoading) return <div className="space-y-4 p-5 animate-pulse">Loading...</div>;
  if (isError) return <div className="p-5 text-rose-700">Error loading providers</div>;

  const providers = data.providers || [];

  return (
    <div className="space-y-6 p-5">
      <div>
        <h3 className="text-lg font-semibold text-slate-900">Provider Catalog</h3>
        <p className="mt-1 text-sm text-slate-600">View available payment providers and their capabilities.</p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {providers.map((provider) => (
          <div key={provider.key} className="rounded-lg border border-slate-200 bg-white p-4 hover:shadow-sm">
            <h4 className="text-sm font-semibold text-slate-900">{provider.label}</h4>
            <p className="mt-1 text-xs text-slate-600 font-mono">{provider.key}</p>
            
            {provider.family && (
              <span className="mt-3 inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                {provider.family}
              </span>
            )}

            {provider.capabilities?.surfaces && (
              <div className="mt-3 space-y-1">
                <p className="text-xs font-medium text-slate-700">Surfaces</p>
                <div className="flex flex-wrap gap-1">
                  {provider.capabilities.surfaces.map((surface) => (
                    <span key={surface} className="inline-flex rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">
                      {surface}
                    </span>
                  ))}
                </div>
              </div>
            )}

            {provider.countries && (
              <div className="mt-3">
                <p className="text-xs font-medium text-slate-700">Markets</p>
                <p className="text-xs text-slate-600">{provider.countries.join(', ')}</p>
              </div>
            )}

            {provider.status && (
              <div className="mt-4 pt-4 border-t border-slate-200">
                <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                  provider.status === 'active' ? 'bg-green-50 text-green-700' :
                  provider.status === 'beta' ? 'bg-yellow-50 text-yellow-700' :
                  'bg-slate-50 text-slate-700'
                }`}>
                  {provider.status.charAt(0).toUpperCase() + provider.status.slice(1)}
                </span>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
```

### Step 2: Add API Endpoint (30 minutes)

**File:** `routes/api.php` (add this line after other billing routes):

```php
Route::get('/crm/billing/providers-catalog', [SettingsController::class, 'providersCatalog']);
```

**File:** `app/Http/Controllers/CRM/SettingsController.php` (add this method):

```php
use App\Billing\Contracts\BillingProviderRegistry;

public function providersCatalog(BillingProviderRegistry $registry)
{
    return response()->json([
        'providers' => array_map(
            fn ($def) => [
                'key' => $def->key,
                'label' => $def->label,
                'family' => $def->family->value ?? null,
                'status' => $def->meta('status', 'active'),
                'capabilities' => [
                    'surfaces' => array_map(fn ($s) => $s->value, $def->capabilities->surfaces()),
                    'rails' => array_map(fn ($r) => $r->value, $def->capabilities->rails()),
                ],
                'countries' => $def->countryCodes,
                'currencies' => $def->currencyCodes,
            ],
            $registry->definitions()
        ),
    ]);
}
```

### Step 3: Test & Commit (30 minutes)

```bash
# Test in browser
npm run dev
# Navigate to: localhost:8000/settings
# Click: Billing tab → Providers sub-tab
# Verify: Providers load, cards display, no errors

# Commit
git add resources/js/components/billing/ProvidersTab.jsx
git add routes/api.php
git add app/Http/Controllers/CRM/SettingsController.php
git commit -m "feat: BILL-302 — implement provider catalog display tab"
```

---

## What to Test

✅ Providers load and display  
✅ Cards responsive (1/2/3 columns)  
✅ Capabilities show correctly  
✅ Status badges show correct colors  
✅ No console errors  

---

## Next Ticket

Once BILL-302 is merged, start **BILL-303** (Provider Profiles):
- CRUD forms for credentials
- Multi-profile support
- Secret masking
- Test button

---

## Help

- **Component guide:** `docs/BILL-302-implementation-guide.md`
- **Full spec:** `docs/phase-3-next-steps-2026-04-04.md`
- **ProviderRegistry:** `app/Billing/Providers/ProviderRegistry.php`

---

**Ready? Start with Step 1 now.**
