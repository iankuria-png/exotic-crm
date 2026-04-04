# Phase 3 Roadmap: Billing Workspace Foundation
**Generated:** 4 April 2026  
**Previous Phase:** BILL-203, BILL-204 ✅ Complete  
**Next Phase:** BILL-301 → BILL-306 (Billing Workspace UI)  
**Source:** `docs/payment-billing-implementation-backlog-2026-04-03.md`  

---

## Phase 3 Goal

Build a safe Billing workspace on top of the revised model that is feature-flagged, read-only or dual-writing until runtime cutover is ready.

## Phase 3 Exit Gates

✓ Billing tabs render through extracted components  
✓ Provider schemas drive forms  
✓ Billing tabs have distinct data contracts, query keys, and failure states  
✓ Permissions and redaction rules are enforced consistently  
✓ Saves either dual-write safely or remain read-only  

---

## Phase 3 Tickets (Ordered Priority)

### BILL-301: Add Billing workspace shell to Settings ⭐ START HERE

**Type:** Foundation / Infrastructure  
**Effort:** 1 day  
**Blocker Status:** None - ready to start

#### Requirements
- Extract Billing workspace as tab group inside existing Settings page
- Maintain existing non-billing settings unchanged
- Add feature flag: `BILLING_WORKSPACE_ENABLED`
- Create new component structure

#### Primary Write Scope
```
resources/js/pages/Settings.jsx
resources/js/components/billing/BillingWorkspace.jsx (new)
```

#### Secondary Touch Points
```
routes/api.php
app/Http/Controllers/CRM/SettingsController.php
```

#### Deliverables
- Billing workspace renders as tab group inside Settings
- Non-billing settings remain functional
- Feature flag guards workspace visibility

#### Acceptance Criteria
✓ Existing non-billing settings stay unchanged  
✓ Billing workspace is feature-flagged  
✓ Settings page loads without error  

#### UI Structure (Recommended)
```
Settings Page
├── General Settings Tab (existing)
├── Users Tab (existing)
├── Integrations Tab (existing)
└── [NEW] Billing Workspace Tab
    ├── Providers Tab (BILL-302)
    ├── Provider Profiles Tab (BILL-303)
    ├── Market Routing Tab (BILL-304)
    ├── Wallet Rules Tab (BILL-305)
    └── System Settings Tab (BILL-306)
```

---

### BILL-302: Implement Providers tab

**Type:** UI Feature  
**Effort:** 1 day  
**Depends On:** BILL-301

#### Requirements
- Display provider catalog with capability matrix
- Show implementation status (new, legacy, deprecated)
- Read-only view for inspection
- No editing or credential handling at this stage

#### Primary Write Scope
```
resources/js/components/billing/ProvidersTab.jsx (new)
app/Http/Controllers/CRM/SettingsController.php
```

#### Secondary Touch Points
```
app/Billing/Providers/ProviderRegistry.php
```

#### Deliverables
- Provider catalog display component
- API endpoint: `GET /api/crm/billing/providers`
- Capability matrix rendering

#### Acceptance Criteria
✓ Admins can inspect provider types without editing credentials  
✓ Capability columns are sortable/filterable  
✓ Status indicators show implementation readiness  

#### Data Contract (API Response)
```json
{
  "providers": [
    {
      "key": "mpesa_daraja",
      "name": "M-Pesa (Daraja API)",
      "category": "stk",
      "capabilities": {
        "billing_surface": ["wallet_funding", "subscription"],
        "rails": ["mpesa"],
        "transport": "api",
        "settlement_model": "t+1",
        "supported_currencies": ["KES"],
        "status": "production"
      },
      "markets": ["KE"],
      "implementation_status": "active"
    }
  ]
}
```

---

### BILL-303: Implement Provider Profiles tab

**Type:** UI Feature  
**Effort:** 2 days  
**Depends On:** BILL-301, BILL-302

#### Requirements
- Create/edit provider profiles (credentials, routing rules)
- Multi-profile support per provider
- Market scoping per profile
- Credential masking and validation
- Secrets stored in new `billing_provider_profiles` table

#### Primary Write Scope
```
resources/js/components/billing/ProviderProfilesTab.jsx (new)
app/Http/Controllers/CRM/SettingsController.php
app/Billing/Providers/ProviderSchemaRegistry.php
```

#### Secondary Touch Points
```
app/Models/BillingProviderProfile.php
```

#### Deliverables
- Profile CRUD forms driven by provider schemas
- API endpoints:
  - `GET /api/crm/billing/provider-profiles`
  - `POST /api/crm/billing/provider-profiles`
  - `PUT /api/crm/billing/provider-profiles/{id}`
  - `DELETE /api/crm/billing/provider-profiles/{id}`
- Credential masking on read
- Test credential flow

#### Acceptance Criteria
✓ Multiple profiles per provider and market scope are supported  
✓ Secrets remain masked after save  
✓ Form validation prevents incomplete profiles  
✓ Test button validates credentials before save  

#### Data Model (Database)
```
billing_provider_profiles
├── id
├── provider_type_key (e.g., "mpesa_daraja")
├── market_code (nullable - applies to specific market or all)
├── label (e.g., "Primary Daraja Account")
├── credentials_json (encrypted: api_key, api_secret, etc.)
├── is_active
├── tested_at
├── created_at / updated_at
```

---

### BILL-304: Implement Market Routing tab

**Type:** UI Feature  
**Effort:** 2-3 days  
**Depends On:** BILL-301, BILL-303

#### Requirements
- Configure provider routing per market
- Define routing rules (priority, conditions, fallbacks)
- Set default payment method per market
- Test routing without modifying active config

#### Primary Write Scope
```
resources/js/components/billing/MarketRoutingTab.jsx (new)
app/Http/Controllers/CRM/SettingsController.php
app/Billing/Routing/RoutingRuleBuilder.php
```

#### Secondary Touch Points
```
app/Models/BillingRoutingRule.php
```

#### Deliverables
- Routing configuration form per market
- API endpoints:
  - `GET /api/crm/billing/routing-rules/{market}`
  - `PUT /api/crm/billing/routing-rules/{market}`
- Routing test endpoint
- Conflict detection and warnings

#### Acceptance Criteria
✓ Routing rules are scoped per market  
✓ Fallback chains can be configured  
✓ Test button shows which provider would route for test transaction  

---

### BILL-305: Implement Wallet Rules tab

**Type:** UI Feature  
**Effort:** 2 days  
**Depends On:** BILL-301

#### Requirements
- Configure wallet funding policies
- Set min/max transaction limits
- Configure auto-renewal rules
- Manage wallet fee structures

#### Primary Write Scope
```
resources/js/components/billing/WalletRulesTab.jsx (new)
app/Http/Controllers/CRM/SettingsController.php
```

#### Secondary Touch Points
```
app/Models/BillingWalletRule.php
```

#### Deliverables
- Wallet rule configuration form
- API endpoints for wallet policy management

#### Acceptance Criteria
✓ Wallet limits are enforced correctly  
✓ Auto-renewal policies save without error  

---

### BILL-306: Implement System Settings tab

**Type:** UI Feature  
**Effort:** 1-2 days  
**Depends On:** BILL-301

#### Requirements
- Global billing configuration
- Email/SMTP settings for billing notifications
- Webhook retry policies
- Settlement preferences
- Sandbox/test mode controls

#### Primary Write Scope
```
resources/js/components/billing/SystemSettingsTab.jsx (new)
app/Http/Controllers/CRM/SettingsController.php
```

#### Secondary Touch Points
```
app/Models/BillingSystemSetting.php
```

#### Deliverables
- System settings form
- API endpoints for settings persistence

#### Acceptance Criteria
✓ Settings persist and reload correctly  
✓ Sandbox mode toggle works without error  

---

## Architecture & Technical Decisions

### Feature Flagging
```php
// Use this in all BILL-3xx work
if (config('billing.workspace_enabled', false)) {
    // Show Billing workspace
}
```

### Data Fetching Strategy
- Use React Query (TanStack Query) for caching
- Distinct query keys per tab to prevent cross-talk
- Optimistic updates for form submission
- Error boundaries per tab

### Write Safety During Phase 3
**Option 1 (Safer for Phase 3):** Read-only mode
- Forms present configuration in read-only state
- Edit mode behind separate BILLING_EDIT_ENABLED flag
- No actual writes to database until Phase 4

**Option 2 (Dual-write):** Safe dual-write
- Writes go to new `billing_*` tables
- Legacy config projected from new tables (BILL-203)
- Runtime still reads legacy config during Phase 3
- Cutover happens in Phase 4

**Recommendation:** Start with Option 1 (read-only), implement Option 2 dual-write after BILL-301 is stable.

### Component Structure
```
resources/js/components/billing/
├── BillingWorkspace.jsx (main container)
├── ProvidersTab.jsx
├── ProviderProfilesTab.jsx
├── MarketRoutingTab.jsx
├── WalletRulesTab.jsx
├── SystemSettingsTab.jsx
└── shared/
    ├── BillingForm.jsx
    ├── CredentialInput.jsx
    ├── SchemaFieldRenderer.jsx
    └── ValidationErrorDisplay.jsx
```

---

## Estimated Timeline

| Ticket | Effort | Status |
|--------|--------|--------|
| BILL-301 | 1 day | ⏳ Ready to Start |
| BILL-302 | 1 day | ⏳ After 301 |
| BILL-303 | 2 days | ⏳ After 302 |
| BILL-304 | 2-3 days | ⏳ After 303 |
| BILL-305 | 2 days | ⏳ After 301 (parallel OK) |
| BILL-306 | 1-2 days | ⏳ After 301 (parallel OK) |
| **Phase 3 Total** | **9-10 days** | **~ 2 weeks with testing** |

---

## Dependencies & Blockers

✅ **All Phase 2 work is complete:**
- Database migrations: ✅ BILL-201
- Billing models: ✅ BILL-202
- Legacy config projector: ✅ BILL-203
- Migration command: ✅ BILL-204

✅ **Ready to start BILL-301 immediately**

🔗 **No external blockers**

---

## Success Criteria for Phase 3 Completion

1. All 6 UI tickets (BILL-301 through BILL-306) merged and deployed
2. Billing workspace renders without errors with feature flag enabled
3. All forms display configuration correctly
4. No regressions in existing Settings page functionality
5. API contracts match spec (see data models above)
6. Permissions enforced consistently across all tabs
7. Error states handled gracefully with user-friendly messages

---

## Notes for Next Developer

### Before Starting BILL-301
1. Pull latest code with Phase 2 work (migrations, models, projector)
2. Run `php artisan migrate` to ensure all billing tables exist
3. Run test: `php artisan billing:migrate-legacy-config --dry-run` should show wallet/subscription rules
4. Read the billing spec: `docs/payment-billing-decoupling-spec-2026-04-03.md`

### Schema-Driven Forms (BILL-303+)
The system uses `ProviderSchemaRegistry` to generate forms dynamically:
```php
// Example schema for M-Pesa Daraja
[
    'provider_key' => 'mpesa_daraja',
    'fields' => [
        'api_key' => [
            'type' => 'password',
            'label' => 'API Key',
            'required' => true,
            'encrypted' => true,
        ],
        'api_secret' => [
            'type' => 'password',
            'label' => 'API Secret',
            'required' => true,
            'encrypted' => true,
        ],
    ]
]
```

### During Phase 3: Keep it Read-Only
Don't implement write-back to database until BILL-401 (Phase 4).
This prevents data divergence during workspace development.

---

## What Comes After Phase 3 (Preview)

**Phase 4: Runtime Routing & Orchestration**
- BILL-401: Implement payment routing logic
- BILL-402: Transaction processing workflows
- BILL-403: Webhook event handling
- BILL-404: Proxy session management
- **Expected timing:** Weeks 4-5

**Phase 5: Provider Adapters**
- Implement provider-specific transaction handling
- Add webhook signature validation
- Build provider state normalization

**Phase 6+: Wallet Renewals, Market Policy, Full Rollout**

---

## Questions to Resolve

1. **Credential Encryption:** Use existing encryption or new vault service?
2. **Dual-write Timing:** Start dual-write in BILL-303 or defer to Phase 4?
3. **Feature Flag Strategy:** Global flag or per-market flags?
4. **Testing:** Unit tests for forms or integration tests for API endpoints first?
5. **Styling:** Use existing Settings page styling or new Billing-specific theme?

---

**Ready to proceed with BILL-301?**  
Start with the Billing workspace shell, then progressively add tabs.
