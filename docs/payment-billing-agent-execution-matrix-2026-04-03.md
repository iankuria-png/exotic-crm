# Payment Billing Agent Execution Matrix

Date: 2026-04-03
Project: Exotic CRM
Status: Execution operating model

Companion docs:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-billing-implementation-backlog-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`
- `docs/payment-billing-execution-readiness-2026-04-03.md`
- `docs/project-context-crm-2026-04-03.md`
- `docs/project-context-wp-2026-04-03.md`
- `docs/billing-adr-log-2026-04-03.md`

## 1. Operating model

This program should run with one orchestrator and multiple bounded lane agents.

Roles:

- `Orchestrator / Reviewer`
  - owns the canonical spec, backlog, and execution docs
  - approves phase entry and exit
  - is the only actor allowed to integrate lane outputs onto the clean CRM and WP branches
  - blocks any lane that drifts outside approved scope or violates safety rails
- `Lane agents`
  - work only inside assigned ticket batches
  - own only their assigned primary file scopes
  - return patch packets, test evidence, and handoff notes to the orchestrator

Branching model:

- the current clean CRM branch is the canonical CRM integration branch
- the current clean WP branch is the canonical WP integration branch
- no long-lived shared refactor branch is introduced
- parallelism happens in ephemeral agent workspaces or temporary local worktrees
- only the orchestrator applies final integrated changes to the canonical branches

## 2. Lane definitions

| Lane | Responsibility | Typical scope |
|---|---|---|
| `Lane A: Contracts and Billing Core` | model contracts, reducers, routing contracts, compatibility projection, schema authority | docs, migrations, `app/Billing/*`, billing models, compatibility readers |
| `Lane B: Admin UI and Automation` | Billing Settings shell, component extraction, browser automation, admin UX states | `resources/js/pages/Settings.jsx`, extracted Billing UI components, browser automation |
| `Lane C: Runtime and Orchestration` | routing, orchestration, renewal flows, runtime compatibility wrappers, sync transport | billing services, API controllers, commands, runtime jobs, `WalletSyncService.php` |
| `Lane D: Provider Adapters` | provider-family adapters, provider tests, provider fixtures | adapter classes, provider integration tests, provider-specific DTOs |
| `Lane E: Diagnostics, QA, and Rollout` | diagnostics, parity evidence, shadow-read validation, rollout gates, release runbooks | diagnostics backend/UI, rollout docs, validation storage, QA/reporting |

## 3. Phase-by-phase execution matrix

| Phase | Lead lane | Support / review lanes | Ticket batch | Primary write scope | CRM/WP boundary | Merge rule |
|---|---|---|---|---|---|---|
| `0A` Model hardening | `Lane A` | `Lane E` review | `BILL-004-008`, `BILL-011-020` | planning docs only | `CRM+WP contract validation` | serialize under one doc owner at a time |
| `0B` Safety scaffolding | `Lane B` | `Lane A`, `Lane E` | `BILL-001-003`, `BILL-009-010` | flags, UI seams, browser automation | `CRM+WP contract validation` | one PR at a time for `Settings.jsx` and test harness files |
| `1` Registry and abstractions | `Lane A` | orchestrator review | `BILL-101-104` | `app/Billing/*`, registry/routing contracts | `CRM-only` | freeze interfaces before later lanes start |
| `2` Data model and bridge | `Lane A` | `Lane E` review | `BILL-201-215` | migrations, billing models, projectors, snapshots, bridge | `CRM+WP contract validation` | schema and persistence changes stay single-writer |
| `3` Billing workspace foundation | `Lane B` | `Lane A` contract review | `BILL-301-311` | `Settings.jsx`, Billing components, settings controllers | `CRM-only` | UI remains read-only or dual-write until approved |
| `4` Runtime routing refactor | `Lane C` | `Lane A`, `Lane E` | `BILL-401-415` | runtime billing services, controllers, commands | `CRM+WP implementation coordination` | single-writer windows for runtime hot files |
| `5` Provider adapters | `Lane D` | `Lane C`, orchestrator review | `BILL-501-508` | provider adapters and adapter tests | `CRM+WP implementation coordination` where exposed to WP | adapters cannot invent missing core abstractions |
| `6` Renewal and WP surfaces | `Lane C` | `Lane B`, `Lane E` | `BILL-601-608` | renewal runtime, CRM activation UI, WP sync transport | `CRM+WP implementation coordination` | runtime and UI changes merge in controlled slices |
| `7` Diagnostics | `Lane E` | `Lane C`, `Lane B` | `BILL-701-709` | diagnostics assembler, Payments drawer, Billing Diagnostics | `CRM+WP contract validation` | diagnostics backend before diagnostics UI expansion |
| `8` Rollout and cleanup | `Lane E` | `Lane A`, `Lane C` | `BILL-801-807` | flags, rollout docs, diff evidence, cleanup | `CRM+WP implementation coordination` | no cleanup until cutover evidence is complete |

## 4. Hot file lock map

These files are collision hotspots and should never have overlapping active lane work:

- `resources/js/pages/Settings.jsx`
  - used in `0B`, `3`, and `7`
- `resources/js/pages/Payments.jsx`
  - used in `6` and `7`
- `app/Http/Controllers/API/BillingController.php`
  - used in `4`, `6`, and `7`
- `app/Http/Controllers/CRM/PaymentQueueController.php`
  - diagnostics hotspot in `7`
- `app/Services/PaymentCompletionService.php`
  - canonical runtime hotspot in `4`
- `app/Services/BillingGatewayService.php`
  - runtime hotspot in `4`
- `app/Services/WalletSyncService.php`
  - WP sync hotspot in `6`
- `app/Billing/Routing/*`
  - contract hotspot in `1`, dependency for `4` and `7`

Rule:

- only one lane may hold a hot-file lock at a time
- the orchestrator decides lock release after reviewing the handoff packet

## 5. Handoff packet required from every lane

Every handoff must include:

- ticket IDs completed
- base commit SHA or integration point
- exact files changed
- feature flags touched and their default state
- migrations added or modified
- tests run and exact commands used
- fixtures added or relied on
- rollback note
- open dependencies or blockers
- explicit reviewer ask

Lane-specific additions:

- `Lane A`
  - contract delta summary
  - schema or state diagram if semantics changed
  - one compatibility example for each affected legacy behavior
- `Lane B`
  - screenshots or recordings
  - permission/redaction/loading/degraded state coverage
  - API contracts consumed
- `Lane C`
  - call-path map before and after
  - idempotency and rollback notes
  - mixed-population and cutover impact
- `Lane D`
  - provider capability summary
  - callback/signature rules
  - normalized state mapping
  - sandbox status
- `Lane E`
  - parity/shadow-read evidence
  - rollout checklist updates
  - cutover or rollback recommendation

## 6. Reviewer gates before integration

The orchestrator should not integrate a lane packet unless:

- the packet stays within approved ticket scope
- no other active lane owns the same hot file set
- runtime-affecting work is behind a flag or compatibility wrapper
- migration work includes rollback notes
- WP-facing changes include parity evidence
- diagnostics changes preserve the split between `Payment Diagnostics` and `Billing Diagnostics`
- test commands and outcomes are included
- unresolved blockers are called out explicitly

Automatic escalation:

- contract or projection changes require `Lane A` review
- diagnostics, parity, rollout, or cutover changes require `Lane E` review
- WordPress-facing contract or sync behavior requires both `Lane A` and `Lane C` sign-off before orchestration review closes

## 7. CRM and WordPress coordination matrix

| Phase | Boundary | Coordination rule |
|---|---|---|
| `0A` | `CRM+WP contract validation` | WP sign-off required on payload/version contracts, but no WP implementation ships yet |
| `0B` | `CRM+WP contract validation` | fixtures and automation must cover WP contract assumptions |
| `1` | `CRM-only` | keep WP bridge on compatibility projection |
| `2` | `CRM+WP contract validation` | new model lands, but WP output remains parity-safe and versioned |
| `3` | `CRM-only` | Billing workspace remains non-authoritative to WP consumers |
| `4` | `CRM+WP implementation coordination` | method visibility and routing behavior must match WP consumer readiness |
| `5` | `CRM+WP implementation coordination` | providers exposed to WP must not go live before WP supports them |
| `6` | `CRM+WP implementation coordination` | renewal methods, wallet status, and sync transport are shared behavior |
| `7` | `CRM+WP contract validation` | diagnostics must expose WP sync and parity health |
| `8` | `CRM+WP implementation coordination` | no live cutover unless WP parity suite is green |

WP-touching ownership:

- payload schemas and versioning: `Lane A`
- payload builders and compatibility serializers: `Lane A`
- sync transport, retry, and idempotency: `Lane C`
- WP-facing activation and renewal behavior: `Lane C`
- Billing Settings UX for WP credentials and state: `Lane B`
- WP parity suite, sync fixtures, and cutover evidence: `Lane E`

## 8. No-go conditions by lane

- `Lane A`
  - stop if any Phase 0A contract is still ambiguous
- `Lane B`
  - stop if automation is not wired or Billing UI would become authoritative early
- `Lane C`
  - stop if feature flags, execution snapshot authority, or compatibility bridge are missing
- `Lane D`
  - stop if provider adapters need to invent missing core abstractions
- `Lane E`
  - stop if shadow-read evidence, WP parity, or rollback evidence is missing

## 9. Day-one assignment sheet

Recommended day-one split:

- `Lane A`
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
- `Lane B`
  - `BILL-001`
  - `BILL-002`
  - `BILL-009`
- `Lane E`
  - `BILL-003`
  - `BILL-010`

Day-one rule:

- do not start `BILL-101+` until the orchestrator declares Phase 0A and Phase 0B closed

## 10. Frictionless workflow rules

To keep this frictionless:

- lanes do not debate architecture in code PRs after Phase 0A closes
- lanes do not self-expand ticket scope
- agents return patch packets, not shared-branch commits
- the orchestrator integrates in sequence on the clean CRM and WP branches
- if a lane is blocked on another lane’s hot file, it waits and reports rather than rebasing around the conflict

This gives you one reviewer, one integration point, and parallel specialist work without branch sprawl.
