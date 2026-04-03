# Payment Billing Plan Thorough Preaudit

Date: 2026-04-03
Project: Exotic CRM
Status: Planning-only preaudit before implementation

This preaudit reviews the current billing planning set against the live codebase before execution starts:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-billing-implementation-backlog-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Input sources:

- fresh parallel review passes for architecture/runtime and migration/regression risk
- local codebase verification focused on product, UI/UX, permissions, diagnostics, and test readiness

## Outcome

The planning set is materially stronger than the earlier drafts.

It is now good enough to support `Phase 0A` and `Phase 0B`.

It is still not ready for runtime implementation without a few remaining pre-execution clarifications, mostly on frontend/test governance and operator workflow preservation.

## Findings

### Critical 1. Frontend test execution is still under-specified

The plan now requires browser automation and suite splits, but the repo still has no frontend test runner, no browser test framework committed, and no CI contract for when these tests must block rollout.

Evidence:

- `package.json` only exposes `dev` and `build`
- the backlog introduces `tests/browser/` and browser coverage in `BILL-010` and `BILL-801`
- the architecture plan requires browser automation before rollout

Remaining gap:

- choose and document the browser test framework, execution command, fixture strategy, CI gate, and minimum blocking policy before implementation starts

### High 2. Settings decomposition does not yet protect all current shared consumers

The docs correctly reject the monolithic `settings-integrations` contract, but the current repo still uses that query shape widely and not only inside `Settings.jsx`.

Evidence:

- `resources/js/pages/Settings.jsx` still loads and invalidates `['settings-integrations']` throughout the page
- `resources/js/pages/Deals.jsx` and `resources/js/pages/Payments.jsx` still use related settings query keys
- the backlog adds `BILL-009` and `BILL-309`, but does not explicitly call out compatibility for non-billing consumers of existing settings payloads

Remaining gap:

- add a compatibility contract for any page still depending on settings-integrations-era data, or explicitly declare which consumers must be migrated before Billing Settings goes live

### High 3. Operator-flow migration is still broader than the current execution plan admits

The plan now states that operators should choose method while routing chooses provider. That direction is correct. The remaining issue is coverage breadth.

Evidence:

- `resources/js/pages/Deals.jsx` still hard-codes `manual`, `stk`, `link`, and `free_trial`, and exposes provider selection for links
- `resources/js/pages/ClientDetail.jsx` duplicates similar activation and deal payment flows
- `app/Http/Controllers/CRM/DealController.php` validates `manual,stk,link,free_trial` in multiple endpoints
- `app/Services/DealPaymentService.php` still branches heavily by method and provider assumptions

Remaining gap:

- create one operator-flow migration matrix that explicitly covers:
  - Deals create/extend flows
  - Client Detail activation flows
  - Client Detail deal payment flows
  - payment-link send flows
  - wallet-funded renewal visibility
- that matrix should define `legacy behavior`, `interim behavior`, `final behavior`, and `feature flag owner`

### High 4. Permissions and redaction are strong in narrative, but still not fully test-shaped

The spec now has a good permission matrix and diagnostics governance rules. The remaining weakness is that these are not yet translated into endpoint-level assertions.

Evidence:

- `app/Services/MarketAuthorizationService.php` already includes `marketing`
- the spec defines `admin`, `sub_admin`, `sales`, and `marketing` behavior across Billing and Diagnostics
- the backlog adds `BILL-307`, `BILL-310`, `BILL-707`, and `BILL-708`

Remaining gap:

- define the exact permission and redaction test matrix by surface:
  - Billing workspace tabs
  - Billing Diagnostics
  - Payment Diagnostics
  - route simulator
  - drill-through to payment detail
  - secret fields and webhook payload views

### Medium-High 5. Wallet wording migration is still incomplete at product-surface level

The docs now call for clearer terminology, but the current product still uses overlapping “wallet top-up” language in Settings and Client Detail.

Evidence:

- `resources/js/pages/ClientDetail.jsx` still uses “Manual wallet top-up” for CRM-admin funding flows
- `resources/js/pages/Settings.jsx` still uses “wallet top-up preset” copy for customer funding rules
- the backlog adds `BILL-311`, but the scope is still described mainly in Billing workspace terms

Remaining gap:

- extend copy migration scope beyond Billing Settings to:
  - Client Detail wallet actions
  - payment and renewal status messaging
  - API success/error copy where operators see it
  - diagnostics labels where wallet funding and wallet adjustment could be confused

### Medium-High 6. Diagnostics split is defined, but shared UX behavior still needs operational rules

The plan now cleanly separates `Payment Diagnostics` from `Billing Diagnostics`. The remaining issue is operational UX consistency under degraded conditions.

Evidence:

- `resources/js/pages/Payments.jsx` already has a live diagnostics drawer
- the spec and backlog define a shared diagnostics backend and two surfaces
- the backlog adds redaction and drill-through tickets

Remaining gap:

- define consistent UX rules for:
  - degraded or partial diagnostics
  - `legacy_composed` diagnostics
  - unavailable route simulator
  - slow-loading health panels
  - drill-through when access scope changes between surfaces

### Medium 7. The plan still lacks a formal design-review gate for “world-class” Billing UX

The wireframes and state coverage are better, but there is still no explicit gate for design review before implementation.

Evidence:

- the spec includes ASCII wireframes and state expectations
- the backlog covers components and browser coverage
- there is no explicit ticket or acceptance gate for interactive prototypes, usability review, or accessibility review

Remaining gap:

- add a pre-build design review gate for the Billing workspace, Payment Diagnostics, and Billing Diagnostics that checks:
  - information hierarchy
  - empty/loading/degraded/error states
  - keyboard and focus behavior
  - readability of market/provider/fallback concepts

### Medium 8. Payments action preservation is now documented, but rollout observability is still thin

The docs now preserve current Payments actions, which is correct. The remaining gap is how regressions in those actions will be detected during rollout.

Evidence:

- the spec now lists `auto-match`, `manual match`, `review-state`, `manual close`, `retry STK`, `send payment link`, `provider check`, and `create subscription`
- the backlog adds `BILL-709`
- the current `PaymentQueueController` remains a rich operational controller, not a thin read endpoint

Remaining gap:

- attach observability and rollout checks to each preserved action, not just to billing routing generally
- at minimum, define which actions must have smoke coverage and which need success/failure telemetry during cutover

## Scores

- Identification quality: `8.6/10`
- Architecture readiness: `8.0/10`
- Migration/regression readiness: `7.2/10`
- Product/UI/UX readiness: `6.8/10`
- Test/QA readiness: `5.8/10`
- Overall execution readiness: `6.6/10`

## Recommendation

Recommendation: `conditional go` for planning hardening only.

Approved scope:

- `Phase 0A`
- `Phase 0B`
- explicit conversion of the remaining gaps above into backlog items or acceptance criteria

Not yet approved:

- runtime billing refactor
- provider adapter implementation
- Billing workspace write-enabled rollout
- diagnostics cutover

## Required before runtime work starts

1. Lock the frontend/browser test framework and CI gate.
2. Add a compatibility map for non-Billing consumers of current settings payloads.
3. Publish the operator-flow migration matrix across Deals, Client Detail, and Payments actions.
4. Publish the role/redaction test matrix by surface and endpoint.
5. Expand wallet wording migration scope beyond Billing Settings.
6. Define degraded-state behavior for both diagnostics surfaces.
7. Add a design-review gate for the new Billing UX.
