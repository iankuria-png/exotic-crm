# Payment Billing Plan Re-Audit

Date: 2026-04-03
Project: Exotic CRM
Scope: Re-audit of the amended billing planning docs against the current codebase before implementation

Reviewed docs:

- `docs/payment-architecture-implementation-plan-2026-04-03.md`
- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `docs/payment-billing-implementation-backlog-2026-04-03.md`

Reviewed code areas:

- `app/Services/WalletSettingsService.php`
- `app/Services/BillingModeService.php`
- `app/Services/BillingGatewayService.php`
- `app/Services/PaymentLinkService.php`
- `app/Services/PaymentCompletionService.php`
- `app/Services/WalletSyncService.php`
- `app/Http/Controllers/API/BillingController.php`
- `app/Http/Controllers/API/PaymentController.php`
- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `app/Http/Controllers/CRM/SettingsController.php`
- `resources/js/pages/Settings.jsx`
- `resources/js/pages/Payments.jsx`
- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`
- related feature tests

## Outcome

The amended plan is materially stronger than the first version.

The architecture direction is still correct.

The plan is not fully execution-safe yet.

Recommendation: `conditional no-go` until the critical and high findings below are folded back into the spec and backlog.

Safe boundary today:

- `go` for planning hardening only
- `conditional go` for Phase `0A` and `0B` once the remaining planning gaps are added to the docs
- `no-go` for runtime refactor, provider adapters, or Billing workspace cutover as the plan stands right now

## Scores

- Identification quality: `8/10`
- Execution readiness: `5/10`
- UI/UX readiness: `6/10`
- Regression-control readiness: `5/10`
- Regression risk if executed as-is: `medium-high`

## Findings

### Critical 1: there is still no canonical internal state model across provider, payment, wallet, and provisioning outcomes

The amended plan adds provider transactions, but it still does not define the canonical state map between:

- upstream provider status
- `payments.status`
- provisioning state
- wallet funding completion
- wallet auto-renew outcome

Evidence in code:

- `app/Services/PaymentCompletionService.php`
- `app/Console/Commands/ReconcilePendingPayments.php`

Evidence in docs:

- the spec defines provider transactions and diagnostics surfaces
- the backlog refactors completion and diagnostics
- neither defines a single state-transition contract that all adapters and reconcilers must obey

Why this matters:

- this is the exact place where provider-specific branching tends to come back
- wallet funding, subscription provisioning, and auto-renew can drift into different meanings of “completed”

Required amendment:

- add a canonical state map and transition table
- define which states are authoritative for provider transaction, payment intent, wallet funding, and provisioning
- add acceptance criteria that every provider adapter maps into that contract

### Critical 2: there is still no first-class model or ticket for billing system settings

The amended plan improved provider, routing, wallet-rule, and diagnostics modeling, but it still does not create a first-class home for the existing wallet system configuration that the runtime already depends on.

Current code reads system-level state through `BillingModeService::walletContext()` and uses it for billing domains, redirect timing, and environment behavior. The current system config also carries branding, SMTP, PINs, discount config, and polling defaults.

Evidence in code:

- `app/Services/BillingModeService.php`
- `app/Services/WalletSettingsService.php`
- `app/Http/Controllers/API/BillingController.php`

Evidence in docs:

- the spec still lists legacy `IntegrationSetting` wallet system config as an authority
- the new data model has wallet rules and subscription rules, but no system-settings table
- the backlog has a wallet tab, but no explicit migration/model/workspace ticket for system config

Why this matters:

- dual-write cannot be complete without a destination for system config
- callback domains, redirect UX, SMTP tests, PINs, discount rules, and poll timing can regress even if provider routing is correct

Required amendment:

- add a first-class billing system settings model and migration
- add a Billing System tab or equivalent workspace ownership
- add explicit migration and projection coverage for `IntegrationSetting` wallet system config

### Critical 3: proxy-session state is still under-modeled even though proxy flows remain a core product path

The revised diagnostics spec expects `Proxy Lifecycle`, but the new data model still has no first-class proxy-session entity. Current runtime stores proxy-token and proxy-session lifecycle state inside `payment_data.link_proxy`.

Current code depends on:

- token hash and expiry
- provider config alias
- environment
- redirect URL
- provider reference
- initialized/opened timestamps
- open count

Evidence in code:

- `app/Services/PaymentLinkService.php`
- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `resources/js/pages/Payments.jsx`

Evidence in docs:

- `docs/payment-billing-decoupling-spec-2026-04-03.md` requires proxy lifecycle in diagnostics
- the data model still only adds provider transactions, webhook events, and routing decisions
- `BILL-401` scopes `PaymentLinkService` refactor but no ticket introduces a proxy-session model or migration strategy

Why this matters:

- high-risk-market proxy routing is one of the central reasons for the redesign
- without first-class proxy-session storage, the plan will likely keep leaking state back into `payment_data`
- diagnostics and replay behavior for existing proxy links will remain brittle

Required amendment:

- add a first-class proxy-session model or explicitly keep `payment_data.link_proxy` as a supported compatibility shape with a migration plan
- add a backlog ticket for proxy-session storage and historical continuity

### Critical 4: the plan still does not make in-flight payment execution snapshots explicit

The amended plan now stores routing decisions, but it does not explicitly state that a payment must remain pinned to the chosen provider profile, environment, proxy mode, and execution contract from initiation until completion.

Current code already behaves this way by persisting provider identity and environment on the payment itself, and by using those persisted values during verification and callbacks.

Evidence in code:

- `app/Services/PaymentLinkService.php`
- `app/Services/BillingGatewayService.php`
- `app/Http/Controllers/API/BillingController.php`
- `app/Http/Controllers/API/PaymentController.php`

Evidence in docs:

- the spec adds `billing_routing_decisions`
- the backlog adds `BILL-701`
- neither the spec nor backlog explicitly defines immutability rules for in-flight payments after market routing changes

Why this matters:

- admins will be changing routes and fallbacks per market
- a pending payment must not silently start using new config because the market binding changed after initiation
- callback verification and provider reconciliation can break if runtime re-resolves active routing instead of reading the frozen execution snapshot

Required amendment:

- define a mandatory execution snapshot contract on payment initiation
- require callbacks, reconciliation, and diagnostics to read the persisted execution snapshot first, not current market routing

### High 5: retry, fallback, and attempt correlation are still under-specified

The amended plan now has provider transactions, routing decisions, and diagnostics sections for provider attempts, but it still does not define:

- whether retries create new provider transactions or new attempts against the same transaction
- how fallback attempts are represented
- whether `payment_attempts` remains authoritative, derivative, or compatibility-only
- how diagnostics correlate attempts to provider transactions and routing decisions

Evidence in code:

- `app/Services/PaymentAttemptService.php`
- `app/Http/Controllers/CRM/PaymentQueueController.php`

Why this matters:

- diagnostics quality depends on this correlation
- fallback and retry semantics are a major source of operational confusion

Required amendment:

- define attempt and retry lifecycle explicitly
- define correlation keys between payment attempts, provider transactions, and routing decisions
- add a backlog ticket for this contract before diagnostics build-out

### High 6: reconciliation and status polling are still not planned strongly enough

The backlog hardens webhook intake, but it still does not promote reconciliation into a first-class capability even though production already depends on scheduled reconciliation.

Evidence in code:

- `app/Console/Kernel.php`
- `app/Console/Commands/ReconcilePendingPayments.php`

Why this matters:

- callbacks are never perfectly reliable
- crypto and mobile-money flows often need active polling or stale-payment reconciliation

Required amendment:

- add explicit reconciliation policy and runner tickets
- define provider-level support for polling, stale thresholds, and sandbox behavior

### High 7: FX and multi-currency migration behavior are still outside the contract

The amended plan now tracks requested and settled currency, but it still does not define:

- FX rate source
- rate provenance
- rounding rules
- how current self-checkout FX override behavior migrates
- which FX data becomes part of the frozen execution snapshot

Evidence in code:

- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Http/Controllers/API/PaymentController.php`

Why this matters:

- FX override is already a live feature
- amount drift or rounding inconsistency will surface as real billing defects

Required amendment:

- add FX policy to the spec
- add migration and testing requirements for current self-checkout FX behavior

### High 8: the WordPress contract is acknowledged, but still not specified tightly enough for migration safety

The backlog now includes WordPress-facing tickets, but the contract is still too broad compared to what the code actually does today.

Current behavior includes:

- anchor-client based config sync
- balance sync payloads
- environment-sensitive active credential push
- credential rotation that may push immediately or store for later
- payload-shape expectations tested in feature tests

Evidence in code:

- `app/Services/WalletSyncService.php`
- `tests/Feature/WalletSyncPhaseSixTest.php`

Evidence in docs:

- `docs/payment-billing-decoupling-spec-2026-04-03.md`
- `BILL-605`
- `BILL-606`

Why this matters:

- WordPress is not just a consumer of “allowed methods”
- it relies on specific payload shape, environment handling, and timing semantics
- the current test suite already encodes provider visibility and payload assumptions that the new plan does not yet spell out

Required amendment:

- add an explicit WP payload contract section
- define versioning, projection, and backward-compatibility rules for balance sync, config sync, and credential sync
- add acceptance criteria for payload parity, not just “safe” behavior

### High 9: permissions and access control are still incomplete for real billing operations

The amended plan improves role modeling, but it still omits the live `marketing` role and does not fully map current operations to future permissions.

Evidence in code:

- `app/Services/MarketAuthorizationService.php`
- `app/Http/Controllers/CRM/ClientWalletController.php`
- `app/Http/Controllers/CRM/SettingsController.php`

Why this matters:

- wallet adjustments, provider tests, diagnostics access, and WP credential rotation all have different risk profiles
- the Billing workspace cannot be considered safe without operation-level permissions and redaction rules

Required amendment:

- extend the role model to include current roles
- define action-level permissions for settings edits, diagnostics, wallet operations, provider tests, and WP sync
- define redaction rules per role

### High 10: the Payments queue action model is still under-scoped in the backlog

The diagnostics split is now clearer, but the plan still treats the `Payments` workspace mostly as a diagnostics surface. In reality it is also an operator workflow hub for auto-match, manual match, review-state transitions, manual close, STK retry, send-link, and create-subscription flows.

Evidence in code:

- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `resources/js/pages/Payments.jsx`

Evidence in docs:

- Phase 7 covers diagnostics surfaces
- no backlog ticket explicitly owns preserving or migrating the queue action model and its recommendation engine

Why this matters:

- the drawer is only part of the operational workflow
- refactoring diagnostics without explicitly protecting queue actions is a regression trap
- operator experience can degrade even if provider routing and adapters are correct

Required amendment:

- add a ticket for Payments workspace action preservation and migration
- include recommendation-engine parity, review-state flows, manual-close flows, and create-subscription flows

### High 11: manual activation, deactivation, and manual payment-status recovery are still outside the explicit migration plan

The amended plan improves market activation methods, but it still does not explicitly absorb or retire the older manual operational flows in `PaymentController`.

Current code still includes:

- manual activation with free-trial handling
- manual deactivation
- manual payment-status recovery paths

Evidence in code:

- `app/Http/Controllers/API/PaymentController.php`

Why this matters:

- those endpoints create real payment and activation side effects
- they overlap with the new subscription rules and wallet/renewal policy model
- execution can drift if the new system is built while these legacy paths remain ungoverned

Required amendment:

- add an explicit decision: migrate, wrap, or retire these endpoints
- add acceptance tests proving which behavior survives and which is deprecated

### High 12: the operator-flow migration is still under-specified

The plan now says routing should choose the provider, but today operators explicitly choose payment-link providers in CRM flows and tests assert that behavior.

Evidence in code:

- `resources/js/pages/Deals.jsx`
- `resources/js/pages/ClientDetail.jsx`
- `app/Http/Controllers/CRM/DealController.php`
- `tests/Feature/DealControllerTest.php`

Why this matters:

- removing provider choice without a migration UX will feel like regression
- emergency override and contingency handling still need a clear operator/admin path

Required amendment:

- define the migration UX from operator-selected provider to policy-selected provider
- define whether override moves behind an advanced drawer, admin-only mode, or diagnostics-only flow

### Medium-High 13: `Settings.jsx` decomposition is still under-scoped

The plan correctly identifies the monolith, but shell extraction alone is not enough for the size of the UI surface being changed.

Evidence in code:

- `resources/js/pages/Settings.jsx` is `7,839` lines
- the page still centralizes broad settings state and queries

Why this matters:

- the Billing workspace is one of the biggest UI additions in the plan
- without query and state-boundary decomposition, the UI rollout remains a regression magnet

Required amendment:

- make API/query decomposition and isolated state boundaries explicit in `BILL-009`
- define which current settings branches must be untouched while Billing is extracted

### Medium-High 14: diagnostics governance is still too vague

The amended plan now models raw webhook bodies, headers, and richer diagnostics data, but it still does not define:

- retention policy
- redaction policy
- role-scoped visibility
- export rules
- drill-down permissions from Billing Diagnostics into payment-level views

Evidence in code:

- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `app/Services/WalletSettingsService.php`

Why this matters:

- the new Billing Diagnostics surface could easily become a data-leak vector
- readiness and health surfaces often expose secret-adjacent information if not designed carefully

Required amendment:

- add diagnostics retention/redaction/access policy to the spec
- add backlog tickets for masking and permission enforcement

### Medium-High 15: there is still no explicit plan for historical payment and diagnostics continuity

The plan now migrates billing configuration, but it still does not define what happens to existing payment rows, historical proxy sessions, payment attempts, and diagnostics history.

Current diagnostics and browser context rely on:

- `payment_attempts`
- `raw_payload`
- `payment_data.link_proxy`
- existing reconciliation state

Evidence in code:

- `resources/js/pages/Payments.jsx`
- `app/Http/Controllers/CRM/PaymentQueueController.php`
- `tests/Feature/PaymentDiagnosticsTest.php`

Evidence in docs:

- the backlog has a billing-config migration command
- there is no explicit backfill or compatibility-read ticket for historical payment execution data

Why this matters:

- the plan will otherwise improve diagnostics only for newly created payments
- historical and in-flight payments can become second-class citizens during rollout

Required amendment:

- add a compatibility-read or backfill plan for historical payment diagnostics data
- make clear whether provider transactions are backfilled, lazily projected, or optional for legacy rows

### Medium 16: the QA plan is better, but still too light for the regression surface

There is still no checked-in frontend test runner or browser suite today, and the current browser-automation ticket only requires one local happy path.

Evidence in code:

- `package.json`
- `resources/js/pages/Payments.jsx`
- `resources/js/pages/ClientDetail.jsx`

Why this matters:

- the plan touches Settings, Payments diagnostics, activation modals, wallet flows, and WP-facing billing behavior
- one local happy path is not enough for safe rollout

Required amendment:

- define minimum browser coverage for Settings Billing, Payments queue actions, diagnostics surfaces, activation flows, and wallet flows
- explicitly separate compatibility tests from new-model tests

### Medium 17: wallet UX separation is conceptually fixed, but not yet operationally designed

The plan now correctly separates wallet funding from admin adjustment, but the live UI still presents support-side credit as “Manual top-up,” and the migration does not yet define the exact copy and state changes that resolve that confusion.

Evidence in code:

- `resources/js/pages/ClientDetail.jsx`
- `app/Http/Controllers/CRM/ClientWalletController.php`

Why this matters:

- operator mental models around money movement are fragile
- wallet UX regressions can happen even when backend concepts are cleaner

Required amendment:

- define exact terminology and UI copy for wallet adjustment versus customer-funded wallet payment
- add that copy migration into the backlog, not only the data-model split

## Open questions that should be answered before execution

1. What is the canonical state map between provider transaction state, `payments.status`, wallet funding outcome, provisioning outcome, and auto-renew outcome?
2. What is the first-class destination for wallet system config now stored in `IntegrationSetting`?
3. Is proxy lifecycle getting its own model, or is `payment_data.link_proxy` being retained as an intentional compatibility contract?
4. What exact execution fields are frozen on payment creation and guaranteed immutable for in-flight payments?
5. What is the retry and fallback contract between routing decisions, provider transactions, and `payment_attempts`?
6. What reconciliation and polling policy is required per provider family?
7. What exact FX contract must remain stable during migration?
8. What exact WordPress payload contract must remain stable during migration?
9. Which roles can view/edit which billing surfaces and diagnostics data, including `marketing`?
10. How does the operator UX migrate from choosing providers to choosing only methods?
11. Are manual activation/deactivation and manual payment recovery being migrated, wrapped, or retired?
12. Will existing payments get backfilled into provider-transaction and diagnostics structures, or will runtime support mixed legacy/new reads indefinitely?

## Re-audit conclusion

The amended plan is now strategically sound.

It is still missing several operational contracts that the current CRM already relies on heavily:

- canonical state mapping across provider/payment/wallet/provisioning
- billing system settings
- proxy-session continuity
- in-flight execution pinning
- retry and fallback correlation
- reconciliation and polling policy
- FX and multi-currency contract
- WordPress payload compatibility
- permissions and diagnostics redaction
- operator-flow migration
- Settings decomposition depth
- Payments queue action preservation
- legacy manual operational flows
- historical diagnostics continuity

If those are corrected, the plan is close to execution-safe.
