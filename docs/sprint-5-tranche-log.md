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
