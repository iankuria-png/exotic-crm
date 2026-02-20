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
