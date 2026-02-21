# Sprint 5 Tranche Log

Date baseline: 2026-02-20
Owner: Engineering
Purpose: Keep a running plan + progress log after each tranche/sprint, with verification and decisions.

## Tranche 1 (Completed)

### Plan
- Close core reconciliation gaps from Sprint 4 audit:
  - manual lead/client creation
  - lead assignment flow
  - role and market assignment editing
  - payment review queue semantics
  - global stats for list pages
  - base UX safety patterns (confirmations + toasts)

### Progress
- Backend:
  - Added manual create endpoints for leads/clients.
  - Added lead assign endpoint and owner lookup endpoint.
  - Added admin role update endpoint with audit trail.
  - Corrected dashboard payment queue semantics to unmatched completed payments.
  - Added server-side scoped stats for clients/leads/deals/payments list endpoints.
- Frontend:
  - Added shared toast provider and confirm dialog components.
  - Implemented manual intake + assignment flows in Leads/Clients.
  - Added role/market edit modal in Settings.
  - Improved microcopy on queue meaning and lifecycle labels.
- Verification:
  - Feature tests passing.
  - Production build passing.

### Result
- Core daily operations now run from CRM UI with clearer semantics and safer high-impact actions.

---

## Tranche 2 (Completed)

### Plan
- Finish remaining high-priority Sprint 5 items:
  - CSV bulk upload for leads and clients
  - dashboard filters
  - broader confirmation/toast coverage hardening
  - tranche-level documentation standardization

### Progress
- Backend:
  - Added `POST /api/crm/leads/upload-csv`.
  - Added `POST /api/crm/clients/upload-csv`.
  - Added CSV parsing and row-level error reporting (max 500 rows/upload).
  - Added dashboard market filter support via `platform_id`.
- Frontend:
  - Leads page:
    - Upload CSV modal (market + file + header toggle + reason).
  - Clients page:
    - Upload CSV modal (market + file + header toggle + reason).
  - Dashboard page:
    - Market filter dropdown wired to backend.
  - Existing confirmation/toast usage retained across high-impact flows.
- Test coverage:
  - Added feature tests for:
    - leads CSV upload endpoint
    - clients CSV upload endpoint
    - dashboard single-market filtering
  - Existing authorization and workflow tests remain green.

### Verification
- `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.

### Decision Notes
- CSV uploads are currently scoped to one market per upload for operational safety and clear ownership.
- CSV ingestion is intentionally create-focused (row-by-row validation + error list) to minimize hidden side effects.

### Next Tranche Candidates
- Server-side CSV dry-run mode with downloadable error report.
- Dashboard additional date-range filters.
- Coverage expansion for remaining medium-impact actions in Settings workspaces.

---

## Tranche 3 (Completed)

### Plan
- Improve UX safety and clarity for bulk intake actions.
- Add explicit confirmation before CSV upload starts.
- Surface row-level CSV failures directly in-page for self-service recovery.
- Harden shared dialog and search controls for accessibility.

### Progress
- Frontend:
  - Leads and Clients now require an explicit confirmation step before CSV upload.
  - Confirmation copy now states operational impact clearly: create-only behavior, no update/delete side effects, market scope, and row limit.
  - Added persistent CSV upload summary cards on Leads/Clients with:
    - total rows
    - created/failed counts
    - row-level error preview (first 8 rows)
  - Added `aria-label` on icon-only search buttons across Leads/Clients/Deals.
  - Updated Dashboard CTA microcopy from `Review queue` to `Review payment queue`.
- Shared components:
  - `ConfirmDialog` now includes:
    - `aria-labelledby` / `aria-describedby`
    - Escape-key close behavior (disabled while pending)
    - cancel/backdrop close lock during pending actions

### Verification
- `npm run build` -> pass.
- `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.

### Decision Notes
- Row-level CSV errors are shown inline for immediate operator recovery, while retaining toast notifications for quick action feedback.
- Error previews are capped for scanability; detailed CSV export can be added in a later tranche.

---

## Tranche 4 (Completed)

### Plan
- Execute Sprint 5A UX foundation priorities from the approved reconciliation backlog:
  - shell/navigation breathing room and hierarchy clean-up
  - remove duplicated top-title context
  - unify status and metric visual language
  - improve dashboard filter/action ergonomics and queue clarity
  - apply user-facing terminology migration from `Deals` to `Subscriptions`
  - reduce payment action ambiguity and enable searchable manual match candidates

### Progress
- Frontend shell and shared components:
  - Refined `MainLayout` top bar into utility controls (no duplicated `Exotic Sales CRM` title).
  - Improved sidebar spacing and icon weight; renamed nav label `Deals` -> `Subscriptions`.
  - Updated `StatusBadge` from pill/full-round styling to refined rounded chips.
  - Reworked shared `MetricCard` to remove top border accents and use dot-indicator semantics.
- Dashboard UX and copy:
  - Added search input, date range controls, and stronger quick actions.
  - Improved market filter affordance with active-state indicator.
  - Renamed `Expiring Deals` section to `Expiring Subscriptions`.
  - Added follow-up context tooltip and clearer queue/renewal microcopy.
- Payments operational clarity:
  - Renamed per-row actions to `Auto-match` and `Match manually`.
  - Added explicit helper copy explaining auto vs manual behavior.
  - Added manual-candidate search UX in drawer.
- Backend contracts:
  - Enhanced dashboard API to accept/validate `from`, `to`, and `search` inputs.
  - Enhanced payment candidate endpoint to support `search` across name/phone/email and numeric IDs (`id`, `wp_post_id`, `wp_user_id`).
- Terminology pass:
  - Updated key user-facing strings from `Deal(s)` to `Subscription(s)` on navigation and core pages while preserving backend route/model names for compatibility.

### Verification
- Build:
  - `npm run build` -> pass.
- Tests:
  - `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
  - `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5a-2026-02-20/dashboard.png`
  - `output/playwright/sprint5a-2026-02-20/clients.png`
  - `output/playwright/sprint5a-2026-02-20/subscriptions.png`
  - `output/playwright/sprint5a-2026-02-20/payments.png`
  - `output/playwright/sprint5a-2026-02-20/payments-manual-match.png`
  - `output/playwright/sprint5a-2026-02-20/renewals.png`
  - `output/playwright/sprint5a-2026-02-20/reports.png`
  - `output/playwright/sprint5a-2026-02-20/settings.png`

### Decision Notes
- Kept backend `deals` domain and routes intact to avoid high-risk schema/API churn; terminology migration is UI-layer-first.
- Dashboard date filtering is optional; when unset, operational queues stay broad while KPI revenue window remains backend-defaulted.
- Candidate search was prioritized in payments manual match to remove phone-only matching bottlenecks before deeper queue policy changes.

---

## Tranche 5 (Completed)

### Plan
- Close high-friction traceability and renewals workflow gaps from Sprint 5 backlog:
  - searchable client traceability by CRM ID and WordPress IDs
  - renewal reminder visibility per subscription row
  - manual renew action directly from renewals workspace
  - direct navigation to client profile and payment history from renewals
  - client detail deep-link support for tabbed views

### Progress
- Backend:
  - Extended client search to include numeric matching on `id`, `wp_post_id`, and `wp_user_id`.
  - Enriched renewal overview payload with:
    - `reminders_sent_count`
    - `reminders_failed_count`
    - `last_renewal_reminder_at`
  - Added `created_at` to `TimelineEvent::$fillable` so reminder timeline events keep authored timestamps.
- Frontend:
  - Clients page search microcopy now explicitly supports CRM/WP ID lookup.
  - Renewals table now shows reminder telemetry (`sent`, `failed`, last event timestamp).
  - Added renewals row actions: `Remind`, `Renew`, `Profile`, `Payments`.
  - Implemented manual renew modal with required reason and day extension input.
  - Added client detail query-param tab state (e.g., `?tab=payments`) with URL sync on tab clicks.
- Tests:
  - Added feature test coverage for:
    - client search by CRM/WP IDs with market scoping
    - renewal overview reminder counts and last reminder timestamp

### Verification
- `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5b-2026-02-20/dashboard.png`
  - `output/playwright/sprint5b-2026-02-20/clients.png`
  - `output/playwright/sprint5b-2026-02-20/leads.png`
  - `output/playwright/sprint5b-2026-02-20/payments.png`
  - `output/playwright/sprint5b-2026-02-20/renewals.png`
  - `output/playwright/sprint5b-2026-02-20/renewals-manual-renew-modal.png`
  - `output/playwright/sprint5b-2026-02-20/client-detail-payments-tab.png`
  - `output/playwright/sprint5b-2026-02-20/reports.png`
  - `output/playwright/sprint5b-2026-02-20/settings.png`

### Decision Notes
- Renewal reminder telemetry is computed from `timeline_events` to preserve audit-source truth and avoid duplicate counters.
- Manual renew from renewals requires an explicit reason for operational traceability.
- Tab deep-links improve self-service handoff between teams (renewals -> client payments context) without extra navigation steps.

---

## Tranche 6 (Completed)

### Plan
- Deliver lead lifecycle controls requested in feedback:
  - archive lead with explicit reason + confirmation
  - delete lead with explicit reason + confirmation
  - add safe bulk archive/delete actions with consequences copy
  - ensure archived leads leave active pipeline views without losing audit traceability

### Progress
- Backend:
  - Added `archived_at` support for leads via migration:
    - `database/migrations/2026_02_20_000013_add_archived_at_to_leads_table.php`
  - Added lead archive endpoint:
    - `PATCH /api/crm/leads/{lead}/archive`
  - Added lead delete endpoint:
    - `DELETE /api/crm/leads/{lead}`
  - Required `reason` validation for both archive and delete actions.
  - Added timeline + audit log coverage for:
    - `lead_archived`
    - `lead_deleted`
  - Default lead listing and pipeline metrics now exclude archived records unless `include_archived=1`.
- Frontend:
  - Leads table actions now include `Archive` and `Delete` per row.
  - Added archive/delete confirmation dialogs with:
    - explicit effect copy
    - required reason field
    - destructive tone for delete actions
  - Added bulk actions:
    - `Archive selected`
    - `Delete selected`
  - Added bulk confirmation dialog with required reason and risk-aware copy.
  - Leads helper microcopy now clarifies reason requirements for archive/delete operations.
- Domain constants:
  - Added audit action constants:
    - `LEAD_ARCHIVE`
    - `LEAD_DELETE`

### Verification
- `php artisan migrate --force` -> pass.
- `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5c-2026-02-20/leads.png`
  - `output/playwright/sprint5c-2026-02-20/leads-archive-modal.png`
  - `output/playwright/sprint5c-2026-02-20/leads-delete-modal.png`

### Decision Notes
- Archive keeps lead history available for audit/reconciliation while reducing pipeline noise for agents.
- Delete remains available but guarded by required reason and destructive confirmation to reduce accidental data loss.

---

## Tranche 7 (Completed)

### Plan
- Execute next backlog slice from reconciliation plan:
  - complete `CRM-512` (renewals pause flow + progressive disclosure control panel)
  - complete remaining `CRM-511` scope (owner identity context in assignment + scrape lead controlled intake)

### Progress
- Backend:
  - Added renewal pause/resume endpoints:
    - `POST /api/crm/renewals/pause`
    - `POST /api/crm/renewals/resume`
  - Added renewal pause fields to deals:
    - `renewal_reminders_paused`
    - `renewal_paused_until`
    - `renewal_pause_reason`
  - Renewal overview now returns pause metadata and supports `bucket=paused`.
  - Automated campaign target selection now excludes paused reminders.
  - Manual reminder API now returns paused-state validation when reminders are paused.
  - Added scrape lead intake endpoint:
    - `POST /api/crm/leads/scrape-entry`
  - Settings owners payload now includes:
    - `role_label`
    - `assigned_markets`
    - `market_scope`
- Frontend:
  - Renewals page upgraded with progressive disclosure:
    - row `Manage` action + side control panel drawer
    - in-panel actions: remind, manual renew, pause/resume reminders, view profile, view payments
  - Added pause reminders modal with optional pause-until date and required reason.
  - Added resume reminders modal with required reason.
  - Added paused reminder KPI card + paused state filter.
  - Leads page:
    - added `Scrape lead` CTA and controlled intake modal
    - assign modal now shows owner role label and assigned market context
    - owner directory card list added for faster informed assignment selection
- Data model and audit:
  - Added audit action constants:
    - `LEAD_SCRAPE_INTAKE`
    - `RENEWAL_PAUSE`
    - `RENEWAL_RESUME`

### Verification
- `php artisan migrate --force` -> pass.
- `php artisan test --filter CrmStreamFourAuthorizationTest` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5d-2026-02-20/renewals.png`
  - `output/playwright/sprint5d-2026-02-20/renewals-control-drawer.png`
  - `output/playwright/sprint5d-2026-02-20/renewals-pause-modal.png`
  - `output/playwright/sprint5d-2026-02-20/leads.png`
  - `output/playwright/sprint5d-2026-02-20/leads-assign-modal-context.png`
  - `output/playwright/sprint5d-2026-02-20/leads-scrape-modal.png`

### Decision Notes
- Pause/resume is implemented as an explicit state on subscriptions to keep campaign targeting deterministic and auditable.
- Scrape lead in this tranche is a controlled intake path (URL-led lead creation with audit trace), while full crawler orchestration remains in advanced scope (`CRM-518`).

---

## Tranche 8 (Completed)

### Plan
- Execute `CRM-513` from the reconciliation backlog:
  - add funnel visualization-ready report payload
  - improve owner performance analytics for management use
  - refine reports UX (export ergonomics + robust empty states)
  - add feature coverage for the expanded reports contract

### Progress
- Backend:
  - `ReportController` now returns structured funnel analytics:
    - `lead_funnel_stages`
    - `lead_funnel_totals`
  - Funnel source data now excludes archived leads and respects the selected date range.
  - Owner analytics expanded with:
    - `active_subscriptions`
    - `pre_activation_subscriptions`
    - `expired_subscriptions`
    - `avg_revenue_per_subscription`
    - `owner_performance_totals`
    - `owner_performance_top_owner`
  - Revenue trend grouping made SQLite-safe for tests by using driver-aware month key expressions.
- Frontend:
  - Reports page redesigned into management-grade sections:
    - `Sales Funnel` panel with per-stage progression/drop-off context
    - upgraded `Owner Performance` table with revenue share and subscription mix
    - refined export control container with range validation and exporting state
    - richer empty-state components for trend/source/package/owner panels
    - lead source normalization to keep canonical sources visible even when zero in the selected range
- Tests:
  - Added `test_reports_summary_returns_funnel_stages_and_owner_totals_for_selected_range` in `CrmStreamFourAuthorizationTest`.
  - Test asserts funnel stages/totals, archived-lead exclusion effect, and owner totals/top-owner response contract.

### Verification
- `php artisan test --filter test_reports_summary_returns_funnel_stages_and_owner_totals_for_selected_range` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5e-2026-02-21/reports-default.png`
  - `output/playwright/sprint5e-2026-02-21/reports-full.png`

### Decision Notes
- Funnel and owner presentation was completed as a contract-first change (backend payload + UI) so reporting insights remain stable even as charts evolve.
- Source normalization intentionally surfaces zero-count channels to remove ambiguity in manager reviews of lead-source coverage.

---

## Tranche 9 (Completed)

### Plan
- Execute `CRM-514` from the reconciliation backlog:
  - transform integrations settings from read-only status cards to actionable market management workspace
  - add backend endpoints for market profile CRUD, connection testing, and manual sync actions
  - make sync health/snapshot state visible in settings
  - preserve RBAC and auditability for all high-impact integration actions

### Progress
- Backend:
  - Added platform integration health/sync metadata columns via migration:
    - `sync_last_checked_at`
    - `sync_last_synced_at`
    - `sync_last_scope`
    - `sync_last_status`
    - `sync_last_error`
    - `sync_last_result`
  - Added audit constants for integration operations:
    - `INTEGRATION_PLATFORM_CREATE`
    - `INTEGRATION_PLATFORM_UPDATE`
    - `INTEGRATION_CONNECTION_TEST`
    - `INTEGRATION_SYNC_RUN`
  - Extended `Platform` model fillable/casts for sync metadata fields.
  - Upgraded `SettingsController` integrations contract to return richer market profile payload:
    - domain/active/runtime defaults
    - wp credential readiness + last check
    - last sync state + result payload
  - Added new settings integration endpoints:
    - `POST /api/crm/settings/integrations/platforms` (admin)
    - `PATCH /api/crm/settings/integrations/platforms/{platform}` (admin/sub_admin)
    - `POST /api/crm/settings/integrations/platforms/{platform}/test-connection` (admin/sub_admin)
    - `POST /api/crm/settings/integrations/platforms/{platform}/sync` (admin/sub_admin)
  - Implemented manual sync orchestration:
    - client sync via `ClientSyncService` (full/delta)
    - lead sync via `LeadImportService` (supports dry-run)
  - Added sync safety guard:
    - dry-run is restricted to leads scope only.
- Frontend:
  - Rebuilt Integrations tab into a workspace with:
    - market selector rail
    - editable profile form (domain/country/currency/timezone/phone prefix/WP credentials/active state)
    - connection health panel with reasoned test action
    - manual sync controls (scope, mode, dry-run, per-page, reason)
    - confirmation modal for sync execution
    - add-market modal (admin-only control surface)
  - Improved status badge semantics for integration/sync states (`healthy`, `partial`, `degraded`, etc.).

### Verification
- `php artisan migrate --force` -> pass.
- `php artisan test --filter "test_admin_can_create_update_and_test_market_integration_profile|test_sub_admin_can_run_leads_sync_for_owned_market_and_blocked_for_out_of_scope_market"` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5f-2026-02-21/settings-integrations-overview.png`
  - `output/playwright/sprint5f-2026-02-21/settings-add-market-modal.png`
  - `output/playwright/sprint5f-2026-02-21/settings-sync-confirmation-modal.png`

### Decision Notes
- Integration management was implemented with platform-scoped RBAC and explicit audit trail to keep operational self-service safe.
- Manual sync was split by scope/mode to avoid hidden side effects and to support dry-run lead intake checks before committing data.

---

## Tranche 10 (Completed)

### Plan
- Execute `CRM-515` from the reconciliation backlog:
  - add in-app user creation flow for admins
  - capture role/status/market assignment at creation time
  - keep new-user onboarding aligned with role governance/audit rules

### Progress
- Backend:
  - Added `POST /api/crm/settings/roles/users` (admin-only) in settings routes.
  - Added `SettingsController::storeUser()` to create users with:
    - name/email/password
    - role and status
    - assigned markets (`assigned_market_ids`)
  - New users now sync market assignments into `user_platforms`.
  - Added `USER_CREATE` audit action constant and audit logging for create-user events.
- Frontend:
  - Roles workspace now includes `Add user` CTA.
  - Added create-user modal with:
    - core identity fields
    - role/status selectors
    - assigned market multi-select
    - mandatory reason text
  - Integrated optimistic feedback via toasts and data refresh on success.
- Tests:
  - Added `test_admin_can_create_user_with_role_and_market_assignments` in `CrmStreamFourAuthorizationTest`.
  - Test validates user record, market mapping rows, and audit log action (`user_create`).

### Verification
- `php artisan test --filter test_admin_can_create_user_with_role_and_market_assignments` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5g-2026-02-21/settings-roles-overview.png`
  - `output/playwright/sprint5g-2026-02-21/settings-create-user-modal.png`

### Decision Notes
- User creation was constrained to admin-only to avoid role escalation risk while still removing engineering dependency for routine onboarding.
- Market assignment is captured at creation time to prevent “user exists but cannot operate” onboarding gaps.

---

## Tranche 11 (Completed)

### Plan
- Execute `CRM-516` from the reconciliation backlog:
  - implement multi-provider SMS routing with active/fallback strategy
  - expose provider configuration and test dispatch controls in Settings
  - preserve reasoned audit logging and admin-only controls

### Progress
- Backend:
  - Added `integration_settings` store for dynamic integration configuration:
    - `database/migrations/2026_02_21_000016_create_integration_settings_table.php`
    - `app/Models/IntegrationSetting.php`
  - Added SMS provider abstraction layer:
    - `app/Services/Sms/SmsProviderInterface.php`
    - `app/Services/Sms/LegacyGatewaySmsProvider.php`
    - `app/Services/Sms/AfricasTalkingSmsProvider.php`
  - Refactored `NotificationService` to support:
    - active provider selection
    - optional fallback provider attempts
    - DB-backed provider config with environment fallback
    - masked config reads and persisted config updates
  - Extended settings integrations API:
    - `PATCH /api/crm/settings/integrations/sms-provider` (admin)
    - `POST /api/crm/settings/integrations/sms-provider/test` (admin)
  - Added audit coverage for provider update + test dispatch actions.
- Frontend:
  - Upgraded Settings Integrations tab with a full `SMS Provider Routing` workspace:
    - routing controls (enabled toggle, active provider, fallback provider, default prefix)
    - provider credential forms for Legacy Gateway and Africa's Talking
    - secure API-key handling messaging (stored key masked, rotate-on-input behavior)
    - validation states for invalid fallback and incomplete active-provider credentials
  - Added `Test Dispatch` panel with:
    - phone/message/reason inputs
    - confirmation modal before sending live test SMS
    - latest test result summary (provider/status/response/fallback attempted)
  - Preserved progressive disclosure and toast feedback patterns.
- Tests:
  - Added `test_admin_can_update_sms_provider_configuration`.
  - Added `test_admin_can_test_sms_provider_dispatch`.
  - Assertions cover persisted config values, provider dispatch behavior, and audit records.

### Verification
- `php artisan migrate --force` -> pass.
- `php artisan test --filter "test_admin_can_update_sms_provider_configuration|test_admin_can_test_sms_provider_dispatch"` -> pass.
- `php artisan test --testsuite=Feature --stop-on-failure` -> pass.
- `npm run build` -> pass.
- Playwright evidence:
  - `output/playwright/sprint5h-2026-02-21/settings-sms-provider-section.png`
  - `output/playwright/sprint5h-2026-02-21/settings-sms-test-confirmation-modal.png`

### Decision Notes
- SMS provider configuration is runtime-managed in DB to remove deploy-time coupling for day-to-day provider switching.
- Fallback routing is constrained to a different provider than active to avoid ambiguous failover behavior.
- Test dispatch remains admin-only and confirmation-gated because it triggers real outbound communication.
