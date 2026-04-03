# Payment Billing Implementation Backlog

Date: 2026-04-03
Project: Exotic CRM
Companion docs:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`
- `docs/payment-billing-execution-readiness-2026-04-03.md`
- `docs/payment-billing-agent-execution-matrix-2026-04-03.md`
- `docs/payment-billing-plan-audit-2026-04-03.md`

## 1. How to use this backlog

This backlog is designed for phased delivery with low-regression rollout.

Execution rules:

- only one ticket owner writes to a primary file set at a time
- new billing behavior ships behind feature flags
- legacy config remains readable until final cleanup
- the new Billing workspace is read-only or dual-writing until runtime cutover
- provider adapters are not built until model hardening is complete
- no provider implementation starts until Kenya and crypto semantics are resolved

Ticket format:

- `BILL-###`

Ownership format:

- `Primary write scope`: the files that ticket owns
- `Secondary touch points`: narrow integration files that may change

## 2. Delivery lanes

Recommended engineering lanes:

- `Lane A: Billing Model and Core`
- `Lane B: Billing Settings UI`
- `Lane C: Billing Runtime and Orchestration`
- `Lane D: Provider Adapters`
- `Lane E: Diagnostics, QA, and Rollout`

## 3. Phase overview

```text
Phase 0A  Model hardening and migration contract
Phase 0B  Safety scaffolding, UI seams, and automation
Phase 1   Provider registry and billing core abstractions
Phase 2   Data model, provider transactions, and compatibility bridge
Phase 3   Billing workspace foundation
Phase 4   Runtime routing and orchestration refactor
Phase 5   Provider adapters
Phase 6   Wallet renewals, market policy, and WordPress surfaces
Phase 7   Shared diagnostics foundation and dual surfaces
Phase 8   Rollout, cleanup, and final hardening
```

## 4. Phase 0A: Model hardening and migration contract

Phase goal:

- eliminate the architectural assumptions that would make the refactor unsafe

Phase exit gate:

- canonical state model is approved
- provider transaction model is approved
- legacy payment-row compatibility semantics are approved
- billing system settings authority is approved
- proxy-session lifecycle is approved
- execution snapshot and immutability rules are approved
- retry, fallback, and reconciliation contracts are approved
- FX policy is approved
- capability taxonomy is approved
- source-of-truth and dual-write rules are approved
- divergence repair and rollback reprojection rules are approved
- Kenya semantics are approved
- WordPress payload parity and versioning rules are approved
- payment-link URL compatibility rules are approved
- mixed-population cutover matrix is approved
- permissions, redaction, and operator UX migration are approved
- Settings decomposition and QA suite split are approved
- sandbox/live preservation rules are approved

### BILL-004: Finalize provider capability taxonomy

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/BillingModeService.php`
- `app/Services/PaymentLinkService.php`

Deliverables:

- canonical taxonomy for billing surface, rail, transport, network, operation, settlement model, and restrictions

Acceptance:

- the team can classify Daraja, KopoKopo, pawaPay, ElemiTech, DusuPay, NOWPayments, and Pesapal without ambiguity

Tests:

- none yet; planning artifact only

### BILL-005: Finalize provider transaction model

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Models/Payment.php`
- `app/Services/PaymentCompletionService.php`

Deliverables:

- approved normalized model for upstream transaction state

Acceptance:

- the spec explicitly covers invoice id, checkout id, external status, amounts, settlement, expiry, and confirmation state
- the approved model defines how `payments.provider_key` and `payments.transaction_reference` project from multi-transaction lineage during migration

Tests:

- none yet; planning artifact only

### BILL-006: Finalize source-of-truth and dual-write contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/WalletSettingsService.php`
- `app/Services/BillingModeService.php`
- `app/Services/WalletSyncService.php`

Deliverables:

- documented precedence rules between legacy JSON, new tables, and WordPress-facing synced config

Acceptance:

- there is no ambiguity about what runtime reads before, during, and after cutover
- write ordering, partial-failure handling, divergence detection, and rollback-safe reprojection are explicitly defined

Tests:

- none yet; planning artifact only

### BILL-007: Finalize Kenya provider semantics

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/LegacyStkService.php`
- `app/Services/BillingGatewayService.php`
- `app/Services/KopokopoService.php`

Deliverables:

- approved model for Daraja, KopoKopo, `django_proxy`, and legacy `mpesa_stk`

Acceptance:

- wallet funding and subscription push semantics are explicitly separated where needed

Tests:

- none yet; planning artifact only

### BILL-008: Finalize sandbox and test-mode preservation rules

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Models/Payment.php`
- `app/Services/PaymentCompletionService.php`
- `app/Http/Controllers/API/BillingController.php`

Deliverables:

- approved contract for `provider_environment` and `payment_data.test_mode`

Acceptance:

- the migration cannot accidentally convert sandbox semantics into live provisioning behavior

Tests:

- none yet; planning artifact only

### BILL-011: Finalize canonical state model and transition rules

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/PaymentCompletionService.php`
- `app/Console/Commands/ReconcilePendingPayments.php`

Deliverables:

- approved canonical state model for provider, payment, wallet funding, provisioning, and auto-renew outcomes

Acceptance:

- the team can map every active provider family into one internal transition table without provider-specific semantics leaking into runtime
- purpose-specific transitions are defined for wallet funding, subscription payments, sandbox suppression, provisioning failure, and post-settlement reversal
- first-release business actions for underpayment and overpayment are explicitly defined by payment purpose

Tests:

- none yet; planning artifact only

### BILL-012: Finalize billing system settings model and authority contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/BillingModeService.php`
- `app/Services/WalletSettingsService.php`

Deliverables:

- approved model and authority contract for billing domains, branding, redirect timing, polling defaults, SMTP, PINs, and discount posture

Acceptance:

- there is a first-class destination for current `wallet_system_config` behavior
- the plan defines a separate global live-read flip for billing-system settings distinct from market/surface routing cutover

Tests:

- none yet; planning artifact only

### BILL-013: Finalize proxy-session lifecycle and execution snapshot contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/PaymentLinkService.php`
- `app/Http/Controllers/API/BillingController.php`
- `app/Http/Controllers/API/PaymentController.php`

Deliverables:

- approved model for proxy-session lifecycle, legacy `link_proxy` compatibility, and immutable execution snapshots

Acceptance:

- in-flight payments are guaranteed to remain pinned to their initiation contract until terminal state
- proxy-session lifecycle defines exactly when the first provider transaction is created and how rotation, unopened expiry, and late initialization behave

Tests:

- none yet; planning artifact only

### BILL-014: Finalize retry, fallback, reconciliation, and FX contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/PaymentAttemptService.php`
- `app/Console/Commands/ReconcilePendingPayments.php`
- `app/Http/Controllers/API/PaymentController.php`

Deliverables:

- approved lineage model for attempts and provider transactions
- approved reconciliation and polling policy
- approved FX policy including rate provenance and rounding

Acceptance:

- retries, fallbacks, polling, reconciliation, and FX behavior are all contractually defined before implementation
- precedence rules for late webhooks, superseded retries/fallbacks, and winning terminal transactions are explicitly defined

Tests:

- none yet; planning artifact only

### BILL-015: Finalize WordPress payload parity and versioning contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/WalletSyncService.php`
- `tests/Feature/WalletSyncPhaseSixTest.php`

Deliverables:

- approved versioned payload contract for balance, config, and credential sync

Acceptance:

- migration preserves payload parity and delayed-rotation semantics for WordPress consumers
- migration preserves current anchor-client config sync delivery, `mode`, `last_topup`, `transactions`, and active-vs-inactive credential delivery semantics

### BILL-017: Finalize legacy payment-row compatibility projection rules

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Models/Payment.php`
- `app/Services/PaymentCompletionService.php`

Deliverables:

- approved rules for projecting `payments.provider_key` and `payments.transaction_reference` from provider-transaction lineage

Acceptance:

- there is no ambiguity about compatibility projection before, during, or after retry/fallback flows

Tests:

- none yet; planning artifact only

### BILL-018: Finalize divergence repair and rollback reprojection contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- cutover and rollout sections of this backlog

Deliverables:

- approved rules for write ordering, drift detection, reprojection, alerting, and rollback-safe repair

Acceptance:

- dual-write and shadow-read divergences have an explicit operational recovery path

Tests:

- none yet; planning artifact only

### BILL-019: Finalize payment-link URL compatibility contract

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- `app/Services/PaymentLinkService.php`
- `tests/Unit/PaymentLinkServiceTest.php`

Deliverables:

- approved fallback order and proxy-hosted behavior for payment-link URL resolution

Acceptance:

- the contract preserves current fallback behavior through provider URL, base/path, `wp_api_url`, and domain

Tests:

- none yet; planning artifact only

### BILL-020: Finalize mixed-population lifecycle and cutover matrix

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`

Secondary touch points:

- rollout sections of this backlog

Deliverables:

- approved lifecycle matrix for legacy-initiated, new-model-initiated, cutover, and rollback completion paths

Acceptance:

- there is no ambiguity about how in-flight payments behave across cutover and rollback boundaries

Tests:

- none yet; planning artifact only

Tests:

- none yet; planning artifact only

### BILL-016: Finalize permissions, redaction, operator UX migration, and suite split

Primary write scope:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-architecture-implementation-plan-2026-04-03.md`
- `docs/payment-billing-implementation-backlog-2026-04-03.md`

Secondary touch points:

- `app/Services/MarketAuthorizationService.php`
- `resources/js/pages/Settings.jsx`
- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`

Deliverables:

- approved permission matrix including `marketing`
- approved diagnostics redaction rules
- approved staged migration away from normal operator provider selection
- approved compatibility-vs-new-model suite split and wallet wording glossary

Acceptance:

- there is no ambiguity about who can see, edit, or simulate billing behavior and how the operator UX changes over time

Tests:

- none yet; planning artifact only

## 5. Phase 0B: Safety scaffolding, UI seams, and automation

Phase goal:

- add flags, baseline tests, frontend seams, and browser coverage before touching business logic

Phase exit gate:

- current behavior is snapshotted
- new billing flags exist and are off
- Settings has a Billing seam
- browser automation exists for core billing flows

### BILL-001: Add billing feature-flag scaffold

Primary write scope:

- `config/billing.php` new
- `config/services.php`
- `app/Providers/AppServiceProvider.php`

Secondary touch points:

- `.env.example`

Deliverables:

- feature flags for registry, routing, provider transactions, auto-renew, diagnostics, dual-write, and Billing workspace

Acceptance:

- app boots with all new flags disabled
- no existing payment flow changes when flags are off

Tests:

- config bootstrap assertions

### BILL-002: Capture baseline behavior snapshots

Primary write scope:

- `tests/Feature/PaymentDiagnosticsTest.php`
- `tests/Feature/PaymentLinkProviderSettingsTest.php`
- `tests/Feature/WalletApiPhaseFiveTest.php`
- `tests/Feature/SubscriptionProvisioningConvergenceTest.php`

Secondary touch points:

- `tests/Feature/LegacyStkRoutingTest.php`
- `tests/Feature/SelfCheckoutApiTest.php`
- `tests/Feature/WalletSyncPhaseSixTest.php`

Deliverables:

- baseline assertions for current settings, diagnostics, wallet, WP sync, and activation behavior

Acceptance:

- current production behavior is codified before refactor begins

### BILL-003: Create billing namespace skeleton

Primary write scope:

- `app/Billing/Contracts/` new
- `app/Billing/Providers/` new
- `app/Billing/Routing/` new
- `app/Billing/Support/` new
- `app/Billing/Diagnostics/` new

Secondary touch points:

- `composer.json` only if autoload changes are needed

Deliverables:

- typed namespace skeleton for later tickets

Acceptance:

- no runtime paths use the new namespace yet

### BILL-009: Extract Billing workspace shell from Settings page

Primary write scope:

- `resources/js/pages/Settings.jsx`
- `resources/js/components/billing/` new

Secondary touch points:

- `resources/js/services/api.js`

Deliverables:

- dedicated Billing workspace shell and component boundary inside Settings

Acceptance:

- billing-specific UI state is no longer trapped inside one giant component branch
- Billing no longer depends on a single `settings-integrations` render branch
- each Billing tab has independent query keys and invalidation boundaries
- each Billing tab has `loading`, `empty`, `degraded`, and `forbidden` states
- diagnostics tab is lazy-loaded
- existing non-billing areas behave as before

Tests:

- smoke rendering tests if harness exists

### BILL-010: Add browser automation harness for billing flows

Primary write scope:

- `package.json`
- `playwright.config.*` new
- `tests/browser/` new

Secondary touch points:

- CI config only if already present

Deliverables:

- browser automation setup for Billing settings, activation, wallet, and diagnostics

Acceptance:

- at least `10+` automated browser flows run locally
- coverage includes `admin`, `sub_admin`, and `sales` paths
- coverage includes one out-of-scope/forbidden case
- coverage includes one degraded diagnostics case
- coverage includes one wallet auto-renew fallback case

Tests:

- initial browser suite split into compatibility and new-model paths

## 6. Phase 1: Provider registry and billing core abstractions

Phase goal:

- replace hard-coded provider assumptions with a registry and route primitives

Phase exit gate:

- runtime can resolve providers without fixed allowlists
- provider metadata includes the approved capability taxonomy

### BILL-101: Build provider contract and registry

Primary write scope:

- `app/Billing/Contracts/BillingProviderAdapter.php` new
- `app/Billing/Providers/ProviderRegistry.php` new
- `app/Billing/Providers/ProviderDefinition.php` new
- `app/Billing/Support/BillingSurface.php` new

Secondary touch points:

- `app/Services/WalletSettingsService.php`
- `app/Services/BillingModeService.php`

Deliverables:

- provider registry keyed by provider type
- capability declarations including rails, transport, currency, and restrictions

Acceptance:

- `WalletSettingsService` no longer owns the canonical provider list
- providers can declare constraints beyond simple surface flags

Tests:

- registry lookup unit tests

### BILL-102: Build provider credential schema registry

Primary write scope:

- `app/Billing/Contracts/ProviderCredentialSchema.php` new
- `app/Billing/Providers/ProviderSchemaRegistry.php` new
- `app/Billing/Providers/Schemas/` new

Secondary touch points:

- `app/Http/Controllers/CRM/SettingsController.php`

Deliverables:

- schema-driven field definitions for Daraja, KopoKopo, pawaPay, ElemiTech, DusuPay, NOWPayments, Pesapal, and legacy Paystack

Acceptance:

- provider settings can be generated from schema metadata instead of hard-coded forms

Tests:

- schema validation unit tests

### BILL-103: Build billing routing value objects and resolver

Primary write scope:

- `app/Billing/Routing/BillingRouteResolver.php` new
- `app/Billing/Routing/BillingRouteDecision.php` new
- `app/Billing/Routing/BillingFallbackChain.php` new
- `app/Billing/Routing/BillingExecutionMode.php` new

Secondary touch points:

- `app/Services/BillingModeService.php`

Deliverables:

- market-aware routing resolution by billing surface
- explicit `direct`, `proxy`, and transitional execution modes

Acceptance:

- runtime can request a route for wallet funding, subscription link, subscription push, wallet auto-renew, and self-checkout

Tests:

- routing unit tests for primary and fallback selection

### BILL-104: Build normalized provider result DTOs

Primary write scope:

- `app/Billing/Support/ProviderInitResult.php` new
- `app/Billing/Support/ProviderStatusResult.php` new
- `app/Billing/Support/ProviderWebhookParseResult.php` new

Secondary touch points:

- `app/Services/HostedCheckoutService.php`
- `app/Services/LegacyStkService.php`
- `app/Services/KopokopoService.php`

Deliverables:

- normalized initiation, status, and webhook parse contracts

Acceptance:

- later adapters plug into one orchestration path

Tests:

- DTO normalization unit tests

## 7. Phase 2: Data model, provider transactions, and compatibility bridge

Phase goal:

- introduce the new billing schema without breaking production runtime

Phase exit gate:

- new tables exist
- provider transaction model exists
- legacy payment compatibility projection exists
- billing system settings model exists
- proxy-session model exists or compatibility contract is in place
- execution snapshots are persistable
- legacy config can be projected safely
- divergence repair and reprojection scaffolding exists
- dual-write rules are implemented

### BILL-201: Create billing configuration migrations

Primary write scope:

- migrations for:
  - `billing_provider_profiles`
  - `billing_market_provider_bindings`
  - `billing_routing_rules`
  - `billing_wallet_rules`
  - `billing_subscription_rules`
  - `billing_routing_decisions`

Secondary touch points:

- none

Deliverables:

- core billing configuration schema

Acceptance:

- migrations run cleanly

### BILL-202: Add billing models and repositories

Primary write scope:

- `app/Models/BillingProviderProfile.php` new
- `app/Models/BillingMarketProviderBinding.php` new
- `app/Models/BillingRoutingRule.php` new
- `app/Models/BillingWalletRule.php` new
- `app/Models/BillingSubscriptionRule.php` new
- `app/Models/BillingRoutingDecision.php` new
- `app/Billing/Repositories/` new

Secondary touch points:

- `app/Models/Platform.php`

Deliverables:

- model and repository access for billing configuration

Acceptance:

- configuration reads no longer depend on raw nested arrays alone

### BILL-203: Build legacy config projection service

Primary write scope:

- `app/Billing/Support/LegacyBillingConfigProjector.php` new
- `app/Services/WalletSettingsService.php`
- `app/Services/BillingModeService.php`

Secondary touch points:

- `app/Http/Controllers/CRM/SettingsController.php`

Deliverables:

- projection from new billing config into legacy runtime shapes

Acceptance:

- markets without new billing rows still behave like production today
- projection includes more than just `wallet_settings` and `payment_link_providers`
- projection covers billing domains, branding, timing values, SMTP-dependent billing behaviors, discount posture, and PIN/approval posture used by runtime billing paths

Tests:

- extend wallet settings, payment-link settings, and wallet sync feature tests

### BILL-204: Build one-time migration command for billing config

Primary write scope:

- `app/Console/Commands/MigrateBillingConfig.php` new

Secondary touch points:

- `app/Billing/Support/LegacyBillingConfigProjector.php`

Deliverables:

- migration command that copies legacy billing configuration into new tables

Acceptance:

- dry-run and apply modes both exist
- command supports idempotent reruns, per-market resume, drift reporting, and rollback-safe reprojection

### BILL-205: Create provider transaction migration and model

Primary write scope:

- migration for `billing_provider_transactions`
- `app/Models/BillingProviderTransaction.php` new

Secondary touch points:

- `app/Models/Payment.php`

Deliverables:

- normalized upstream-transaction storage

Acceptance:

- one payment can be linked to structured provider-side lifecycle state
- one payment can be linked to multiple provider transactions across retry and fallback attempts
- provider transactions can persist lineage, FX, settlement, expiry, and reconciliation metadata without falling back to ad hoc JSON blobs
- compatibility projection rules for `payments.provider_key` and `payments.transaction_reference` are implemented or explicitly scaffolded

Tests:

- schema and model relationship tests

### BILL-206: Create hardened webhook inbox migration and model

Primary write scope:

- migration for `billing_webhook_events`
- `app/Models/BillingWebhookEvent.php` new

Secondary touch points:

- none

Deliverables:

- webhook inbox with raw body, headers, dedupe, verification, retry, and provider-transaction linkage

Acceptance:

- the webhook schema satisfies current verification needs and future async processing

Tests:

- schema tests

### BILL-207: Build dual-write compatibility service

Primary write scope:

- `app/Billing/Support/BillingConfigDualWriter.php` new
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- `app/Services/WalletSettingsService.php`

Deliverables:

- service that writes new config and compatible legacy projections during cutover

Acceptance:

- new Billing UI can be safely editable before runtime fully cuts over
- writes fail safely on projection failure and surface divergence telemetry when repair is required

Tests:

- feature tests proving saved config is reflected in legacy runtime shapes

### BILL-208: Preserve sandbox and test-mode semantics in migration

Primary write scope:

- `app/Models/Payment.php`
- `app/Services/PaymentCompletionService.php`
- `app/Http/Controllers/API/BillingController.php`

Secondary touch points:

- diagnostics tests

Deliverables:

- explicit compatibility behavior for `provider_environment` and `payment_data.test_mode`

Acceptance:

- sandbox flows remain operationally distinct from live flows throughout migration

Tests:

- sandbox and completion feature tests

### BILL-209: Create billing system settings migration, model, and projection contract

Primary write scope:

- migration for `billing_system_settings`
- `app/Models/BillingSystemSetting.php` new
- `app/Billing/Support/LegacyBillingSystemProjector.php` new

Secondary touch points:

- `app/Services/BillingModeService.php`
- `app/Services/WalletSettingsService.php`

Deliverables:

- normalized home for billing domains, branding, timing values, notification posture, and system-level billing settings that runtime already depends on

Acceptance:

- billing system settings can be read independently from market/provider rules
- runtime-critical settings currently sourced from legacy config have a documented projection path
- first release treats billing system settings as CRM-global only; narrower scopes are explicitly deferred
- a dedicated global live-read flip exists for billing-system settings separate from market routing cutover

Tests:

- feature tests for billing system settings reads, projection, and precedence

### BILL-210: Create proxy-session migration, model, and compatibility reader

Primary write scope:

- migration for `billing_proxy_sessions`
- `app/Models/BillingProxySession.php` new
- compatibility readers in billing runtime services

Secondary touch points:

- `app/Services/PaymentLinkService.php`
- `app/Http/Controllers/API/BillingController.php`

Deliverables:

- first-class storage for proxy lifecycle state, token/session identifiers, callback posture, and final outcome

Acceptance:

- high-risk proxy flows no longer depend only on `payment_data.link_proxy`
- historical and in-flight compatibility reads remain possible during rollout
- proxy-session lifecycle defines when the first provider transaction is created and how rotation, unopened expiry, and late initialization bind into lineage

Tests:

- proxy compatibility and lifecycle feature tests

### BILL-211: Persist execution snapshots and immutable initiation contract

Primary write scope:

- canonical snapshot persistence on `billing_routing_decisions` plus summary fields or pointers on payment and provider transaction records
- runtime support classes for immutable execution snapshots

Secondary touch points:

- `app/Services/PaymentLinkService.php`
- `app/Services/BillingGatewayService.php`
- `app/Services/DealPaymentService.php`

Deliverables:

- immutable snapshot of route, provider profile, proxy mode, FX contract, and execution family captured at initiation time
- `billing_routing_decisions.snapshot_json` established as the canonical storage owner for the execution contract

Acceptance:

- in-flight payments are not reinterpreted if admin config changes after initiation
- payment and provider transaction records only store denormalized snapshot summaries or pointers, not competing authoritative snapshots

Tests:

- feature tests proving callback, reconciliation, and completion use the stored execution snapshot

### BILL-212: Extend schema for lineage, FX, and diagnostics governance

Primary write scope:

- migrations adjusting `billing_provider_transactions`
- migrations adjusting `billing_routing_decisions`
- diagnostics-related schema additions as needed

Secondary touch points:

- billing models

Deliverables:

- structured lineage fields for retries and fallbacks
- FX quote and settlement fields
- governance fields needed for redacted diagnostics and historical continuity

Acceptance:

- fallback chains, FX decisions, and diagnostics redaction do not rely on unstructured payload storage

Tests:

- schema tests for lineage, FX, and diagnostics fields

### BILL-213: Version WordPress billing payloads and serializers

Primary write scope:

- WordPress projection DTOs/serializers
- `app/Services/WalletSyncService.php`

Secondary touch points:

- API controllers serving WP-facing billing configuration

Deliverables:

- versioned WordPress-facing payload contract with compatibility serializers

Acceptance:

- CRM can emit explicit payload versions while preserving current bridge expectations during migration
- payload families, field sets, version-selection rules, and deprecation windows are documented and enforced in serializers and tests
- current anchor-client config sync delivery, balance payload fields, and credential push semantics are preserved until an explicit later contract revision

Tests:

- payload parity tests between legacy and versioned serializers

### BILL-214: Build legacy diagnostics composer and selective backfill strategy

Primary write scope:

- diagnostics support layer
- optional one-time or on-demand backfill helpers

Secondary touch points:

- `app/Http/Controllers/CRM/PaymentQueueController.php`

Deliverables:

- strategy to compose useful diagnostics for historical payments that predate the new model

Acceptance:

- diagnostics can mark records as `legacy_composed` or equivalent instead of pretending full structured lineage exists
- the plan explicitly decides per historical cohort between one-time backfill, bounded selective backfill, or bounded `legacy_composed` support instead of leaving a perpetual unresolved split

Tests:

- diagnostics feature tests for historical legacy records

### BILL-215: Add shadow-read diff storage and surface-level cutover flags

Primary write scope:

- feature-flag/config support
- optional diff-log storage

Secondary touch points:

- billing configuration readers

Deliverables:

- infrastructure for comparing legacy-read vs new-read outcomes before precedence flips

Acceptance:

- cutover can be evaluated by market and billing surface using stored diff results
- mixed-population cutover and rollback scenarios are explicitly represented in stored validation results

Tests:

- feature tests for shadow-read logging, divergence reporting, reprojection, and flag-driven precedence

## 8. Phase 3: Billing workspace foundation

Phase goal:

- build a safe Billing workspace on top of the revised model

Phase exit gate:

- Billing tabs render through extracted components
- provider schemas drive forms
- Billing tabs have distinct data contracts, query keys, and failure states
- permissions and redaction rules are enforced consistently
- saves either dual-write safely or remain read-only

### BILL-301: Add Billing workspace shell to Settings

Primary write scope:

- `resources/js/pages/Settings.jsx`
- `resources/js/components/billing/BillingWorkspace.jsx` new

Secondary touch points:

- `routes/api.php`
- `app/Http/Controllers/CRM/SettingsController.php`

Deliverables:

- Billing workspace tab group inside Settings

Acceptance:

- existing non-billing settings stay unchanged
- Billing workspace is feature-flagged

### BILL-302: Implement Providers tab

Primary write scope:

- `resources/js/components/billing/ProvidersTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- `app/Billing/Providers/ProviderRegistry.php`

Deliverables:

- provider catalog with capability and implementation status

Acceptance:

- admins can inspect provider types without editing credentials

### BILL-303: Implement Provider Profiles tab

Primary write scope:

- `resources/js/components/billing/ProviderProfilesTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Billing/Providers/ProviderSchemaRegistry.php`

Secondary touch points:

- `app/Models/BillingProviderProfile.php`

Deliverables:

- create, edit, test, and save provider profiles

Acceptance:

- multiple profiles per provider and market scope are supported
- secrets remain masked after save

### BILL-304: Implement Market Routing tab

Primary write scope:

- `resources/js/components/billing/MarketRoutingTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- `app/Models/BillingRoutingRule.php`
- `app/Models/BillingMarketProviderBinding.php`

Deliverables:

- per-market routing editor by billing surface

Acceptance:

- admins can assign primary and fallback routes for each surface

### BILL-305: Implement Wallet tab on new billing model

Primary write scope:

- `resources/js/components/billing/WalletTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Services/WalletSettingsService.php`

Secondary touch points:

- `app/Models/BillingWalletRule.php`

Deliverables:

- per-market wallet settings backed by new rules

Acceptance:

- wallet limits, presets, UI toggles, and wallet funding routes save correctly

### BILL-306: Implement Subscription Rules tab

Primary write scope:

- `resources/js/components/billing/SubscriptionRulesTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- `app/Models/BillingSubscriptionRule.php`

Deliverables:

- market-level activation, renewal, and fallback rules

Acceptance:

- admins can enable and disable `manual`, `stk`, `link`, `wallet`, and `free_trial` by market

### BILL-307: Implement billing permission matrix in UI and API

Primary write scope:

- `resources/js/pages/Settings.jsx`
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- auth helpers and policies if needed

Deliverables:

- explicit permission mapping for `admin`, `sub_admin`, `sales`, and `marketing`

Acceptance:

- Billing management respects current role model instead of vague “admin/operator” assumptions
- action-level permissions, diagnostics visibility, and redacted states are enforced consistently

### BILL-308: Implement Billing System tab

Primary write scope:

- `resources/js/components/billing/BillingSystemTab.jsx` new
- `app/Http/Controllers/CRM/SettingsController.php`

Secondary touch points:

- `app/Models/BillingSystemSetting.php`

Deliverables:

- dedicated Billing System tab for billing domains, branding, timing, and system-level billing posture

Acceptance:

- admins can configure billing system settings independently from market routing and provider profiles
- tab is clearly separated from market/provider-specific controls

### BILL-309: Decompose Billing workspace data contracts and loaders

Primary write scope:

- `resources/js/pages/Settings.jsx`
- Billing workspace data hooks/components

Secondary touch points:

- Billing settings API endpoints

Deliverables:

- per-tab query boundaries, loaders, and invalidation strategy for the Billing workspace

Acceptance:

- Billing does not depend on a single monolithic `settings-integrations` data contract
- each Billing tab supports `loading`, `empty`, `degraded`, and `forbidden` states
- diagnostics data is lazy-loaded

### BILL-310: Implement permission-aware and redacted states across Billing UI

Primary write scope:

- `resources/js/pages/Settings.jsx`
- Billing workspace components

Secondary touch points:

- settings auth helpers and policies

Deliverables:

- permission-aware forbidden, read-only, and redacted views for Billing tabs

Acceptance:

- `sub_admin`, `sales`, and `marketing` see only the sections and fields they are allowed to access
- diagnostics and secrets are redacted consistently

### BILL-311: Migrate wallet wording and operator-facing copy

Primary write scope:

- Billing workspace components
- CRM payment and wallet UI text where needed

Secondary touch points:

- docs or copy constants if present

Deliverables:

- wording split between wallet funding, wallet adjustment, wallet-paid renewal, and billing diagnostics concepts

Acceptance:

- operator and admin UI no longer uses ambiguous “top-up” language where multiple concepts are involved

## 9. Phase 4: Runtime routing and orchestration refactor

Phase goal:

- move initiation and completion paths onto route decisions, provider transactions, and adapter-ready contracts

Phase exit gate:

- payment-link, hosted checkout, wallet funding, and subscription initiation use the routing engine
- in-flight payments use immutable execution snapshots
- retries, fallbacks, and reconciliation run through structured lineage
- payment-link URL compatibility behavior is preserved or intentionally versioned

### BILL-401: Refactor PaymentLinkService to routing engine

Primary write scope:

- `app/Services/PaymentLinkService.php`
- `app/Billing/Routing/BillingRouteResolver.php`

Secondary touch points:

- `app/Http/Controllers/CRM/PaymentLinkProxyController.php`

Deliverables:

- payment-link creation uses route decisions and provider profiles

Acceptance:

- no hard-coded provider allowlists remain in link resolution
- proxy continuity and token/session state are resolved through route decisions plus proxy-session state, not ad hoc payload reads alone
- direct/static payment-link fallback order remains compatible with current provider URL, base/path, `wp_api_url`, and domain behavior

### BILL-402: Refactor hosted checkout orchestration

Primary write scope:

- `app/Services/HostedCheckoutService.php`
- `app/Http/Controllers/CRM/PaymentLinkProxyController.php`

Secondary touch points:

- adapter contracts

Deliverables:

- hosted checkout initialization runs through adapter-ready paths

Acceptance:

- hosted checkout is no longer limited to Paystack/Pesapal assumptions

### BILL-403: Refactor wallet funding initiation

Primary write scope:

- `app/Services/BillingGatewayService.php`
- `app/Http/Controllers/API/WalletController.php`
- `app/Http/Controllers/CRM/ClientWalletController.php`

Secondary touch points:

- `app/Billing/Routing/BillingRouteResolver.php`

Deliverables:

- customer-funded wallet initiation resolves through market routing and adapters

Acceptance:

- wallet funding and admin wallet adjustment are separate flows in code and UI

### BILL-404: Refactor subscription payment initiation

Primary write scope:

- `app/Services/DealPaymentService.php`
- `app/Http/Controllers/CRM/DealController.php`

Secondary touch points:

- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`

Deliverables:

- activation and renewal initiation use market rules plus routing engine

Acceptance:

- only market-enabled methods are accepted
- routing chooses provider by policy by default
- normal operators do not choose provider profiles directly

### BILL-405: Refactor completion path onto normalized provider transactions

Primary write scope:

- `app/Services/PaymentCompletionService.php`
- `app/Services/WalletCheckoutService.php`
- `app/Services/SubscriptionProvisioningService.php`

Secondary touch points:

- `app/Models/Payment.php`
- `app/Models/BillingProviderTransaction.php`

Deliverables:

- completion path consumes normalized provider results and provider transaction updates

Acceptance:

- wallet funding and subscription completion remain idempotent
- completion reads from immutable execution snapshots and canonical state transitions instead of current config at callback time

### BILL-406: Refactor self-checkout by provider family

Primary write scope:

- `app/Http/Controllers/API/PaymentController.php`
- `app/Services/HostedCheckoutService.php`

Secondary touch points:

- routing engine

Deliverables:

- self-checkout differentiates hosted redirect, push or mobile collection, and crypto invoice families

Acceptance:

- NOWPayments is not forced into hosted-card semantics

### BILL-407: Pin execution snapshot during initiation

Primary write scope:

- payment initiation services
- payment/provider transaction persistence

Secondary touch points:

- route resolver

Deliverables:

- immutable execution snapshot persisted at initiation across payment links, wallet funding, subscription initiation, and self-checkout

Acceptance:

- provider profile, route mode, proxy mode, FX contract, and execution family are frozen for the lifetime of an in-flight payment

### BILL-408: Refactor callback, completion, and reconciliation to snapshot-first reads

Primary write scope:

- `app/Services/PaymentCompletionService.php`
- reconciliation/status-query services
- relevant webhook handlers

Secondary touch points:

- provider transaction model

Deliverables:

- callback, completion, and reconciliation flows resolve against the stored execution contract first

Acceptance:

- admin config changes after initiation do not change how an existing payment is interpreted

### BILL-409: Implement proxy lifecycle service and compatibility bridge

Primary write scope:

- proxy lifecycle service under `app/Billing/`
- `app/Services/PaymentLinkService.php`
- `app/Http/Controllers/API/BillingController.php`

Secondary touch points:

- proxy-session model

Deliverables:

- explicit lifecycle management for proxied flows, including initiation, callback, finalization, and compatibility reads

Acceptance:

- proxy state is observable, durable, and compatible with legacy `payment_data.link_proxy` records during rollout
- token rotation, unopened expiry, and late provider initialization map cleanly onto proxy-session state and provider-transaction lineage

### BILL-410: Build canonical state reducer and transition guardrails

Primary write scope:

- canonical state support classes under `app/Billing/`
- completion and reconciliation integration points

Secondary touch points:

- payment and provider transaction models

Deliverables:

- canonical transition logic for payment intent, provider transaction, settlement, provisioning, and wallet funding states

Acceptance:

- runtime uses a documented reducer instead of ad hoc state mapping per provider path
- the reducer includes purpose-specific transition and compensation rules for wallet funding, subscriptions, sandbox suppression, provisioning failure, and reversals

### BILL-411: Implement retry and fallback orchestration with lineage

Primary write scope:

- billing orchestration services
- routing and provider transaction lineage updates

Secondary touch points:

- diagnostics layer

Deliverables:

- structured retry and fallback orchestration preserving attempt order, parent-child lineage, and reason codes

Acceptance:

- retries and fallbacks can be explained and replayed in diagnostics without guessing from payload history

### BILL-412: Build provider status-query and reconciliation orchestrator

Primary write scope:

- reconciliation/status-query services
- scheduled jobs or commands

Secondary touch points:

- provider adapters

Deliverables:

- capability-aware polling and reconciliation path for providers that need status queries beyond webhooks

Acceptance:

- reconciliation no longer depends on isolated legacy commands or provider-specific branching outside the orchestrator
- late webhooks, superseded transactions, current-provider selection, and winning-terminal selection follow explicit precedence rules

### BILL-413: Implement FX quote locking and settlement tolerance rules

Primary write scope:

- billing orchestration services
- provider transaction persistence

Secondary touch points:

- wallet and completion services

Deliverables:

- explicit FX contract captured at initiation with tolerance rules for completion and settlement handling

Acceptance:

- self-checkout FX overrides and provider-settled amounts are handled predictably and auditable across providers

### BILL-414: Remove normal operator provider selection and add audited admin override

Primary write scope:

- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`
- related controllers/services

Secondary touch points:

- permissions layer

Deliverables:

- operator flows select payment method while provider selection is policy-driven by default

Acceptance:

- only admin-level override paths can force a provider profile, and every override is audited

### BILL-415: Wrap, migrate, or retire legacy manual operational billing flows

Primary write scope:

- legacy API payment flows
- migration shims or retirement helpers

Secondary touch points:

- Payments workspace actions

Deliverables:

- disposition plan implemented for legacy manual operational payment flows that still matter in production

Acceptance:

- legacy manual flows are either preserved behind compatibility wrappers or explicitly retired with equivalent operator tooling

## 10. Phase 5: Provider adapters

Phase goal:

- implement each active provider as an isolated adapter

Phase exit gate:

- all target providers can be configured via profiles and resolved through the registry

### BILL-501: Build Pesapal compatibility adapter

Primary write scope:

- `app/Billing/Providers/Pesapal/PesapalAdapter.php` new
- `app/Billing/Providers/Schemas/PesapalSchema.php` new

Acceptance:

- current Pesapal continuity is preserved behind the adapter path

### BILL-502: Build Daraja adapter

Primary write scope:

- `app/Billing/Providers/Daraja/DarajaAdapter.php` new
- `app/Billing/Providers/Schemas/DarajaSchema.php` new

Acceptance:

- STK initiation, callback parsing, and status normalization work

### BILL-503: Build KopoKopo adapter

Primary write scope:

- `app/Billing/Providers/KopoKopo/KopoKopoAdapter.php` new
- `app/Billing/Providers/Schemas/KopoKopoSchema.php` new

Acceptance:

- KopoKopo direct flow is isolated behind adapter contract

### BILL-504: Build pawaPay adapter

Primary write scope:

- `app/Billing/Providers/PawaPay/PawaPayAdapter.php` new
- `app/Billing/Providers/Schemas/PawaPaySchema.php` new

Acceptance:

- pawaPay can be used on supported mobile-money or hosted collection surfaces

### BILL-505: Build ElemiTech adapter

Primary write scope:

- `app/Billing/Providers/ElemiTech/ElemiTechAdapter.php` new
- `app/Billing/Providers/Schemas/ElemiTechSchema.php` new

Acceptance:

- ElemiTech can initiate supported collection flows and normalize callbacks

### BILL-506: Build DusuPay adapter

Primary write scope:

- `app/Billing/Providers/DusuPay/DusuPayAdapter.php` new
- `app/Billing/Providers/Schemas/DusuPaySchema.php` new

Acceptance:

- DusuPay can initiate supported collection flows and verify callbacks

### BILL-507: Build NOWPayments adapter

Primary write scope:

- `app/Billing/Providers/NOWPayments/NowPaymentsAdapter.php` new
- `app/Billing/Providers/Schemas/NowPaymentsSchema.php` new

Acceptance:

- NOWPayments is exposed only for supported crypto and invoice-based surfaces

### BILL-508: Register all active adapters

Primary write scope:

- `app/Billing/Providers/ProviderRegistry.php`

Acceptance:

- registry resolves all active providers
- deferred providers stay isolated

## 11. Phase 6: Wallet renewals, market policy, and WordPress surfaces

Phase goal:

- turn renewal, expiry, and method visibility into explicit market policy

Phase exit gate:

- wallet auto-renew works by policy
- CRM and WordPress surfaces respect market rules
- WordPress payload and delivery semantics remain parity-safe during migration

### BILL-601: Enforce market activation methods in CRM runtime

Primary write scope:

- `app/Services/DealPaymentService.php`
- `app/Http/Controllers/CRM/DealController.php`
- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`

Acceptance:

- activation and renewal modals only show market-allowed methods
- backend rejects disabled methods even if UI is bypassed

### BILL-602: Build wallet auto-renew policy engine

Primary write scope:

- `app/Billing/Support/WalletAutoRenewPolicy.php` new
- `app/Services/RenewalService.php`

Acceptance:

- policy can decide whether to debit wallet, send fallback, or escalate

### BILL-603: Implement renewal runner for wallet auto-charge

Primary write scope:

- `app/Console/Commands/RunRenewals.php`
- `app/Services/RenewalService.php`
- `app/Services/WalletCheckoutService.php`

Acceptance:

- expiring subscriptions can auto-renew from wallet where allowed
- insufficient balance uses configured fallback

### BILL-604: Surface wallet renewal state in CRM

Primary write scope:

- `resources/js/pages/ClientDetail.jsx`
- `resources/js/pages/Deals.jsx`
- `resources/js/pages/Payments.jsx`

Acceptance:

- operators can see auto-renew enabled, attempted, succeeded, failed, or fallback-sent state

### BILL-605: Expose market billing methods to WordPress-facing APIs

Primary write scope:

- `app/Http/Controllers/API/BillingController.php`
- `app/Http/Controllers/API/PaymentController.php`
- `app/Http/Controllers/API/PlatformController.php`

Acceptance:

- self-service clients only see market-allowed methods
- WordPress-facing responses use explicit versioned payload contracts with parity to the approved bridge schema

### BILL-606: Preserve and migrate WP credential sync behavior

Primary write scope:

- `app/Services/WalletSyncService.php`
- `app/Services/WalletSettingsService.php`

Secondary touch points:

- Billing settings controllers

Acceptance:

- WP credential push and rotation remain safe during Billing model migration
- active-environment push semantics and delayed rotation semantics remain compatible with current WordPress behavior
- config sync remains anchor-client based until a later contract revision explicitly changes it
- sync attempts record success and failure state with retry-safe semantics

### BILL-607: Add WordPress payload parity and version rollout tests

Primary write scope:

- WordPress-facing API and sync tests

Secondary touch points:

- serializers and sync services

Deliverables:

- regression suite covering legacy and versioned WordPress billing payloads

Acceptance:

- new payload versions cannot ship unless parity and compatibility assertions pass
- parity tests cover current `mode`, `last_topup`, `transactions`, anchor-client delivery, and credential push timing semantics
- retry and idempotency behavior for failed sync attempts is covered in tests

### BILL-608: Persist WordPress sync status and retry orchestration

Primary write scope:

- `app/Services/WalletSyncService.php`
- supporting sync persistence or retry support classes

Secondary touch points:

- WordPress-facing billing sync jobs or commands

Deliverables:

- persisted sync-attempt status, retry-safe idempotency keys, and automated retry orchestration for wallet config, balance, and credential sync

Acceptance:

- failed WordPress sync attempts can be retried without duplicate side effects
- last attempted sync and last successful sync are distinguishable

## 12. Phase 7: Shared diagnostics foundation and dual surfaces

Phase goal:

- diagnostics must explain any billing event across all routes and providers
- the product must expose both `Payment Diagnostics` and `Billing Diagnostics` without duplicating business logic

Phase exit gate:

- shared diagnostics backend powers both the Payments drawer and the Billing Settings diagnostics tab
- payment diagnostics show route, provider transaction, fallback, webhook, wallet, and provisioning state
- billing diagnostics show readiness, routing health, webhook health, proxy posture, WP contract health, and recent failures

### BILL-701: Persist routing decisions and execution metadata

Primary write scope:

- `app/Billing/Routing/BillingRouteResolver.php`
- `app/Models/BillingRoutingDecision.php`
- `app/Services/PaymentAttemptService.php`

Acceptance:

- every initiation records route selection and fallback chain

### BILL-702: Build webhook inbox processor

Primary write scope:

- `app/Jobs/ProcessBillingWebhookEvent.php` new
- `app/Http/Controllers/API/BillingController.php`

Secondary touch points:

- provider adapters
- webhook models

Acceptance:

- raw webhook events are stored, verified, processed, and status-marked
- webhook processing uses monotonic state progression so delayed lower-order events cannot regress newer provider-transaction state

### BILL-703: Build shared diagnostics backend and query model

Primary write scope:

- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `app/Billing/Diagnostics/BillingDiagnosticsAssembler.php` new
- `app/Billing/Diagnostics/` new query and presenter classes if needed

Secondary touch points:

- provider transaction model

Acceptance:

- one shared diagnostics backend supports both payment-scoped and market/provider-scoped reads
- payment diagnostics payload includes provider profile, route mode, fallback, provider transaction, webhook state, wallet ledger, and provisioning state
- billing diagnostics payload includes readiness, route health, fallback posture, webhook posture, proxy posture, WP contract health, and recent failure trends
- diagnostics can return redacted and `legacy_composed` payloads when full structured lineage is unavailable or access must be limited

### BILL-704: Redesign diagnostics UI in Payments workspace

Primary write scope:

- `resources/js/pages/Payments.jsx`
- `resources/js/components/payments/` new if needed

Acceptance:

- the existing Payments drawer remains the per-payment diagnostics surface
- diagnostics UI supports routing, provider transaction, webhooks, wallet, provisioning, and recommended next-action sections
- operators can see which route and provider profile were used for the payment
- role-aware sections, unavailable states, and degraded diagnostics messaging are explicit in the UI

### BILL-705: Add Billing Diagnostics tab inside Settings workspace

Primary write scope:

- `resources/js/pages/Settings.jsx`
- `resources/js/components/settings/billing/` new if needed
- Billing settings controllers

Secondary touch points:

- diagnostics assembler

Acceptance:

- `Settings > Billing > Diagnostics` exists as a separate admin/system surface from the Payments drawer
- admins can inspect provider profile readiness, route simulator output, webhook health, proxy posture, WP contract health, and recent billing failures
- admins can drill from billing diagnostics into affected payments when needed
- diagnostics access is permission-aware and honors redaction rules for secrets, payloads, and restricted provider details

### BILL-706: Add provider status and sandbox compatibility matrix to Billing Diagnostics

Primary write scope:

- `app/Http/Controllers/CRM/SettingsController.php`
- diagnostics assembler
- Billing settings UI components

Acceptance:

- provider status checks and sandbox tooling are capability-driven instead of Paystack/Pesapal allowlists
- billing diagnostics shows profile readiness, environment posture, and capability-aware health checks per provider family

### BILL-707: Implement diagnostics access, redaction, and policy presenters

Primary write scope:

- diagnostics presenter layer
- relevant auth/policy helpers

Secondary touch points:

- Payments and Billing Diagnostics controllers

Deliverables:

- consistent policy-driven redaction of secrets, payloads, and provider-sensitive diagnostics data

Acceptance:

- diagnostics output differs safely by role and scope without changing the underlying diagnostics engine

### BILL-708: Implement Billing Diagnostics scoping and route-simulator authorization

Primary write scope:

- Billing diagnostics controllers
- diagnostics assembler/presenters

Secondary touch points:

- Billing Settings UI

Deliverables:

- market/provider-scoped diagnostics queries plus policy-gated route simulation and drill-through

Acceptance:

- route simulator and cross-market drill-through are restricted to authorized admin scopes only

### BILL-709: Preserve Payments queue actions during diagnostics refactor

Primary write scope:

- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `resources/js/pages/Payments.jsx`

Secondary touch points:

- diagnostics assembler

Deliverables:

- existing queue actions and operator recommendation flows remain intact while diagnostics become richer

Acceptance:

- Payments remains an action hub, not just a reporting screen, throughout the diagnostics refactor
- the migration explicitly preserves `auto-match`, `manual match`, `review-state`, `manual close`, `retry STK`, `send payment link`, `provider check`, and `create subscription`
- equivalent endpoint semantics, response categories, and permission behavior remain available during migration

## 13. Phase 8: Rollout, cleanup, and final hardening

Phase goal:

- cut over safely, observe the system, and remove hard-coded remnants only after confidence is earned

Phase exit gate:

- markets are cut over intentionally
- billing-system live-read flip is validated separately from market routing
- compatibility branches are minimized
- full billing suite passes
- mixed-population cutover and rollback scenarios are validated

### BILL-801: Build end-to-end billing smoke suite

Primary write scope:

- browser tests
- selected feature tests

Acceptance:

- smoke suite covers compatibility and new-model paths across settings, routing, activation, wallet funding, auto-renew, diagnostics, and WP-facing method visibility
- browser coverage includes admin, in-scope sub-admin, out-of-scope sub-admin, sales, degraded diagnostics, and wallet-renewal-fallback scenarios

### BILL-802: Market-by-market rollout checklist and observability

Primary write scope:

- `docs/` rollout checklist
- observability config if needed

Acceptance:

- each market has explicit cutover checklist and rollback path
- checklist includes a separate global billing-system live-read flip and mixed-population validation steps

### BILL-803: Remove hard-coded billing allowlists

Primary write scope:

- `app/Services/WalletSettingsService.php`
- `app/Services/BillingModeService.php`
- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `app/Http/Controllers/API/PaymentController.php`

Acceptance:

- hard-coded provider lists and string allowlists are removed or isolated behind compatibility shims only

### BILL-804: Archive legacy compatibility branches

Primary write scope:

- `app/Services/HostedCheckoutService.php`
- `app/Services/BillingGatewayService.php`
- `app/Services/LegacyStkService.php`

Acceptance:

- deferred Paystack and other legacy paths remain isolated, not mixed into active routing design

### BILL-805: Final cutover and precedence flip

Primary write scope:

- billing feature flags
- billing configuration readers

Acceptance:

- runtime reads new billing model first
- legacy projection becomes fallback only
- rollback path remains documented
- shadow-read results are clean enough by market and billing surface before precedence flips
- billing-system live-read precedence is flipped only after its own global shadow-read validation

### BILL-806: Store shadow-read route diffs and cutover validation results

Primary write scope:

- billing configuration readers
- observability or diff-log support

Secondary touch points:

- rollout tooling

Deliverables:

- persisted comparison results between legacy and new-model decisions during shadow-read phases

Acceptance:

- cutover readiness can be evaluated with stored diff evidence by market and billing surface
- stored validation covers legacy-initiated and new-model-initiated payments across cutover and rollback scenarios

### BILL-807: Implement market-scoped and surface-scoped kill switches

Primary write scope:

- feature flags/config
- billing readers and runtime gates

Secondary touch points:

- rollout docs

Deliverables:

- kill switches for market and billing-surface rollback

Acceptance:

- rollback can be scoped without disabling the entire billing program
- rollback controls include the CRM-global billing-system live-read flip in addition to market and billing-surface scopes

## 14. Suggested safe sequencing

1. `BILL-004` to `BILL-020`
2. `BILL-001` to `BILL-003`
3. `BILL-009` and `BILL-010`
4. `BILL-101` to `BILL-104`
5. `BILL-201` to `BILL-215`
6. `BILL-301` to `BILL-311`
7. `BILL-401` to `BILL-415`
8. `BILL-501` to `BILL-508`
9. `BILL-601` to `BILL-608`
10. `BILL-701` to `BILL-709`
11. `BILL-801` to `BILL-807`

## 15. Parallel opportunities

- `BILL-004` to `BILL-020` can be reviewed in parallel where independent, but the whole Phase 0A contract must be approved together
- `BILL-009` and `BILL-010` can run in parallel after Phase 0A
- `BILL-209` to `BILL-215` can be split across backend owners once the Phase 0A model contracts are approved
- provider adapter tickets `BILL-502` to `BILL-507` can run in parallel after model and runtime prerequisites are complete
- diagnostics backend and diagnostics UI can progress together after provider transaction and webhook inbox work is in place
- WordPress contract work can run alongside renewal policy work after runtime contracts are stable

## 16. Recommended first tranche

If the team wants the smallest safe first implementation set, start here:

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
- `BILL-001`
- `BILL-002`
- `BILL-009`
- `BILL-010`
- `BILL-101`
- `BILL-103`
- `BILL-017`
- `BILL-018`
- `BILL-019`
- `BILL-020`
- `BILL-209`
- `BILL-211`
- `BILL-205`
- `BILL-206`

Outcome of first tranche:

- the model is hardened
- UI seams exist
- automation exists
- billing system settings and execution snapshot contracts exist
- provider transactions and webhook inbox shape are defined in code
- the team can start the real refactor without building on unsafe assumptions

## 17. Definition of done

The plan is done only when:

- admins can configure providers, profiles, routing, wallet rules, and subscription rules without hard-coded assumptions
- markets can use multiple providers with explicit fallback rules
- wallet funding, wallet adjustments, and wallet-paid subscriptions are separate concepts in code and UI
- wallet auto-renew works by policy
- WordPress and CRM show market-allowed methods only
- diagnostics explain route, provider transaction, webhook, wallet, and provisioning state
- sandbox and live flows remain safely distinct
- deferred providers stay isolated from the active provider architecture
- the full billing suite passes
