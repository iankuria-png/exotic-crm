# Payment Diagnostics UI/UX TODO

**Date:** 2026-03-19  
**Parent plan:** `/Users/ian/.claude/plans/mellow-sniffing-rain.md`  
**Addendum:** [payment-diagnostics-uiux-addendum.md](/Users/ian/Projects/exotic-crm/docs/payment-diagnostics-uiux-addendum.md)

---

## Delivery rules

- [x] Keep the diagnostics API payload and recommendation keys unchanged.
- [x] Preserve every current operational action and permission boundary.
- [ ] Keep the drawer usable on desktop and narrow/mobile widths.
- [x] Commit after each completed implementation phase.
- [x] Run the relevant regression checks before moving to the next phase.

## Phase 1 - Information architecture

- [x] Add a top `Diagnosis Summary` block in [Payments.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Payments.jsx).
- [x] Make status, failure stage, and primary action visible without scrolling.
- [x] Reorder the drawer into `Overview`, `Telemetry`, and `History`.
- [x] Move `Recent Attempts` above `Audit Trail` and `Timeline`.
- [x] Add a visible header close button without removing overlay or footer close behavior.
- [x] Commit phase 1.

### Phase 1 regression gate

- [ ] Open the drawer on a failed payment and confirm the summary appears above the fold.
- [ ] Confirm all existing actions still render and remain clickable.
- [ ] Confirm header close, footer close, and overlay click all still work.

## Phase 2 - Telemetry presentation

- [x] Convert `Recent Attempts` into a denser timeline/table-like presentation.
- [x] Add a compact freshness indicator near the top summary area.
- [x] Improve section spacing so important telemetry no longer competes with low-priority history.
- [x] Add a sticky section-nav or anchor-chip row if it improves navigation without crowding the header.
- [x] Commit phase 2.

### Phase 2 regression gate

- [x] Confirm attempts still show provider, HTTP status, latency, reason, and timestamp.
- [x] Confirm provider-status and sandbox-reconcile blocks still display correctly.
- [ ] Confirm no diagnostics section becomes inaccessible after the layout change.

## Phase 3 - Browser confidence + action hierarchy

- [x] Replace raw browser/request output with explicit confidence states.
- [x] `Browser captured`
- [x] `Server-side request`
- [x] `No browser context captured`
- [x] Keep detailed origin/referrer/browser/device/IP output only when meaningful.
- [x] Visually emphasize only the primary next action.
- [x] Keep secondary and tertiary actions discoverable but less dominant.
- [x] Commit phase 3.

### Phase 3 regression gate

- [ ] Verify browser-originated website STK diagnostics show captured context.
- [ ] Verify wallet/server-originated flows do not present misleading browser metadata.
- [ ] Verify recommendation buttons still trigger the same actions as before.

## Phase 4 - Loading, empty, and history states

- [x] Replace the single loading sentence with section skeletons/placeholders.
- [x] Improve empty states for no attempts, no browser context, and no audit/timeline history.
- [x] Improve `details` affordances for history sections so expand/collapse state is clearer.
- [x] Keep diagnostics error handling intact while making the error state more deliberate.
- [x] Commit phase 4.

### Phase 4 regression gate

- [ ] Verify loading state does not cause layout jump or broken spacing.
- [ ] Verify empty states appear intentionally and do not read like data loss.
- [ ] Verify error state still appears when diagnostics fetch fails.

## Phase 5 - Final verification

- [x] Run `npm run build`.
- [x] Run `php artisan test tests/Feature/LegacyStkRoutingTest.php tests/Feature/WalletApiPhaseFiveTest.php tests/Feature/PaymentDiagnosticsTest.php`.
- [ ] Manually verify the refreshed drawer on desktop width.
- [ ] Manually verify the refreshed drawer on narrow/mobile width.
- [ ] Confirm there are no console errors during diagnostics interactions.
- [ ] Commit the final UI/UX verification pass.

## Done when

- [x] Operators can identify the issue and next action from the first screenful.
- [x] `Recent Attempts` is visible before history.
- [x] Browser-context trust is explicit.
- [x] Existing actions, safeguards, and telemetry remain intact.
