# BILL-301 Implementation: Add Billing Workspace Shell to Settings

**Ticket:** BILL-301  
**Title:** Add Billing workspace shell to Settings  
**Phase:** 3 (Billing Workspace Foundation)  
**Effort:** 1 day  
**Status:** ⏳ Ready to Start  

---

## Overview

Extract Billing workspace as a nested tab group inside the existing Settings page, maintaining consistency with current Settings UI/UX patterns (tabs, cards, status indicators, refresh buttons).

## Acceptance Criteria

✓ Existing non-billing settings stay unchanged  
✓ Billing workspace is feature-flagged (`BILLING_WORKSPACE_ENABLED`)  
✓ Billing workspace appears as a new tab in the Settings tab navigation  
✓ Sub-tabs render correctly inside Billing workspace  
✓ Settings page loads without error  

## Design Patterns (Must Follow Current Settings Page)

### Current Settings Page Structure
```
Settings
├── Heading: "Settings"
├── Description: "Configure integrations, templates, and operational controls."
│
├── Tab Navigation (horizontal)
│   ├── Integrations (active)
│   ├── Templates
│   ├── Webhook Logs
│   ├── Roles & Permissions
│   ├── Dashboard
│   └── System Health
│
└── Tab Content (cards, status indicators, refresh buttons)
```

### New Billing Tab Structure (BILL-301)
```
Settings
├── Heading: "Settings" (unchanged)
├── Description: (unchanged)
│
├── Tab Navigation (horizontal)
│   ├── Integrations
│   ├── Templates
│   ├── Webhook Logs
│   ├── Roles & Permissions
│   ├── Dashboard
│   ├── System Health
│   └── [NEW] Billing ← Feature-flagged
│
└── Tab Content
    └── When "Billing" tab is active:
        ├── Heading: "Billing Configuration"
        ├── Description: "Manage payment providers, routing rules, and wallet settings."
        │
        ├── Sub-Tab Navigation (horizontal)
        │   ├── Providers (BILL-302)
        │   ├── Provider Profiles (BILL-303)
        │   ├── Market Routing (BILL-304)
        │   ├── Wallet Rules (BILL-305)
        │   └── System Settings (BILL-306)
        │
        └── Sub-Tab Content (cards, status indicators)
```

## Implementation Steps

### 1. Update Settings.jsx

**File:** `resources/js/pages/Settings.jsx`

**Changes:**
- Import `BillingWorkspace` component
- Add "Billing" tab to tab navigation (conditionally based on feature flag)
- Render `<BillingWorkspace />` when Billing tab is active
- Maintain all existing tab logic and styling

**Code Pattern:**
```jsx
import { useFeatureFlag } from '@/hooks/useFeatureFlag';
import BillingWorkspace from '@/components/billing/BillingWorkspace';

export default function Settings() {
  const [activeTab, setActiveTab] = useState('integrations');
  const billingEnabled = useFeatureFlag('BILLING_WORKSPACE_ENABLED');

  const tabs = [
    { id: 'integrations', label: 'Integrations', component: <IntegrationsTab /> },
    { id: 'templates', label: 'Templates', component: <TemplatesTab /> },
    { id: 'webhook_logs', label: 'Webhook Logs', component: <WebhookLogsTab /> },
    { id: 'roles_permissions', label: 'Roles & Permissions', component: <RolesTab /> },
    { id: 'dashboard', label: 'Dashboard', component: <DashboardTab /> },
    { id: 'system_health', label: 'System Health', component: <SystemHealthTab /> },
  ];

  // Add Billing tab if feature-flagged
  if (billingEnabled) {
    tabs.push({ id: 'billing', label: 'Billing', component: <BillingWorkspace /> });
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900">Settings</h1>
        <p className="mt-2 text-gray-600">Configure integrations, templates, and operational controls.</p>
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-gray-200">
        <div className="flex gap-8">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`py-3 px-0 text-sm font-medium border-b-2 transition ${
                activeTab === tab.id
                  ? 'border-teal-500 text-teal-600'
                  : 'border-transparent text-gray-700 hover:text-gray-900 hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tab Content */}
      <div>
        {tabs.find((tab) => tab.id === activeTab)?.component}
      </div>
    </div>
  );
}
```

### 2. Create BillingWorkspace.jsx

**File:** `resources/js/components/billing/BillingWorkspace.jsx`

**Purpose:** Container component for all Billing sub-tabs, following the same tab pattern as Settings.jsx

**Structure:**
```jsx
import { useState } from 'react';
import ProvidersTab from './ProvidersTab';
import ProviderProfilesTab from './ProviderProfilesTab';
import MarketRoutingTab from './MarketRoutingTab';
import WalletRulesTab from './WalletRulesTab';
import SystemSettingsTab from './SystemSettingsTab';

export default function BillingWorkspace() {
  const [activeSubTab, setActiveSubTab] = useState('providers');

  const subTabs = [
    { id: 'providers', label: 'Providers', component: <ProvidersTab /> },
    { id: 'provider_profiles', label: 'Provider Profiles', component: <ProviderProfilesTab /> },
    { id: 'market_routing', label: 'Market Routing', component: <MarketRoutingTab /> },
    { id: 'wallet_rules', label: 'Wallet Rules', component: <WalletRulesTab /> },
    { id: 'system_settings', label: 'System Settings', component: <SystemSettingsTab /> },
  ];

  return (
    <div className="space-y-6">
      {/* Billing Workspace Header */}
      <div>
        <h2 className="text-2xl font-bold text-gray-900">Billing Configuration</h2>
        <p className="mt-1 text-gray-600">Manage payment providers, routing rules, and wallet settings.</p>
      </div>

      {/* Sub-Tab Navigation */}
      <div className="border-b border-gray-200">
        <div className="flex gap-8">
          {subTabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveSubTab(tab.id)}
              className={`py-3 px-0 text-sm font-medium border-b-2 transition ${
                activeSubTab === tab.id
                  ? 'border-teal-500 text-teal-600'
                  : 'border-transparent text-gray-700 hover:text-gray-900 hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Sub-Tab Content */}
      <div>
        {subTabs.find((tab) => tab.id === activeSubTab)?.component}
      </div>
    </div>
  );
}
```

### 3. Create Shared BillingCard Component

**File:** `resources/js/components/billing/shared/BillingCard.jsx`

**Purpose:** Reusable card component matching the Settings page card design, used by all BILL-3xx tabs.

```jsx
export default function BillingCard({
  title,
  description,
  status = null,
  statusLabel = null,
  onRefresh = null,
  isLoading = false,
  children,
  footer = null,
}) {
  const statusStyles = {
    pass: 'bg-green-50 text-green-700',
    healthy: 'bg-green-50 text-green-700',
    configured_disabled: 'bg-amber-50 text-amber-700',
    stale: 'bg-gray-50 text-gray-700',
    warning: 'bg-yellow-50 text-yellow-700',
  };

  return (
    <div className="bg-white border border-gray-200 rounded-lg p-6 hover:shadow-sm transition">
      <div className="flex items-start justify-between mb-4">
        <div className="flex-1">
          <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
          {description && <p className="mt-1 text-sm text-gray-600">{description}</p>}
        </div>

        {onRefresh && (
          <button
            onClick={onRefresh}
            disabled={isLoading}
            className="ml-4 px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
          >
            {isLoading ? 'Refreshing...' : 'Refresh'}
          </button>
        )}
      </div>

      {children && <div className="mb-4">{children}</div>}

      <div className="flex items-center justify-between">
        {status && (
          <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${statusStyles[status] || statusStyles.pass}`}>
            {statusLabel || status.toUpperCase()}
          </span>
        )}

        {footer && <div className="ml-auto text-sm text-gray-600">{footer}</div>}
      </div>
    </div>
  );
}
```

### 4. Feature Flag Configuration

**File:** `config/features.php` (or equivalent)

```php
return [
    'billing' => [
        'workspace_enabled' => env('BILLING_WORKSPACE_ENABLED', false),
        'edit_enabled' => env('BILLING_EDIT_ENABLED', false),
        'dual_write_enabled' => env('BILLING_DUAL_WRITE_ENABLED', false),
    ],
];
```

**File:** `.env` (for local development)

```env
BILLING_WORKSPACE_ENABLED=true
BILLING_EDIT_ENABLED=false
BILLING_DUAL_WRITE_ENABLED=false
```

### 5. Hook: useFeatureFlag

**File:** `resources/js/hooks/useFeatureFlag.js`

```javascript
import { useQuery } from '@tanstack/react-query';

export function useFeatureFlag(flagName) {
  const { data = {} } = useQuery({
    queryKey: ['feature-flags'],
    queryFn: async () => {
      const response = await fetch('/api/crm/feature-flags');
      return response.json();
    },
  });

  return data[flagName] ?? false;
}
```

### 6. Backend: Feature Flag Endpoint

**File:** `app/Http/Controllers/CRM/SettingsController.php`

```php
public function featureFlags()
{
    return response()->json([
        'BILLING_WORKSPACE_ENABLED' => config('features.billing.workspace_enabled'),
        'BILLING_EDIT_ENABLED' => config('features.billing.edit_enabled'),
        'BILLING_DUAL_WRITE_ENABLED' => config('features.billing.dual_write_enabled'),
    ]);
}
```

**File:** `routes/api.php`

```php
Route::get('/crm/feature-flags', [SettingsController::class, 'featureFlags']);
```

---

## Testing Checklist

### Visual Testing
- [ ] Settings page loads without error
- [ ] Billing tab appears when `BILLING_WORKSPACE_ENABLED=true`
- [ ] Billing tab is hidden when `BILLING_WORKSPACE_ENABLED=false`
- [ ] Tab styling matches existing Settings tabs (color, font, spacing)
- [ ] Sub-tabs render correctly inside Billing workspace
- [ ] Sub-tab active state styling is consistent

### Functional Testing
- [ ] Clicking Billing tab shows workspace content
- [ ] Clicking sub-tabs switches content
- [ ] Feature flag toggle works (enable/disable workspace)
- [ ] No console errors on page load or tab switch
- [ ] Browser back/forward works with tab navigation

### Regression Testing
- [ ] All existing Settings tabs still work
- [ ] Non-billing functionality unaffected
- [ ] No layout shift when Billing tab appears/disappears
- [ ] No performance degradation

---

## Styling Reference (Tailwind)

Match these exact classes from current Settings page:

**Page Container**
```jsx
<div className="space-y-6">
```

**Heading**
```jsx
<h1 className="text-3xl font-bold text-gray-900">Settings</h1>
<h2 className="text-2xl font-bold text-gray-900">Billing Configuration</h2>
```

**Description**
```jsx
<p className="mt-2 text-gray-600">Configure integrations, templates, and operational controls.</p>
<p className="mt-1 text-gray-600">Manage payment providers, routing rules, and wallet settings.</p>
```

**Tab Navigation**
```jsx
<div className="border-b border-gray-200">
  <div className="flex gap-8">
    {/* tabs */}
  </div>
</div>
```

**Tab Button Active**
```jsx
className="py-3 px-0 text-sm font-medium border-b-2 border-teal-500 text-teal-600 transition"
```

**Tab Button Inactive**
```jsx
className="py-3 px-0 text-sm font-medium border-b-2 border-transparent text-gray-700 hover:text-gray-900 hover:border-gray-300 transition"
```

---

## Files to Modify/Create

### New Files
- `resources/js/components/billing/BillingWorkspace.jsx`
- `resources/js/components/billing/ProvidersTab.jsx` (stub)
- `resources/js/components/billing/ProviderProfilesTab.jsx` (stub)
- `resources/js/components/billing/MarketRoutingTab.jsx` (stub)
- `resources/js/components/billing/WalletRulesTab.jsx` (stub)
- `resources/js/components/billing/SystemSettingsTab.jsx` (stub)
- `resources/js/components/billing/shared/BillingCard.jsx`
- `resources/js/hooks/useFeatureFlag.js`

### Modified Files
- `resources/js/pages/Settings.jsx`
- `app/Http/Controllers/CRM/SettingsController.php`
- `routes/api.php`
- `config/features.php` (or create if doesn't exist)
- `.env` (local development)

---

## Stub Implementation for Sub-Tabs

For BILL-301 completion, create minimal stub components that render placeholder content:

**Example stub (repeat for all 5 sub-tabs):**

```jsx
// resources/js/components/billing/ProvidersTab.jsx
export default function ProvidersTab() {
  return (
    <div className="text-center py-8">
      <p className="text-gray-500">Providers tab - Coming in BILL-302</p>
    </div>
  );
}
```

---

## QA Sign-Off

Before moving to BILL-302:

- [ ] All feature flags working correctly
- [ ] Tab navigation smooth and responsive
- [ ] No layout issues on different screen sizes
- [ ] Existing Settings functionality preserved
- [ ] Code review approved
- [ ] Ready for next ticket (BILL-302)

---

## Next Steps After BILL-301

Once BILL-301 is merged and tested:

1. **BILL-302:** Implement ProvidersTab with provider catalog display
2. **BILL-303:** Implement ProviderProfilesTab with CRUD forms
3. **BILL-304:** Implement MarketRoutingTab with routing configuration
4. **BILL-305:** Implement WalletRulesTab (parallel with 301)
5. **BILL-306:** Implement SystemSettingsTab (parallel with 301)

---

## Notes for Developer

- Keep Settings.jsx changes minimal - only add Billing tab logic
- Use existing color scheme (teal-500 for active state, gray-600 for descriptions)
- Follow existing spacing/padding from other tabs (appears to be Tailwind defaults)
- Tab content should have consistent left/right margins matching other tabs
- Status indicators follow the same pattern as System Health tab (colored badges)
- All components should be responsive (work on mobile too)

**Start with BILL-301, get it merged, then proceed to sub-tab implementations in parallel.**
