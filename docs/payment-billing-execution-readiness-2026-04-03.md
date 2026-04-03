# Payment Billing Execution Readiness

Date: 2026-04-03
Project: Exotic CRM
Status: Ready for execution planning

Companion docs:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-billing-implementation-backlog-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`
- `docs/payment-billing-agent-execution-matrix-2026-04-03.md`
- `docs/project-context-crm-2026-04-03.md`
- `docs/project-context-wp-2026-04-03.md`
- `docs/billing-adr-log-2026-04-03.md`

## 1. Purpose

This document converts the approved planning set into an execution package.

It answers:

- what must be true before the first implementation merge
- which tickets start first
- how work should be split across lanes
- which safety rails must exist before runtime changes
- what evidence is required before each cutover

## 2. Execution posture

Execution should start as a controlled strangler migration, not a rewrite.

Rules:

- no runtime billing cutover happens in the same tranche as model hardening
- no provider adapter work starts before Phase 0A and Phase 0B are closed
- no Billing Settings tab becomes authoritative before dual-write and shadow-read evidence exist
- every runtime-affecting merge must land behind a feature flag or compatibility wrapper
- every phase must produce rollback evidence, not just happy-path demos

## 3. Preconditions for first code merge

The team should not begin implementation until all of the following are explicitly approved:

- Phase 0A contract set from the backlog is signed off
- lane ownership is assigned
- feature-flag naming and storage strategy is chosen
- browser automation runner is selected and wired into the repo
- database migration rollback procedure is rehearsed locally
- fixture strategy is agreed for legacy payments, retries, fallbacks, proxy flows, and WordPress sync
- provider sandbox availability is recorded for Daraja, KopoKopo, pawaPay, ElemiTech, DusuPay, NOWPayments, and Pesapal compatibility
- telemetry and diff evidence storage location is chosen

## 4. Required execution lanes

Recommended ownership model:

- `Lane A: Contracts and Billing Core`
  - owns Phase 0A, Phase 1, and core parts of Phase 2
  - lead files: billing models, reducers, routing contracts, compatibility projection
- `Lane B: Admin UI and Automation`
  - owns Phase 0B and Billing workspace foundation
  - lead files: `resources/js/pages/Settings.jsx`, extracted Billing components, browser automation
- `Lane C: Runtime and Orchestration`
  - owns Phase 4 and renewal/runtime pieces of Phase 6
  - lead files: billing services, orchestrators, command jobs, controller compatibility wrappers
- `Lane D: Provider Adapters`
  - starts only after Phase 4 entry gate clears
  - lead files: adapter classes, provider tests, provider-family fixtures
- `Lane E: Diagnostics, QA, and Rollout`
  - owns Phase 7, Phase 8, shadow-read evidence, and release runbooks
  - lead files: diagnostics assembler, rollout docs, validation storage, operational reporting

## 5. First safe execution tranche

This is the smallest safe set of work that should start implementation:

- `BILL-004`
- `BILL-005`
- `BILL-006`
- `BILL-007`
- `BILL-008`
- `BILL-011`
- `BILL-012`
- `BILL-013`
- `BILL-014`
- `BILL-015`
- `BILL-016`
- `BILL-017`
- `BILL-018`
- `BILL-019`
- `BILL-020`
- `BILL-001`
- `BILL-002`
- `BILL-003`
- `BILL-009`
- `BILL-010`

Output of this tranche:

- model and migration contracts are frozen
- UI seam extraction has started
- automation scaffolding exists
- the repo is ready for real implementation without architectural churn

## 6. Ticket start order

Recommended order inside the first tranche:

### Step 1: Lock the contracts

- `BILL-004`
- `BILL-005`
- `BILL-006`
- `BILL-011`
- `BILL-012`
- `BILL-013`
- `BILL-014`
- `BILL-015`
- `BILL-016`
- `BILL-017`
- `BILL-018`
- `BILL-019`
- `BILL-020`

Gate:

- no ambiguity remains in state, compatibility, projection, proxy, WordPress, FX, or cutover rules

### Step 2: Build safety scaffolding

- `BILL-001`
- `BILL-002`
- `BILL-003`
- `BILL-009`
- `BILL-010`

Gate:

- browser automation can run
- Billing UI seams exist
- role/redaction rules are testable before the new workspace expands

### Step 3: Start implementation only after Steps 1 and 2 close

Next sequence:

- `BILL-101` to `BILL-104`
- `BILL-201` to `BILL-215`

## 7. Branching and merge policy

Recommended execution strategy:

- the current clean CRM branch remains the canonical integration branch for CRM work
- the current clean WordPress branch remains the canonical integration branch for WP work
- the orchestrator and reviewer is the only actor who applies integrated changes onto those canonical branches
- parallel work should happen in ephemeral agent workspaces or temporary local worktrees, not through a growing set of shared long-lived git branches
- if a temporary local ticket branch is needed for tooling or safety, it should be short-lived, unnamed beyond the ticket, and deleted immediately after integration

Merge policy:

- only one open PR may change a given ticket's `Primary write scope`
- PRs that touch runtime billing must include explicit flag default state
- PRs that touch migrations must include forward and rollback notes
- PRs that touch WordPress contract code must include parity evidence
- PRs that touch diagnostics must include both `Payment Diagnostics` and `Billing Diagnostics` screenshots or fixtures where relevant

Shared-repo discipline:

- agents do not push directly to the canonical CRM or WP branches
- agents deliver reviewed patch packets, not “final” shared-branch commits
- the orchestrator applies, verifies, and sequences those patches on the canonical branches
- if two agents need the same primary file set, the later lane waits rather than creating a parallel conflicting change stream

## 8. Required feature flags before runtime work

The following flags should exist before Phase 2 runtime-facing work:

- `billing.registry.enabled`
- `billing.dual_write.enabled`
- `billing.shadow_read.enabled`
- `billing.billing_system_live_read.enabled`
- `billing.market_surface_cutover.<market>.<surface>`
- `billing.diagnostics.v2.enabled`
- `billing.wordpress.versioned_payloads.enabled`
- `billing.wallet_auto_renew.enabled`
- `billing.provider_family.<provider>.enabled`

Rules:

- flags default to `off`
- flags must be overridable per environment
- rollout evidence must record which flags were active for a test or market

## 9. Test and fixture preparation

Before Phase 2 code merges, prepare:

- legacy payment fixtures for `manual`, `stk`, `link`, `wallet`, `free_trial`
- retry and fallback fixtures across multiple provider transactions
- proxy-session fixtures for unopened, opened, expired, rotated, and late-init paths
- WordPress sync fixtures for config, balance, and credential payload families
- underpayment and overpayment fixtures for provider families that support tolerance rules
- sandbox/live separation fixtures

Required test layers:

- unit tests for reducers, route resolution, compatibility projection, and retry/fallback lineage
- feature tests for Billing Settings saves, activation methods, payment-link generation, wallet auto-renew, and reconciliation
- browser automation for Billing Settings, Diagnostics, Payments drawer, and CRM activation flows
- contract tests for WordPress payload versions

## 10. Environment and data readiness

Before provider adapters begin:

- record which providers have real sandbox access versus mocked-only support
- record callback URLs and signature-verification requirements per provider
- prepare a seeded staging environment with representative historical payment rows
- capture at least one anonymized example for each currently supported flow in production-like data

The team should also maintain:

- a staging database snapshot before every schema-heavy tranche
- a repeatable migration replay script
- a rollback checklist per migration bundle

## 11. Phase entry gates

### Enter Phase 1 only if:

- Phase 0A and Phase 0B are closed
- branch policy is active
- automation runner is green

### Enter Phase 2 only if:

- provider registry contracts are merged
- execution snapshot authority is implemented
- compatibility projection design is approved by Lane A and Lane E

### Enter Phase 4 only if:

- provider transaction schema exists
- compatibility bridge exists
- dual-write and shadow-read flags exist

### Enter Phase 5 only if:

- runtime orchestrator is provider-agnostic
- no new adapter needs to invent missing core abstractions

### Enter Phase 8 only if:

- diagnostics evidence exists for legacy and new-model flows
- WordPress parity suite is green
- cutover and rollback validation results are stored

## 12. Release evidence required before any live cutover

For each market and billing surface:

- shadow-read diffs show acceptable variance
- provider transaction lineage is visible in diagnostics
- webhook inbox is processing and replay-safe
- payment-link compatibility behavior is preserved
- wallet auto-renew outcomes are visible and auditable
- WordPress payload parity and credential sync are green
- rollback path is documented and rehearsed

No market should be promoted on “manual confidence” alone.

## 13. Immediate execution checklist

The next concrete actions should be:

1. Approve the first tranche ticket set.
2. Assign named owners to Lanes A through E.
3. Create the feature-flag skeleton.
4. Add browser automation tooling to the repo.
5. Create fixture packs for legacy, retry/fallback, proxy, and WordPress flows.
6. Open implementation PRs only for Phase 0A and 0B work.

## 14. Ready-for-execution statement

The billing plan is ready for execution only in this sense:

- planning contradictions are reduced enough to begin Phase 0A and Phase 0B implementation safely
- runtime refactor, provider adapter rollout, and live cutover still require phase gates and evidence

This is an execution-ready migration program, not a permission slip to skip safeguards.
