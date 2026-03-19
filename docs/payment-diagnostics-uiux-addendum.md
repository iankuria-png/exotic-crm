# Payment Diagnostics UI/UX Addendum

**Date:** 2026-03-19  
**Parent plan:** `/Users/ian/.claude/plans/mellow-sniffing-rain.md`  
**Scope:** Add a UI/UX workstream to the M-Pesa diagnostics rollout without changing the diagnostics data contract.

---

## Objective

Make the Payment Diagnostics drawer feel like an operator console instead of a stack of equal-weight cards. The first screenful should answer:

1. What failed?
2. How serious is it?
3. What should I do next?

This addendum is intentionally presentation-focused. The backend telemetry plan remains the source of truth for diagnostics data capture.

## Current UX gaps

- Important sections have similar visual weight, so the primary diagnosis is easy to miss.
- `Recent Attempts`, which is usually the most useful telemetry block, appears too late in the drawer.
- Browser/request context is presented as raw fields even when the request came from a server-side workflow.
- Operational actions compete visually instead of guiding the operator toward the best next move.
- The drawer lacks a top-right close affordance and stronger incident-style loading/empty states.

## Proposed drawer changes

### 1. Diagnosis Summary

- Combine status, failure point, and recommended-action emphasis into a top summary block.
- Include severity/status badges, a plain-English diagnosis sentence, the failure stage, and the primary action.
- Keep amount and sandbox context visible, but demote them beneath the diagnosis hierarchy.

### 2. Information architecture

- Group the drawer into:
  - `Overview`
  - `Telemetry`
  - `History`
- Add anchor chips or a compact sticky section-nav row for quick jumps.
- Move `Recent Attempts` above `Audit Trail` and `Timeline`.

### 3. Browser-confidence states

- Replace raw dash-heavy browser fields with explicit states:
  - `Browser captured`
  - `Server-side request`
  - `No browser context captured`
- Keep the detailed fields only when the data is trustworthy and useful.

### 4. Action hierarchy

- Preserve existing actions and permissions.
- Visually emphasize one primary next action.
- Keep secondary and tertiary actions available, but less visually dominant.
- Add a visible header close button while preserving overlay-click and footer close.

### 5. Telemetry presentation

- Compress attempts into a compact timeline/table-like presentation.
- Surface freshness metadata near the summary area.
- Improve the affordance of collapsible history sections so they read as intentional expandable panels.

### 6. State design

- Replace the single loading sentence with section skeletons.
- Improve empty states for:
  - no attempts
  - no browser context
  - no audit/history
- Preserve the current error handling, but style it as a deliberate diagnostics state.

## Regression guardrails

- No backend payload changes.
- No mutation-key or recommendation-key changes.
- No loss of sandbox safeguards, provider verification, or history visibility.
- No behavioral changes to provider status checks, reconcile actions, or manual-close flows.
- Drawer must remain usable at desktop and mobile widths.

## Acceptance criteria

- Status, failure stage, and primary next action are visible without scrolling.
- `Recent Attempts` appears before history.
- Browser context clearly communicates trust level.
- All existing actions remain functional and discoverable.
- Close behavior works from header, footer, and overlay.
- `npm run build` and the diagnostics/backend regression tests still pass.

## Implementation note

The planned implementation surface is currently limited to [Payments.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Payments.jsx). That keeps the UI refresh isolated from the backend telemetry rollout and makes rollback safer if needed.
