# Payment Architecture Implementation Plan

Date: 2026-04-03
Project: Exotic CRM
Status: Revised after pre-implementation audit

This note maps the billing architecture proposal onto the current Laravel CRM and defines the safest execution path after auditing the plan against the real codebase.

Execution kickoff details live in:

- `docs/payment-billing-execution-readiness-2026-04-03.md`
- `docs/payment-billing-agent-execution-matrix-2026-04-03.md`

## Executive summary

The architectural direction is still correct:

- decouple wallet logic
- decouple provider execution
- decouple payment-link routing
- make market rules explicit
- move provider logic behind adapters

But the original version was not safe to execute yet.

The revised plan adds the missing pieces that must exist before implementation starts:

- a first-class provider transaction model
- explicit legacy payment-row compatibility semantics
- a billing system settings layer
- a proxy-session layer
- an immutable execution snapshot contract
- a canonical internal state model and reducer
- a richer provider capability taxonomy
- source-of-truth and dual-write rules
- divergence repair and rollback reprojection rules
- explicit webhook inbox requirements
- monotonic webhook ordering and out-of-order event safety
- explicit retry, fallback, and reconciliation contracts
- explicit FX and currency policy
- explicit WordPress payload versioning and parity rules
- explicit WordPress sync retry and idempotency rules
- exact payment-link URL compatibility rules
- an explicit mixed-population cutover matrix
- explicit preservation of Payments workspace operational actions
- explicit permissions, redaction, and staged operator UX migration
- explicit Kenya provider semantics
- explicit preservation of `provider_environment` and `test_mode`
- frontend seam extraction and UI automation before the big Settings rollout

## What exists today

- Wallet provider keys are fixed to `pesapal`, `paystack`, and `mpesa_stk` in `app/Services/WalletSettingsService.php`.
- Wallet initiation and settlement are hard-coded in `app/Services/BillingGatewayService.php`.
- Hosted checkout only exists for Paystack and Pesapal in `app/Services/HostedCheckoutService.php`.
- CRM proxy payment links only support Paystack and Pesapal in `app/Http/Controllers/CRM/PaymentLinkProxyController.php`.
- Payment-link settings validation only allows `wallet_provider_key` of `paystack` or `pesapal` in `app/Http/Controllers/CRM/SettingsController.php`.
- Self-checkout still assumes Paystack/Pesapal-shaped hosted checkout in `app/Http/Controllers/API/PaymentController.php`.
- Renewal and activation flows still hard-code `manual`, `stk`, `link`, and `free_trial` in CRM screens and controllers.
- Diagnostics and live provider checks are still limited to Paystack/Pesapal branches.
- M-Pesa/STK is split between the new wallet path in `app/Services/BillingGatewayService.php` and the older path in `app/Services/LegacyStkService.php`.
- WordPress-facing wallet sync and credentials are part of the runtime contract already and cannot be treated as optional side concerns.
- Billing runtime still depends on system settings such as billing domains, branding, timing, discount posture, and PIN-related approval behavior.
- Proxy lifecycle state is still effectively embedded in ad hoc fields like `payment_data.link_proxy`.
- The Payments workspace is an operational action hub, not just a historical ledger or diagnostics surface.

## Audit-driven amendments

These are now non-negotiable planning constraints:

### 1. Add a provider transaction layer

`payments` cannot carry all upstream state alone.

We need a structured provider transaction record that can hold:

- upstream transaction or invoice id
- upstream checkout id or session id
- external status
- requested amount and currency
- settled amount and currency
- fees and net amount
- settlement state
- expires at
- confirmation depth
- reversal or refund metadata
- retry metadata

This is required for:

- NOWPayments
- async mobile-money providers
- multi-step redirect flows
- better diagnostics and reconciliation

### 2. Expand capability modeling beyond broad surfaces

The registry must model more than `wallet`, `STK`, and `hosted checkout`.

It must also capture:

- billing surface
- rail
- transport
- network
- operation type
- settlement model
- supported currencies
- market restrictions
- merchant-account constraints

Without that, provider-specific rules will leak back into runtime code.

### 3. Define source of truth and migration precedence

The revised implementation must explicitly define:

- what remains authoritative in legacy JSON
- what becomes authoritative in new billing tables
- what is dual-written during migration
- what is read from WordPress-facing synced config
- how precedence is resolved when the same concept exists in more than one place

Until that is defined, the new Billing workspace must not become a live-write trap.

The contract must also define:

- transactional write ordering for dual-write saves
- divergence detection and alerting
- rollback-safe reprojection rules

### 4. Separate wallet concepts

The product currently uses “wallet top-up” to mean two different things:

- admin ledger credit
- customer-funded wallet payment

The revised model must separate:

- `wallet_adjustment`
- `wallet_funding_payment`
- `wallet_subscription_payment`

### 5. Make webhook processing durable

The webhook inbox must store:

- raw body
- normalized payload
- headers
- signature input and verification result
- provider delivery id or event id
- dedupe key
- retry and error counters
- linked provider transaction id
- linked payment id if resolved

Callbacks should be stored first and processed asynchronously.

### 6. Resolve Kenya semantics before provider implementation

The revised plan treats:

- `Daraja` as a provider type
- `KopoKopo` as a provider type
- `django_proxy` as a transitional transport mode, not a provider
- `mpesa_stk` as a legacy compatibility alias only

Wallet funding and subscription STK are allowed to resolve to different Kenya routes.

### 7. Preserve existing sandbox semantics

`provider_environment` and `payment_data.test_mode` are already business logic, not decoration.

They affect:

- side-effect suppression
- provisioning
- reporting
- diagnostics
- completion-page behavior

The refactor must preserve those semantics from day one.

### 8. Extract UI seams before the Billing workspace rollout

`Settings.jsx` is currently too monolithic to treat as a safe extension seam.

Before the large Billing workspace lands, the frontend needs:

- a dedicated Billing workspace shell
- extracted billing components
- browser automation for the most important flows

### 9. Add a billing system settings layer

The plan needs a first-class model for settings that are global to billing behavior, including:

- billing domains and callback bases
- branding and completion-page posture
- redirect timing and polling behavior
- SMTP-dependent billing notifications
- discount posture and approval/PIN posture that affects billing runtime

This must not stay hidden inside unrelated legacy settings blobs.
For this migration, the layer should be treated as CRM-global only. Narrower scopes are explicitly deferred.

### 10. Model proxy-session lifecycle explicitly

High-risk proxy flows require first-class lifecycle storage for:

- proxy initiation
- session or token identifiers
- callback posture
- expiry
- final outcome
- correlation back to the payment intent and provider transaction

### 11. Freeze execution contract at initiation

Once a payment starts, the system must preserve an immutable execution snapshot that captures:

- selected route
- provider profile
- proxy or direct mode
- execution family
- FX contract
- key risk or environment posture

Callbacks and reconciliation must read that snapshot first instead of consulting current admin config.
`billing_routing_decisions.snapshot_json` should be the canonical persistence owner, with any other snapshot fields treated as denormalized read helpers only.

### 11A. Preserve legacy payment-row compatibility semantics

The revised plan must define how `payments.provider_key` and `payments.transaction_reference` are projected from multi-transaction lineage during migration and rollback.

### 11B. Define payment-link URL compatibility

The current payment-link fallback order must be treated as a contract:

1. provider `url`
2. provider `base_url` + `path`
3. `wp_api_url` origin
4. `domain`

Proxy-hosted providers should continue to return no direct static URL until proxy flow creates the redirect.

### 12. Preserve Payments workspace operations

The Payments workspace already supports real operator actions.

The refactor must preserve:

- queue actions
- recommendation and matching flows
- operational review behavior

An explicit action-by-action disposition matrix should be maintained for:

- auto-match
- manual match
- review-state changes
- manual close
- retry STK
- send payment link
- provider check
- create subscription

Diagnostics can expand, but Payments cannot quietly degrade into a read-only reporting page.

### 13. Treat WordPress payloads as versioned contracts

The WordPress bridge should be treated as a formal compatibility surface with:

- versioned payloads
- explicit payload families for wallet config, balance, billing methods, and credentials
- field-level contract definitions per payload family
- parity rules
- explicit version-selection rules
- migration-safe serializers
- current delivery semantics such as anchor-client config sync
- active-environment credential semantics
- delayed rotation semantics
- minimum deprecation windows before older payloads can be removed

### 13A. Define mixed-population cutover behavior

The plan must explicitly support:

- legacy-initiated payments finishing after cutover
- new-model-initiated payments finishing after rollback
- diagnostics and reconciliation across both populations

### 14. Add permissions, redaction, and staged operator UX rules

The current role language in the plan must match real codebase realities.

The revised plan must explicitly cover:

- `admin`
- `sub_admin`
- `sales`
- `marketing`

It must also define what each role can view, edit, override, or diagnose.

## Recommended target architecture

### 1. Payment intent layer

Keep `payments` as the CRM-facing payment intent and provisioning anchor.

It should continue to own:

- business purpose
- market
- client or deal linkage
- high-level status
- operational identifiers used by CRM

### 2. Provider transaction layer

Add a separate normalized upstream-transaction layer for provider-specific lifecycle state.

This prevents:

- repeated overloading of `transaction_reference`
- ad hoc JSON writes
- provider-specific meaning drift across services

### 3. Wallet ledger layer

Wallet ledger logic should own:

- credit and debit integrity
- compensation
- balance history
- idempotency

It should not own provider initiation or routing.

### 3A. Billing system settings layer

Add a dedicated billing system settings layer for:

- billing domains
- branding
- completion behavior
- timing and polling settings
- WP bridge system settings
- approval and PIN posture that influences billing flows

### 4. Provider registry and schema registry

The provider registry should own:

- provider type metadata
- capability taxonomy
- supported surfaces and constraints
- credential schema
- webhook strategy
- status-query strategy

### 5. Billing routing engine

The routing engine should resolve by:

- market or platform
- billing surface
- rail
- environment
- risk posture
- operator or self-service context
- fallback chain

Operator-facing provider choice should not be the default path.

Default policy:

- operator chooses method
- routing engine chooses provider
- provider override is admin-only or emergency-only

### 5A. Proxy session layer

Proxy-mode payments need their own lifecycle model and service boundary.

That layer should own:

- proxy token or session state
- proxy callback posture
- proxy expiry
- proxy finalization status
- compatibility bridging to existing proxy metadata

### 6. Orchestrators

The revised architecture should separate orchestration by intent:

- wallet funding orchestrator
- subscription initiation orchestrator
- renewal and auto-renew orchestrator
- diagnostics assembler

It should also include:

- reconciliation and status-query orchestrator
- WordPress projection layer
- Payments operations compatibility layer

### 6A. Execution snapshot contract

All initiation paths must persist a frozen execution contract that later readers can trust.

### 6B. Canonical state reducer

The billing system needs a shared state reducer that reconciles:

- payment intent state
- provider transaction state
- settlement state
- wallet funding state
- provisioning state
- auto-renew state

### 7. Webhook inbox and async processing

Every inbound provider event should:

1. land in webhook inbox storage
2. be verified
3. be deduplicated
4. resolve or attach provider transaction state
5. update payment intent state
6. emit diagnostics and timeline signals

### 7A. FX and settlement policy layer

The architecture must include a clear policy for:

- quote capture
- locked vs indicative rates
- settlement variance tolerance
- provider-fee handling
- reporting currency vs settled currency

## Provider family decisions

### Active near-term providers

- Daraja
- KopoKopo
- pawaPay
- ElemiTech
- DusuPay
- NOWPayments
- Pesapal for continuity

### Deferred or legacy providers

- Paystack
- PayPal

### Family rules

- Daraja and KopoKopo are separate provider types
- pawaPay, ElemiTech, and DusuPay are mobile-money and hosted-collection adapters
- NOWPayments is crypto-only and must not inherit hosted-card assumptions
- Paystack and PayPal remain isolated from the active target design

## Revised phase model

### Phase 0A: Model hardening

Before implementation starts:

- finalize provider capability taxonomy
- finalize provider transaction model
- finalize legacy payment-row compatibility semantics
- finalize billing system settings authority
- finalize proxy-session lifecycle
- finalize immutable execution snapshot rules
- finalize canonical internal state transitions
- finalize webhook inbox model
- finalize retry, fallback, and reconciliation contract
- finalize FX and currency policy
- finalize WordPress payload parity and versioning contract
- finalize divergence repair and rollback reprojection contract
- finalize payment-link URL compatibility contract
- finalize mixed-population cutover matrix
- finalize permissions, redaction, and staged operator UX rules
- finalize source-of-truth and precedence rules
- finalize Kenya provider semantics
- finalize sandbox/live preservation rules

### Phase 0B: Safety scaffolding

- feature flags
- baseline tests
- browser automation harness
- Billing workspace shell extraction

### Phase 1: Core abstractions

- provider registry
- schema registry
- route resolver
- normalized result DTOs

### Phase 2: Data model and compatibility bridge

- new billing tables
- provider transaction tables
- compatibility projection for legacy payment rows
- billing system settings table
- proxy-session table
- execution snapshot storage
- dual-write and projection services
- legacy migration commands
- WordPress projection compatibility
- shadow-read diff support
- divergence repair and reprojection support

### Phase 3: Billing workspace foundation

- extracted Billing workspace
- Billing System tab
- provider profiles
- routing
- wallet rules
- subscription rules
- permission-aware and redacted states
- per-tab loaders and query contracts

This phase must either dual-write legacy structures or remain read-only until runtime cutover is ready.

### Phase 4: Runtime routing refactor

- payment links
- hosted checkout
- wallet funding
- subscription initiation
- completion normalization
- execution snapshot pinning
- canonical state transitions
- retry and fallback orchestration
- reconciliation and polling
- FX quote locking
- payment-link URL fallback compatibility
- proxy-to-provider transaction binding
- admin-only provider override
- legacy manual-flow disposition

### Phase 5: Provider adapters

- Daraja
- KopoKopo
- pawaPay
- ElemiTech
- DusuPay
- NOWPayments
- Pesapal compatibility

### Phase 6: Renewal and policy enforcement

- market activation methods
- wallet auto-renew policy
- renewal runner
- WordPress-facing method visibility
- WordPress payload parity and version rollout
- WordPress sync retry and idempotency
- current WP delivery semantics preserved until explicitly versioned away

### Phase 7: Unified diagnostics

- routing decisions
- provider transaction visibility
- webhook visibility
- monotonic webhook ordering and out-of-order protection
- wallet and provisioning visibility
- payment-scoped diagnostics drawer in `Payments`
- system and configuration diagnostics tab in `Settings > Billing`
- shared diagnostics backend powering both surfaces
- diagnostics redaction and access policy
- historical diagnostics continuity for legacy payments
- Payments queue action preservation during diagnostics refactor

### Phase 8: Rollout and cleanup

- market-by-market rollout
- billing-system live-read flip
- cutover validation
- shadow-read diff validation
- mixed-population lifecycle validation
- market and billing-surface kill switches
- removal of hard-coded allowlists
- isolation of deferred providers

## Specific implementation constraints for this codebase

### Settings workspace

Do not treat the current Settings page as modular enough already.

Required first:

- extract a Billing shell from `Settings.jsx`
- move billing-specific state and rendering into dedicated components
- define distinct data contracts and query keys per Billing tab
- design explicit `loading`, `empty`, `degraded`, and `forbidden` states
- add automation coverage for settings and both diagnostics surfaces

### Deal and client activation flows

Current CRM screens hard-code methods and expose link-provider choice.

The target behavior should be:

- method visibility comes from market rules
- provider routing comes from policy
- provider override is optional, admin-only, and audited
- operators should see route outcome after initiation instead of choosing provider profiles directly

### Self-checkout

Self-checkout cannot be modeled only as “hosted checkout with another provider.”

It must branch by family:

- hosted redirect family
- push and mobile-collection family
- crypto invoice family

### WordPress-facing behavior

The CRM must expose:

- allowed payment methods per market
- wallet state and wallet auto-renew state
- safe credential sync for the WP bridge

The WP contract must be treated as part of the billing system, not an afterthought.
It must also be versioned and parity-tested.
Current anchor-client config sync, balance payload fields, and credential delivery timing must be preserved until a later coordinated WP revision.

### Payments workspace

The Payments workspace must retain its current operational role during the refactor.

That means:

- preserve queue and review actions
- preserve matching and recommendation flows
- add richer diagnostics without collapsing operator workflows
- preserve action endpoint and response behavior until compatibility wrappers are intentionally retired

### Payment-link behavior

The current payment-link fallback order and proxy-hosted null-direct-URL behavior must be preserved as an explicit compatibility contract during refactor.

## Recommended immediate next step

Do not start adapter implementation yet.

Start with the revised planning tranche:

1. model hardening
2. compatibility and precedence contract
3. billing system settings authority
4. immutable execution snapshot and proxy lifecycle contract
5. divergence repair and rollback reprojection contract
6. payment-link URL compatibility contract
7. mixed-population lifecycle matrix
8. permissions, redaction, and operator UX migration contract
9. Billing workspace seam extraction
10. browser automation setup

Only after that should the actual runtime refactor begin.

## Final recommendation

Recommendation: proceed with the billing vision, but only on the amended plan.

The old version was good enough to justify the direction.
The revised version is what makes the direction implementable with materially lower regression risk.
