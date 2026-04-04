# Exotic CRM Billing Refactor - Complete Project Overview
**Current Status:** Phase 2 Complete ✅ | Phase 3 Ready 🚀  
**Last Updated:** 4 April 2026  
**Project Manager:** GitHub Copilot (AI Assistant)

---

## Project Vision

Decouple the Exotic CRM billing system from legacy wallet logic to support:
- Multiple payment providers per market
- Provider-agnostic routing and fallback chains
- First-class webhook and transaction tracking
- Admin-driven provider and routing configuration
- Zero-downtime migration from legacy system

---

## Current Phase Overview

### Phase 2: Data Model Foundation ✅ COMPLETE

**What was delivered:**
- Database schema: 5 new tables for providers, transactions, webhooks, sessions, settings
- Eloquent models: 5 models with proper relationships and foreign keys
- Migration command: Safe, transaction-wrapped migration with dry-run/apply/resume/drift-report
- All code tested and validated

**Commits:**
- `19cf272` feat: BILL-201 BILL-202 BILL-203 BILL-204 — phase 2 data model completion
- `3ba3261` feat: add legacy billing config projector

**Status:** ✅ Database migrations passing | ✅ Models created | ✅ Command tested

---

### Phase 3: Billing Workspace UI 🚀 IN PROGRESS

**What's needed:**
Build 5 interconnected UI tabs inside the Billing workspace for admin configuration.

**Tickets & Status:**

| # | Ticket | Component | Status | Effort | Lead |
|---|--------|-----------|--------|--------|------|
| 1 | BILL-301 | Workspace Shell | ✅ DONE | 1d | Copilot |
| 2 | BILL-302 | Providers Tab | 🚀 READY | 1-2d | Next dev |
| 3 | BILL-303 | Provider Profiles | 📋 PLANNED | 2d | After 302 |
| 4 | BILL-304 | Market Routing | 📋 PLANNED | 2-3d | After 303 |
| 5 | BILL-305 | Wallet Rules | 📋 PLANNED | 2d | Parallel |
| 6 | BILL-306 | System Settings | 📋 PLANNED | 1-2d | Parallel |

**Phase 3 Total Effort:** 9-10 days (~2 weeks with testing)

---

### Phase 4 & Beyond (Preview)

**Phase 4:** Runtime Routing & Orchestration (TBD)
- Payment routing logic
- Transaction processing
- Webhook handling
- Proxy session management

**Phase 5:** Provider Adapters (TBD)
- Provider-specific implementations
- Webhook signature validation
- State normalization

**Phase 6+:** Wallet, Market Policy, Full Rollout (TBD)

---

## Architecture Overview

### Database Schema

```
billing_provider_types
├── id, key, label
├── capability_json (surfaces, rails, modes)
└── status (active/beta/deprecated)

billing_provider_transactions
├── id, payment_id (fk)
├── provider_type_key
├── provider_transaction_id, provider_status
├── amounts: requested, charge, settled, fee
├── state_version, state_json
├── retry_of_provider_transaction_id (self-fk)
└── fallback_from_provider_transaction_id (self-fk)

billing_webhook_events
├── id, payment_id (fk)
├── provider_transaction_id (fk)
├── dedupe_key (unique)
├── raw_body, normalized_payload
├── signature_verified, processing_status
└── retry_count

billing_proxy_sessions
├── id, payment_id (fk)
├── token_hash (unique)
├── provider_profile_id (fk)
├── state (pending/active/consumed)
└── expires_at

billing_system_settings
├── id, platform_id
├── setting_key, setting_value_json
└── Covers: domains, branding, SMTP, webhooks, settlement
```

### React Component Structure

```
Settings Page
├── [Existing] Integrations
├── [Existing] Templates
├── [Existing] Webhook Logs
├── [Existing] Roles & Permissions
├── [Existing] Dashboard
├── [Existing] System Health
└── [NEW] Billing Workspace (BILL-301)
    │
    ├── Providers Sub-Tab (BILL-302)
    │   └── Read-only catalog of all providers
    ├── Provider Profiles Sub-Tab (BILL-303)
    │   └── CRUD forms for provider credentials
    ├── Market Routing Sub-Tab (BILL-304)
    │   └── Routing rules per market
    ├── Wallet Rules Sub-Tab (BILL-305)
    │   └── Wallet funding policies
    └── System Settings Sub-Tab (BILL-306)
        └── Global billing config
```

### API Endpoints (Phase 3)

```
GET  /crm/billing/providers-catalog
     → Returns: provider definitions with capabilities

POST /crm/billing/provider-profiles
     → Create provider credential profile

GET  /crm/billing/provider-profiles
     → List all provider profiles

PUT  /crm/billing/provider-profiles/{id}
     → Update profile

DELETE /crm/billing/provider-profiles/{id}
       → Delete profile

POST /crm/billing/provider-profiles/{id}/test
     → Test credentials

GET  /crm/billing/routing-rules/{market}
     → Get routing rules for market

PUT  /crm/billing/routing-rules/{market}
     → Update routing rules

GET  /crm/billing/wallet-rules
     → Get wallet policies

PUT  /crm/billing/wallet-rules
     → Update wallet policies

GET  /crm/billing/system-settings
     → Get system configuration

PUT  /crm/billing/system-settings
     → Update system configuration
```

---

## Design System

### Settings Page Patterns (What We Follow)

**Tab Navigation**
- Horizontal scrolling tabs
- Active tab: teal border + teal text
- Inactive tabs: gray text + gray hover
- Gap: 8px between tabs
- Padding: 12px vertical, 0 horizontal

**Cards**
- White background with slate-200 border
- Rounded corners (rounded-lg)
- Padding: 16px (p-4 to p-6)
- Hover: shadow-sm
- Status badges positioned bottom-right

**Status Badges**
- Green (Active, Pass, Healthy): bg-green-50, text-green-700
- Yellow (Beta, Configured Disabled): bg-yellow-50, text-yellow-700
- Gray (Stale, Inactive): bg-slate-50, text-slate-700
- Rounded-full, 12px font

**Typography**
- H1: text-3xl font-bold text-gray-900
- H2: text-2xl font-bold text-gray-900
- H3: text-sm font-semibold text-slate-900
- Description: text-sm text-gray-600
- Monospace: font-mono (for keys/codes)

**Color Palette**
- Primary Action: teal-500, teal-600
- Text Primary: slate-900
- Text Secondary: slate-600, slate-700
- Background: white
- Border: slate-200
- Success: green-50 / green-700
- Warning: yellow-50 / yellow-700
- Neutral: slate-50 / slate-700

---

## Feature Flags

```php
// config/features.php or .env

BILLING_WORKSPACE_ENABLED=true       // Show Billing tab (Phase 3)
BILLING_EDIT_ENABLED=false           // Allow writes (Phase 4+)
BILLING_DUAL_WRITE_ENABLED=false     // Write to new tables (Phase 4)
BILLING_RUNTIME_ENABLED=false        // Route through new system (Phase 4+)
```

---

## Key Implementation Guides

### For BILL-302 (Next)

**Read:** `docs/BILL-302-quick-start.md` (5 min)  
**Then:** `docs/BILL-302-implementation-guide.md` (detailed)

**What to build:**
1. ProvidersTab component (card grid display)
2. API endpoint: `/crm/billing/providers-catalog`
3. Controller method: `providersCatalog()`

**Expected output:**
Admins can view all providers with capabilities, markets, currencies, status.

---

### For BILL-303 (After 302)

**Read:** `docs/phase-3-next-steps-2026-04-04.md` section on BILL-303

**What to build:**
1. ProviderProfilesTab component (CRUD forms)
2. API endpoints: CRUD + test operations
3. Form schema renderer (driven by provider schemas)
4. Credential encryption/masking

**Expected output:**
Admins can create/edit/delete provider credential profiles with market scoping.

---

## Migration Strategy

### Legacy → New Config Migration

**Command:** `php artisan billing:migrate-legacy-config`

**Options:**
```bash
--dry-run              # Preview changes without applying
--apply                # Execute migration
--resume               # Continue from checkpoint
--drift-report         # Compare legacy vs new config
```

**What gets migrated:**
- Wallet funding providers → BillingProviderProfiles
- Subscription rules → BillingSubscriptionRules
- Routing preferences → BillingRoutingRules
- Global settings → BillingSystemSettings

**Safety features:**
- Transaction-wrapped
- Checkpoint support
- Drift detection
- Full rollback capability

---

## Testing Strategy

### Phase 3 Testing Pyramid

```
        Manual Testing (UX validation)
       /                              \
      /  Integration Testing (API)     \
     /                                  \
Unit Testing (Component Logic)

Requirements:
- Unit: Minimum 80% coverage for new components
- Integration: Full API contract testing
- Manual: Accessibility, responsive design, UX flow
```

### Test Checklist for Each Ticket

Before merging:
- [ ] Component renders without errors
- [ ] All props handled correctly
- [ ] Loading state displays
- [ ] Error state displays
- [ ] Empty state displays
- [ ] Responsive design (mobile/tablet/desktop)
- [ ] Accessibility (keyboard nav, screen readers)
- [ ] No console errors/warnings
- [ ] No performance issues
- [ ] Regression testing (existing features intact)

---

## Deployment Strategy

### Phase 3 Rollout (Low Risk)

1. **Staging Deploy** (3 days before production)
   - Deploy code with feature flags OFF
   - Run integration tests
   - Performance baseline
   - Manual QA in staging

2. **Production Deploy** (morning, support team online)
   - Deploy code with feature flags OFF
   - Verify no regressions
   - Monitor error rates

3. **Feature Flag Enable** (after 1 day, if stable)
   - Enable `BILLING_WORKSPACE_ENABLED=true`
   - Rolling 25%/50%/100% user rollout
   - Monitor adoption and errors

4. **Cutover (Phase 4+)** (planned, not in Phase 3)
   - Enable write-back and routing
   - Run final validation
   - Execute migration command
   - Monitor runtime routing

---

## Success Metrics

### Phase 2 Completion ✅
- ✅ 5 database tables created and migrated
- ✅ 5 Eloquent models with relationships
- ✅ Migration command tested with dry-run
- ✅ Zero regressions in existing functionality
- ✅ MySQL constraint naming issue resolved

### Phase 3 Goals (In Progress)
- Billing workspace renders without errors
- All 6 UI tickets completed and tested
- Provider catalog display functional
- CRUD forms prepared for Phase 4
- Design system patterns consistent
- Zero regressions

### Phase 4 Goals (Future)
- Provider routing operational
- Transaction processing working
- Webhook handling functional
- Legacy config fully migrated
- Runtime cutover successful

---

## Quick Reference

### Documents by Purpose

**For Project Overview:**
- `docs/session-summary-2026-04-04.md` (this session's work)
- `docs/payment-billing-decoupling-spec-2026-04-03.md` (original spec)

**For Phase Planning:**
- `docs/phase-3-next-steps-2026-04-04.md` (complete Phase 3 roadmap)
- `docs/payment-billing-implementation-backlog-2026-04-03.md` (full backlog)

**For Implementation:**
- `docs/BILL-302-quick-start.md` (5-min start guide)
- `docs/BILL-302-implementation-guide.md` (detailed specification)
- `docs/BILL-301-implementation-guide.md` (reference for workspace shell)

**For Handover:**
- `docs/billing-refactor-phase-2-handover-2026-04-04.md` (Phase 2 context)
- `docs/phase-3-next-steps-2026-04-04.md` (what's next)

### Code References

**Database:**
- Migrations: `database/migrations/2026_04_04_*.php` (5 tables)
- Models: `app/Models/Billing*.php` (5 models)

**Backend Services:**
- Migration Command: `app/Console/Commands/MigrateLegacyBillingConfig.php`
- Config Projector: `app/Billing/Support/LegacyBillingConfigProjector.php`
- Provider Registry: `app/Billing/Providers/ProviderRegistry.php`

**Frontend Components:**
- Workspace: `resources/js/components/billing/BillingWorkspace.jsx`
- Tab Navigation: `resources/js/components/billing/BillingTabNav.jsx`
- Settings Page: `resources/js/pages/Settings.jsx`

**API Routes:**
- `routes/api.php` (CRM billing endpoints)
- `app/Http/Controllers/CRM/SettingsController.php` (controllers)

---

## Contact & Questions

**If you're stuck:**
1. Check the implementation guide for the ticket
2. Review the data contract / API specification
3. Look at existing similar components
4. Check git history for related PRs
5. Ask in project Slack/Discord

**Common Questions Answered:**
- Q: Why read-only in Phase 3?  
  A: Minimize risk while validating UI, write-back in Phase 4

- Q: Can I start BILL-303 before BILL-302 is merged?  
  A: Yes, but they share some infrastructure (provider schemas)

- Q: When does the actual payment routing happen?  
  A: Phase 4 (Runtime Routing), not in Phase 3

- Q: How do we handle the migration cutover?  
  A: Detailed plan in BILL-006 (approved), executed in Phase 4

---

## Timeline Summary

| Phase | Description | Status | Duration | Start |
|-------|-------------|--------|----------|-------|
| Phase 0A | Model hardening & decisions | ✅ Complete | 1-2w | Done |
| Phase 0B | Safety scaffolding & shells | ✅ Complete | 1-2w | Done |
| Phase 1 | Provider registry & core | ✅ Complete | 1-2w | Done |
| Phase 2 | Data model & migration | ✅ Complete | 3-4d | Done |
| **Phase 3** | **Billing Workspace UI** | **🚀 READY** | **~2w** | **Now** |
| Phase 4 | Runtime routing & cutover | 📋 Planned | ~2w | Q2 2026 |
| Phase 5 | Provider adapters | 📋 Planned | 1-2w | Q2 2026 |
| Phase 6+ | Wallet, market policy, cleanup | 📋 Planned | TBD | Q2+ 2026 |

---

## Sign-Off

**Project Status:** ✅ On Track

- Phase 2 delivered and committed
- Phase 3 fully planned with implementation guides
- All blockers resolved
- Ready for next developer to start BILL-302
- No technical debt or regressions

**Next Step:** Begin BILL-302 implementation following `docs/BILL-302-quick-start.md`

---

**End of Overview**  
*Generated: 4 April 2026 by GitHub Copilot*
