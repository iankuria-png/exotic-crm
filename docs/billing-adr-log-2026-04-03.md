# Billing ADR Log

Date opened: 2026-04-03
Project: Exotic CRM billing refactor
Status: Active decision log

Use this file as the compact architectural decision record for Phase `0A` and later billing-phase gates.

## ADR-001: Provider transactions are first-class

Status: Accepted

Decision:

- upstream provider execution state will not be modeled only on `payments`
- the program uses first-class provider transactions with compatibility projection back to legacy payment rows during migration

Why:

- retries, fallbacks, proxy lifecycles, and provider-family differences cannot be represented safely with a single payment-row reference

Primary references:

- [payment-billing-decoupling-spec-2026-04-03.md](/Users/ian/Projects/exotic-crm/docs/payment-billing-decoupling-spec-2026-04-03.md)
- [payment-billing-implementation-backlog-2026-04-03.md](/Users/ian/Projects/exotic-crm/docs/payment-billing-implementation-backlog-2026-04-03.md)

## ADR-002: Execution snapshots are immutable and canonical

Status: Accepted

Decision:

- `billing_routing_decisions.snapshot_json` is the canonical persistence owner of the execution contract for initiated billing flows

Why:

- in-flight payments must not drift when admins change routes, profiles, FX, or fallback settings after initiation

## ADR-003: Billing System settings are first-class and CRM-global in release one

Status: Accepted

Decision:

- billing-system settings move out of implicit settings ownership into a first-class model
- release one supports a single CRM-global billing-system record only

Why:

- domains, branding, timing, discount posture, PIN behavior, and credential-delivery controls are runtime-critical and should not be half-migrated

## ADR-004: Dual-write and cutover are mandatory, not optional

Status: Accepted

Decision:

- the Billing workspace remains read-only or dual-write until runtime cutover evidence exists
- divergence detection, reprojection, and rollback-safe repair are part of the design, not cleanup

Why:

- this program is a strangler migration, not a rewrite

## ADR-005: Kenya semantics are explicit

Status: Accepted

Decision:

- `Daraja` and `KopoKopo` are first-class provider types
- `django_proxy` is transitional transport, not the long-term provider model
- `mpesa_stk` remains a compatibility alias only

Why:

- Kenya flows currently mix provider identity and transport identity, which makes routing and fallback unsafe

## ADR-006: WordPress is a versioned consumer of CRM contracts

Status: Accepted

Decision:

- CRM owns billing logic
- WordPress consumes versioned payload contracts with explicit parity windows
- current anchor-client config sync, wallet payload fields, and active/inactive credential semantics are preserved until an explicit later revision

Why:

- WP is an integration consumer, not the place to improvise billing semantics during the migration

## ADR-007: Diagnostics has two surfaces and one backbone

Status: Accepted

Decision:

- `Payment Diagnostics` remains the per-payment operator investigation surface
- `Billing Diagnostics` is the system and configuration health surface in Settings
- both must share one diagnostics backend

Why:

- duplicate diagnostics logic would create contradictory behavior during rollout

## ADR-008: Historical compatibility is bounded

Status: Accepted

Decision:

- historical cohorts must explicitly choose between backfill, selective backfill, or bounded `legacy_composed` support
- perpetual unbounded split diagnostics is not allowed

Why:

- we need migration safety without permanent diagnostic bifurcation

## ADR-009: Underpayment and overpayment behavior is explicit

Status: Accepted

Decision:

- underpayment and overpayment are governed by explicit business rules per payment purpose and provider-family capability
- no silent partial provisioning or silent extra credit in the first release

Why:

- FX and crypto-style payment families make tolerance behavior a product decision, not a hidden implementation detail

## ADR-010: Clean canonical branches, orchestrated integration

Status: Accepted

Decision:

- the current clean CRM branch and clean WP branch remain the only canonical branches
- agents work in ephemeral scratch spaces and return patch packets
- the orchestrator is the only integration point

Why:

- this program is large enough that branch sprawl would become a coordination tax and a regression risk

## ADR-011: Hot-file single-writer locks are mandatory

Status: Accepted

Decision:

- hotspot files like `Settings.jsx`, `Payments.jsx`, `BillingController.php`, `PaymentQueueController.php`, and the runtime billing services are single-writer locked

Why:

- reducing merge conflicts is not enough; we also need to prevent overlapping semantic drift in the most fragile files

## ADR-012: No provider adapter before provider-agnostic runtime

Status: Accepted

Decision:

- no adapter implementation starts until the runtime orchestrator is provider-agnostic and the core contracts are closed

Why:

- otherwise each adapter lane would be forced to invent missing abstractions and destabilize the design

## ADR review rule

If a lane needs to change any accepted ADR:

- it must propose the ADR delta explicitly
- it must show impact on spec, backlog, and execution matrix
- it must not merge implementation work that assumes the new ADR before review closes
