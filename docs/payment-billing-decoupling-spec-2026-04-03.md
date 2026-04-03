# Payment Billing Decoupling Spec

Date: 2026-04-03
Project: Exotic CRM
Status: Revised implementation blueprint after pre-execution audit

## 1. Executive answer

Yes, the CRM should decouple:

- wallet ledger logic
- wallet funding logic
- PSP execution logic
- payment-link routing
- subscription activation and renewal policy
- diagnostics and provider status visibility

No, the team should not start implementation from the original version of the plan.

The revised safe approach is:

1. Keep the existing `Settings` route.
2. Add a dedicated `Billing` workspace inside `Settings`.
3. Make that workspace authoritative only when runtime is ready, or dual-write legacy config until cutover.
4. Introduce a first-class provider transaction model before adapter work.

## 2. Non-negotiable planning amendments

These constraints now govern the entire build.

### A. Add a first-class provider transaction model

`payments` remains the CRM payment intent and provisioning anchor.

But the system also needs a provider transaction record for upstream state such as:

- provider transaction id
- provider session or invoice id
- external status
- requested amount and currency
- settled amount and currency
- fee amount and net amount
- settlement status
- invoice or checkout expiry
- confirmation depth or threshold
- refund or reversal status
- retry metadata

Without this, new providers will push critical state back into `payment_data` and `raw_payload`.

### B. Expand provider capability taxonomy

The system cannot model providers only as:

- wallet
- STK
- hosted checkout
- proxy
- webhook
- crypto

The system must also model:

- billing surface
- rail
- transport
- network
- operation type
- settlement model
- supported currencies
- merchant-account scope
- market restrictions

### C. Define source of truth and precedence rules

The revised design must explicitly define:

- legacy JSON sources
- new billing tables
- WordPress-facing synced config
- dual-write period behavior
- read precedence during migration
- cutover criteria

The new Billing workspace must not silently become a write-only control panel.

### D. Separate wallet concepts

The product must stop using one phrase for multiple flows.

Required conceptual split:

- `wallet_adjustment`
- `wallet_funding_payment`
- `wallet_subscription_payment`

### E. Make webhook processing durable

Every webhook must preserve:

- raw body
- normalized payload
- request headers
- provider delivery id or event id
- signature input and verification result
- dedupe key
- processing status
- retry count
- last processing error
- linked provider transaction id
- linked payment id if resolved

### F. Resolve Kenya provider semantics before implementation

The revised model treats:

- `Daraja` as a provider type
- `KopoKopo` as a provider type
- `django_proxy` as a transitional transport mode
- `mpesa_stk` as a legacy compatibility alias only

Wallet funding and subscription STK are allowed to use different Kenya routes.

### G. Preserve sandbox and test semantics

`provider_environment` and `payment_data.test_mode` already affect business logic and must be preserved during migration.

### H. Add a canonical internal state model

The system must define canonical internal status mapping between:

- upstream provider status
- CRM payment intent status
- wallet funding completion
- provisioning completion
- wallet auto-renew outcome

Raw provider status must never directly define final CRM completion semantics.

### I. Add a billing system settings model

System-level billing settings must move out of implicit `IntegrationSetting` ownership into a first-class billing model.

This model must cover:

- billing domains
- branding
- redirect timing
- polling defaults
- SMTP
- operator PIN
- free-trial PIN
- discount PIN
- global discount config

### J. Model proxy-session lifecycle explicitly

Proxy flows require a first-class proxy-session concept for token expiry, open/init/callback timestamps, open count, redirect URL, provider reference, and terminal state.

Legacy `payment_data.link_proxy` remains a compatibility shape only during migration.

### K. Pin execution contract at initiation

Every initiated payment must persist an immutable execution snapshot containing:

- chosen provider profile
- provider type
- environment
- execution mode
- callback contract
- pricing and FX quote
- proxy-session linkage if applicable

Canonical ownership rule:

- `billing_routing_decisions.snapshot_json` is the single canonical persistence owner for the immutable execution contract
- `payments` and `billing_provider_transactions` may cache selected snapshot fields for query performance, but must reference the routing-decision snapshot as source of truth

Callbacks, reconciliation, completion, and diagnostics must read the execution snapshot first, not current market routing.

### L. Treat WordPress payloads as versioned contracts

Wallet balance sync, wallet config sync, and credential sync must be versioned payload contracts with parity requirements, compatibility windows, and rollback rules.

Payload families:

- `wallet_balance_payload`: `version`, client identity, market identity, wallet enabled flag, balance, currency, last sync or as-of timestamp, wallet auto-renew state
- `billing_methods_payload`: `version`, market identity, activation methods, renewal methods, wallet funding availability, self-checkout family visibility, fallback hints allowed for self-service
- `wallet_credentials_payload`: `version`, active environment, auth mode, provider family auth blocks, callback base or domain references, rotation metadata

Versioning rules:

- CRM publishes one active version per payload family at a time
- version selection is driven by CRM-side compatibility configuration and must be explicit, never inferred from ad hoc consumer behavior
- every new payload version must keep the prior version available through a documented deprecation window of at least one release cycle and 90 days, whichever is longer
- a payload version cannot be removed until parity tests and consumer-readiness checks pass

### M. Preserve Payments workspace operations

`Payments` is not only a diagnostics surface. It is also an operator workflow hub for:

- auto-match
- manual match
- review-state changes
- manual close
- retry STK
- send payment link
- provider check
- create subscription

These flows must be preserved during diagnostics and billing refactors.

### N. Define permissions, redaction, and staged operator UX

The plan must define:

- role coverage for `admin`, `sub_admin`, `sales`, and `marketing`
- diagnostics access and redaction levels
- staged removal of normal operator provider selection
- admin-only emergency route override behavior
- wallet wording and copy migration

## 3. Product goals

This design supports the requested outcomes:

- admins configure billing system settings independently from market wallet rules
- admins configure wallet rules independently from PSP credentials
- admins configure PSPs independently from payment-link routing
- admins bind one or many PSP profiles to each market
- each market defines primary and fallback routing per billing surface
- each provider uses a unique schema-driven credential form
- Kenya can run Daraja, KopoKopo, pawaPay, or others side by side
- CRM controls which activation and renewal methods are allowed per market
- wallet funding routes are configurable per market
- wallet auto-renew becomes explicit policy
- proxy versus direct becomes routing policy, not hard-coded branching
- diagnostics cover routing, fallback, webhook, ledger, and provisioning state

## 4. Core concepts

### Provider type

What kind of connector this is.

Examples:

- `daraja`
- `kopokopo`
- `pawapay`
- `elemitech`
- `dusupay`
- `nowpayments`
- `pesapal`
- deferred: `paystack`, `paypal`

### Provider profile

A concrete credential and runtime configuration set for one provider type.

Examples:

- `Kenya / Daraja / Production / Main shortcode`
- `Kenya / KopoKopo / Production / Merchant A`
- `Kenya / pawaPay / Production / Adult-friendly route`
- `Global / NOWPayments / Production / Crypto`

### Market binding

A statement that a provider profile is usable in a specific market or platform for specific billing surfaces.

### Routing rule

A market-level policy that chooses:

- primary binding
- fallback chain
- execution mode
- risk posture

### Payment intent

The CRM-facing payment record.

This remains the `payments` layer and owns:

- business purpose
- client, deal, and platform linkage
- high-level state visible to CRM
- provisioning anchor

### Provider transaction

The upstream transaction or invoice lifecycle state attached to a payment intent.

### Wallet adjustment

An internal CRM credit or debit to the wallet ledger.

### Wallet funding payment

An external payment whose outcome increases wallet balance.

### Wallet subscription payment

A payment executed from wallet balance to activate or renew a subscription.

## 4A. Canonical state model

### Payment intent status

- `initiated`
- `pending_customer_action`
- `pending_provider_confirmation`
- `pending_internal_completion`
- `completed`
- `failed`
- `cancelled`
- `expired`
- `refunded`

### Provider transaction normalized status

- `created`
- `pending_customer_action`
- `pending_provider_confirmation`
- `pending_settlement`
- `succeeded`
- `failed`
- `cancelled`
- `expired`
- `refunded`
- `underpaid`
- `overpaid`
- `unknown`

### Settlement status

- `unsettled`
- `partially_settled`
- `settled`
- `reversed`
- `refunded`

### Provisioning status

- `not_applicable`
- `suppressed_sandbox`
- `queued`
- `completed`
- `failed`

### Wallet funding status

- `not_applicable`
- `pending_credit`
- `credited`
- `credit_failed`
- `reversed`

### Wallet auto-renew status

- `disabled`
- `eligible`
- `scheduled`
- `payment_initiated`
- `renewed`
- `fallback_sent`
- `escalated`

Rules:

- CRM `payments.status` is derived from canonical internal state, never directly from raw provider status.
- Upstream provider success does not imply CRM completion until wallet credit or provisioning side effects have succeeded or been intentionally suppressed.
- Sandbox success maps to sandbox-safe completion state, not live side effects.

Purpose-specific transition matrix:

- `wallet_funding_payment`
  - provider transaction `succeeded` -> payment intent `pending_internal_completion`
  - wallet credit success -> payment intent `completed`, wallet funding status `credited`
  - wallet credit failure -> payment intent `failed`, wallet funding status `credit_failed`, operator compensation required
  - post-credit reversal or refund -> payment intent `refunded`, wallet funding status `reversed`, with compensating wallet debit or manual hold workflow
- `wallet_subscription_payment`
  - provider transaction `succeeded` -> payment intent `pending_internal_completion`
  - provisioning success -> payment intent `completed`, provisioning status `completed`
  - sandbox success -> payment intent `completed`, provisioning status `suppressed_sandbox`
  - provisioning failure after provider success -> payment intent remains `pending_internal_completion` until retry, compensation, or operator escalation resolves it
  - terminal `failed` is allowed only after compensation or operator policy declares the attempt unrecoverable
- `manual` and `free_trial`
  - do not create provider transactions
  - still emit canonical payment, provisioning, and audit transitions through the same reducer

## 4B. Legacy payment-row compatibility semantics

Legacy payment-row fields remain compatibility projections during migration.

Rules:

- `payments.provider_key` is never the authoritative source once `billing_provider_transactions` exist
- `payments.provider_key` projects the provider type of the compatibility provider transaction
- compatibility provider transaction selection rules:
  - if the payment is non-terminal, project the current active provider transaction
  - if the payment is terminal and successful, project the winning terminal provider transaction
  - if the payment is terminal and unsuccessful, project the latest terminal provider transaction unless manual recovery promotes a different one
- `payments.transaction_reference` remains a compatibility reference only
- `payments.transaction_reference` is projected from the compatibility provider transaction using provider-family reference precedence
- late events on superseded provider transactions do not overwrite compatibility projection unless reconciliation or operator recovery explicitly promotes them
- legacy rows created before provider-transaction adoption may remain `legacy_projected` and continue to use existing references until backfilled

Provider-family compatibility reference precedence:

- explicit `compatibility_reference` if stored
- provider transaction id if it is customer-visible and stable
- provider invoice or checkout id
- provider session id
- provider-specific legacy reference extracted from raw payload

## 5. Capability taxonomy

Provider capabilities must be stored in structured metadata.

Required dimensions:

### A. Billing surfaces

- `subscription_link`
- `subscription_push`
- `subscription_invoice`
- `wallet_funding`
- `wallet_auto_renew`
- `manual_confirmation`
- `proxy_hosted_checkout`
- `self_checkout`

### B. Rails

- `card`
- `mobile_money`
- `bank_transfer`
- `crypto`
- `wallet_balance`

### C. Transport modes

- `redirect`
- `push`
- `server_to_server_collection`
- `invoice_address`
- `manual_confirmation`
- `internal_ledger`
- transitional: `django_proxy`

### D. Operation types

- `initiate`
- `status_query`
- `webhook_verify`
- `reconcile`
- `refund`
- `cancel`

### E. Settlement semantics

- immediate
- delayed
- confirmation-based
- invoice-expiry based

### F. Restrictions

- supported currencies
- supported countries
- market restrictions
- adult-content suitability
- merchant-account requirements

## 6. Information architecture

Recommended Settings IA:

```text
Settings
└── Billing
    ├── Overview
    ├── Billing System
    ├── Providers
    ├── Provider Profiles
    ├── Market Routing
    ├── Wallet
    ├── Subscription Rules
    ├── Diagnostics
    └── Audit Log
```

Recommended admin mental model:

```text
Provider type        = what connector exists
Provider profile     = which credentials/config are used
Market binding       = where the profile is enabled
Routing rule         = when it is selected
Fallback rule        = what happens if primary fails
Billing system       = global billing domains, branding, timing, and PIN posture
Wallet rule          = how stored balance is funded and consumed
Subscription rule    = which methods are allowed
Provider transaction = what happened upstream
```

## 7. Role model

The plan must stop using only “admin” and “operator.”

The current CRM role model matters:

- `admin`
- `sub_admin`
- `sales`
- `marketing`

Recommended billing permission model:

- `admin`: full provider, profile, billing-system, cutover, diagnostics, route override, and WP credential control
- `sub_admin`: market-level routing and rule management where explicitly allowed, with redacted diagnostics
- `sales`: no provider or routing management; can only use operator flows allowed by market policy and payment-scoped diagnostics
- `marketing`: no billing management and no diagnostics access

## 7A. Billing permission matrix

Recommended access policy:

- `View Billing workspace`: `admin` full, `sub_admin` in-scope only, `sales` none, `marketing` none
- `Edit provider profiles and routing`: `admin` full, `sub_admin` in-scope only, `sales` none, `marketing` none
- `Edit billing system, PINs, discount config, WP credentials, cutover flags`: `admin` only
- `Run provider tests and route simulator`: `admin` full, `sub_admin` in-scope and redacted, `sales` none, `marketing` none
- `View Payment Diagnostics`: `admin`, `sub_admin`, and `sales` for in-scope payments only
- `View Billing Diagnostics`: `admin` full, `sub_admin` in-scope and redacted, `sales` none, `marketing` none
- `Emergency provider override`: `admin` only
- `Admin wallet credit/debit`: target model is `admin` and `sub_admin` only unless a temporary compatibility exception is explicitly approved

## 8. Operator behavior

Default policy:

- operators choose method
- routing chooses provider
- operators do not choose PSPs in normal flow

Optional advanced behavior:

- admin-only or emergency-only provider override for diagnostics or contingency handling

This removes unnecessary provider internals from day-to-day operator workflows.

## 8A. Operator-flow migration UX

Compatibility stage:

- keep the current provider selector only behind an `Advanced route override` control for `admin`

Default stage:

- `sales` and standard `sub_admin` users see only payment methods
- provider selection is removed from normal operator flow

Required UI copy direction:

- replace `Link provider` with `Route override` for admin-only override mode
- replace `Choose who sends the payment link.` with `CRM will choose the best billing route for this market.`
- replace `No enabled provider available` with `No billing route is available for this market.`

Required result UX:

- every initiated payment shows the selected route outcome after submission
- payment result screens link to `Payment Diagnostics`, not provider settings

## 9. Billing workspace tab behavior

### Providers

Purpose:

- show supported connector types
- show capability matrix
- show implementation status

This tab does not store credentials.

### Billing System

Purpose:

- manage billing domains, branding, redirect timing, polling defaults, SMTP, PIN posture, and discount posture independently of market wallet rules

### Provider Profiles

Purpose:

- store actual credentials and runtime options
- allow multiple profiles for the same provider type

This is where unique provider configuration lives.

### Market Routing

Purpose:

- bind profiles to markets
- define primary and fallback routing per billing surface
- define direct versus proxy behavior

### Wallet

Purpose:

- control stored-value behavior independently of PSPs

This tab owns:

- wallet enablement
- currency
- presets
- limits
- wallet funding routes
- auto-renew policy

### Subscription Rules

Purpose:

- define market-allowed activation and renewal methods

Allowed method set:

- `manual`
- `stk`
- `link`
- `wallet`
- `free_trial`

### Diagnostics

Purpose:

- provide system-level billing diagnostics for admins inside Settings
- validate provider profile readiness, route health, fallback readiness, webhook posture, proxy posture, and auditability
- link into per-payment diagnostics when an admin needs to inspect a concrete payment event

## 9A. Settings decomposition contract

Billing must not continue to depend on one monolithic `settings-integrations` payload after Phase `0B`.

Required rules:

- each Billing tab gets its own read model, API handler, query key, and invalidation scope
- each Billing tab defines `loading`, `empty`, `degraded`, `forbidden`, and `saved` states
- `Diagnostics` must lazy-load and must not block other Billing tabs
- non-billing integration state must not be held inside Billing components

## 10. Source of truth and migration precedence

This is a required part of the design.

### Stage 1: Legacy authority

Before cutover, current runtime remains authoritative:

- `platform.wallet_settings`
- `platform.payment_link_providers`
- wallet system config in `IntegrationSetting`
- platform credential blobs in `IntegrationSetting`

### Stage 2: Dual-write bridge

When the new Billing workspace becomes editable:

- save to new billing tables
- project to legacy structures where current runtime still depends on them
- keep an audit trail of the projected legacy output
- persist new-model rows and legacy projections in one transactional unit where possible
- fail the write if the legacy projection cannot be persisted cleanly

### Stage 2A: System settings and WP contract precedence

During dual-write:

- `wallet_system_config` remains readable through legacy paths
- new billing system settings remain authoritative only for explicit dual-write or shadow-read validation
- WordPress-facing payloads remain versioned and projected through compatibility serializers
- the `Billing System` tab may be editable before live-read cutover, but it remains a dual-write surface until the global system-settings read flip is approved

### Stage 2B: Shadow read

Before runtime cutover:

- resolve config and routing from both legacy and new readers
- store projected snapshots and diffs
- do not execute the new runtime path while diffs remain unexplained

### Stage 2C: Divergence handling and repair

If new and legacy projections drift:

- record a divergence event with the affected market, surface, and config family
- block the affected cutover flag from promotion until divergence is resolved
- support operator-visible drift reporting
- support idempotent reprojection from the authoritative source
- keep rollback-safe reprojection tooling available for both legacy and new stores

### Stage 3: Read cutover

Only after runtime refactor is complete:

- runtime reads new billing tables first
- legacy projection remains as fallback only

### Stage 3A: Billing-system live-read cutover

Global billing-system settings flip separately from market routing.

Rules:

- `wallet_system_config` remains the live-read source until system-settings shadow reads are clean
- the billing-system live-read flip is CRM-global
- global billing-system rollback must not require rolling back market-surface routing

### Stage 3B: Market and surface cutover

Cutover is both market-scoped and surface-scoped.

Each of these surfaces must be independently controllable:

- `subscription_link`
- `subscription_push`
- `wallet_funding`
- `self_checkout`
- `wallet_auto_renew`

### Stage 3C: Mixed-population lifecycle matrix

The system must support these states during rollout:

- `legacy-initiated -> post-cutover completed`
- `new-model initiated -> rollback completed`
- `legacy-projected diagnostics -> new-model diagnostics`
- `new-model routing with legacy fallback read`

Rules:

- execution snapshots always govern in-flight payment interpretation regardless of current read precedence
- legacy-initiated payments continue to resolve through compatibility projection until terminal
- rollback does not rewrite in-flight execution contracts

### Stage 4: Legacy retirement

Only after cutover verification:

- remove dependency on hard-coded legacy arrays
- keep migration/export tooling for rollback and observability

## 11. Data model

Recommended logical model:

```text
billing_provider_types
  id
  key
  label
  capability_json
  status

billing_provider_profiles
  id
  provider_type_key
  profile_name
  country_code
  market_id nullable
  merchant_scope_json
  environment
  config_json
  secrets_json
  active

billing_system_settings
  id
  scope
  mode_json
  domain_json
  branding_json
  timing_json
  smtp_json
  pin_policy_json
  discount_policy_json
  updated_by
  updated_at

Scope rule:

- first release supports a single `global` billing system settings record for the CRM instance
- `platform` or narrower scopes are reserved for a later extension and must not be partially introduced during this migration

billing_market_provider_bindings
  id
  market_id
  provider_profile_id
  billing_surface
  enabled
  operator_enabled
  self_service_enabled
  execution_mode
  priority
  fallback_group
  restriction_json
  notes

billing_routing_rules
  id
  market_id
  billing_surface
  primary_binding_id
  fallback_strategy_json
  risk_policy_json
  active

billing_wallet_rules
  id
  market_id
  enabled
  currency_code
  topup_preset_json
  limit_json
  auto_renew_json
  ui_json

billing_subscription_rules
  id
  market_id
  activation_method_json
  renewal_method_json
  free_trial_json
  discount_json
  expiry_policy_json

billing_provider_transactions
  id
  payment_id
  provider_type_key
  provider_profile_id
  normalized_status
  provider_transaction_id
  provider_session_id nullable
  provider_invoice_id nullable
  provider_status
  requested_amount
  requested_currency
  charge_amount nullable
  charge_currency nullable
  settled_amount nullable
  settled_currency nullable
  fee_amount nullable
  fee_currency nullable
  fx_rate nullable
  fx_source nullable
  fx_locked_at nullable
  settlement_status
  expires_at nullable
  confirmation_state_json
  upstream_reference_json
  attempt_group_key nullable
  attempt_sequence nullable
  retry_of_provider_transaction_id nullable
  fallback_from_provider_transaction_id nullable
  compatibility_reference nullable
  state_version
  raw_state_json
  last_status_at nullable

billing_webhook_events
  id
  provider_type_key
  provider_profile_id
  market_id
  provider_event_id nullable
  dedupe_key
  headers_json
  raw_body
  payload_json
  signature_status
  verification_meta_json
  processing_status
  retry_count
  last_error nullable
  billing_provider_transaction_id nullable
  payment_id nullable
  received_at
  processed_at nullable

billing_routing_decisions
  id
  payment_id
  market_id
  billing_surface
  chosen_binding_id
  provider_profile_id
  provider_type_key
  execution_mode
  environment
  fallback_taken
  decision_version
  shadow_diff_json nullable
  surface_cutover_flag nullable
  snapshot_json
  immutable_until_terminal_state
  decision_json
  created_at

Snapshot ownership note:

- `snapshot_json` is the canonical immutable execution contract for the payment
- provider transactions and payment rows may store denormalized snapshot pointers or summary fields, but not competing authoritative snapshots

billing_proxy_sessions
  id
  payment_id
  billing_routing_decision_id
  provider_profile_id
  provider_type_key
  environment
  token_hash
  token_expires_at
  redirect_url
  provider_reference
  opened_at nullable
  open_count
  initialized_at nullable
  callback_at nullable
  rotation_count
  state
  legacy_meta_json nullable
```

Important notes:

- country alone is not enough; runtime binding is by market or platform
- profiles can be reusable across markets, but bindings are market-specific
- provider transactions are mandatory for the active provider set
- historical payments may continue to read legacy `payment_data.link_proxy` and composed diagnostics until backfilled or projected
- `payment_attempts` remains part of the compatibility model and must correlate to routing decisions and provider transactions

## 12. Runtime service boundaries

### A. Wallet ledger service

Owns:

- credit and debit integrity
- balance history
- compensation
- idempotency

Does not own:

- provider initiation
- provider selection
- routing

### A1. Billing system settings service

Owns:

- billing domains
- branding
- redirect timing
- polling defaults
- SMTP
- PIN posture
- discount posture

### B. Provider registry

Owns:

- provider metadata
- capability taxonomy
- credential schemas
- provider adapter lookup

### C. Routing engine

Owns:

- provider selection
- fallback chain
- direct versus proxy decision
- environment resolution

### D. Wallet funding orchestrator

Owns:

- customer-funded wallet payment initiation
- completion and wallet credit
- fallback behavior for wallet funding

### E. Subscription billing orchestrator

Owns:

- activation flow
- renewal flow
- self-checkout flow
- wallet renewal flow

### F. Provider transaction service

Owns:

- normalized upstream transaction state
- status updates
- settlement updates
- correlation between provider events and payments

### G. Diagnostics assembler

Owns:

- shared diagnostics backbone for all billing diagnostics surfaces
- route visibility
- provider attempts
- fallback timeline
- wallet ledger events
- provisioning events
- recommended next actions

### H. Proxy session service

Owns:

- CRM proxy token lifecycle
- proxy open/init/callback timestamps
- redirect URL persistence
- provider reference persistence
- compatibility reads from legacy `payment_data.link_proxy`

Binding rule:

- creating a proxy session alone does not create a provider transaction
- the first `billing_provider_transaction` is created only when upstream checkout, invoice, session allocation, or STK initiation succeeds far enough to allocate a provider reference
- unopened or expired proxy sessions without upstream initialization remain proxy-session records only
- token rotation updates proxy-session lifecycle and creates a new provider transaction only if upstream initialization is restarted

### I. Execution snapshot contract

Owns:

- immutable initiation snapshot
- pricing and FX quote lock
- callback contract freeze
- provider-profile pinning for in-flight payments

### J. Reconciliation and status-query service

Owns:

- polling policy
- stale-payment verification
- webhook-plus-polling behavior
- settlement confirmation when provider family requires it

### K. WordPress projection layer

Owns:

- versioned payload projection for balance, config, and credentials
- backward-compatible payload serializers
- parity guarantees during migration

### L. Payments operations compatibility layer

Owns:

- queue-action continuity
- recommendation-engine parity
- manual operational workflow preservation during migration

## 12A. Retry and fallback correlation model

Rules:

- each retry creates a new `payment_attempt`
- fallback to a different provider creates a new `billing_provider_transaction`
- status-query and reconcile operations attach to the current provider transaction, not a new one
- diagnostics must show one payment, many attempts, and possibly many provider transactions with lineage
- each payment has exactly one `current_provider_transaction` candidate at a time
- retries or fallbacks supersede the previous current provider transaction but do not erase its history
- late webhooks on superseded provider transactions update that transaction history only and cannot overwrite payment intent state unless reconciliation or manual recovery explicitly promotes them
- once a payment has a winning terminal provider transaction, later status queries default to that winning terminal transaction for reversal or refund monitoring only

## 12D. Webhook ordering and monotonic state progression

Rules:

- webhook processing must be monotonic
- provider transaction updates use `state_version`, provider event ordering metadata, or equivalent logical clock to prevent older events from regressing newer state
- a delayed `initiated` or `pending` event must never overwrite a later `succeeded`, `failed`, `expired`, or `refunded` state
- when provider-family ordering guarantees are weak, webhook handlers must defer to the canonical transition reducer and current-provider selection rules before mutating payment intent state

## 12B. Reconciliation and polling policy

Every provider family must declare one of:

- `webhook_primary`
- `webhook_plus_polling`
- `polling_required`

Profiles must define:

- polling cadence
- backoff
- max age
- stale thresholds
- sandbox behavior

Precedence rules:

- reconcile polls the current provider transaction while a payment is non-terminal
- after terminal success, reconcile polls only the winning terminal provider transaction for post-settlement changes
- after terminal failure or expiry, reconcile polls only when provider-family policy explicitly requires delayed terminal confirmation
- reconciliation may promote a different provider transaction to compatibility projection only through explicit promotion rules recorded in audit and diagnostics

Legacy reference parity:

- legacy reconciliation may continue to read `payments.transaction_reference`, `payments.reference_number`, and `payment_data.link_proxy.provider_reference` through the compatibility mapping layer
- provider-family mappers must define how legacy references map onto normalized provider transactions before cutover

## 12C. Payment-link URL compatibility contract

Static or direct payment-link URL resolution order:

1. provider `url`
2. provider `base_url` + `path`
3. origin derived from `platform.wp_api_url` with `/pay`
4. `platform.domain` with `/pay`

Rules:

- `proxy_hosted_checkout` providers do not resolve a direct static URL before proxy initiation; they return `null` until proxy flow yields the redirect URL
- compatibility tests must lock this fallback order before runtime refactor

## 13. Kenya provider semantics

This section is required before adapter implementation.

### Daraja

Use as a first-class provider type for direct Safaricom integration.

### KopoKopo

Use as a first-class provider type for aggregator-based mobile money collection.

### Django proxy

Treat as a transitional transport mode or bridge, not as the final provider model.

### Legacy alias

`mpesa_stk` remains only as a compatibility alias during migration.

### Rule

Do not force Kenya wallet funding and Kenya subscription STK onto the same route by assumption.

Example:

```text
Kenya / wallet_funding
  primary: KopoKopo Production
  fallback: Daraja Production

Kenya / subscription_push
  primary: Daraja Production
  fallback: KopoKopo Production
```

## 14. NOWPayments semantics

NOWPayments must not inherit hosted-card assumptions.

It requires first-class support for:

- invoice creation
- invoice expiry
- chain and network
- address lifecycle
- underpayment and overpayment
- confirmation thresholds
- payout or settlement currency

It should be exposed only on supported crypto surfaces.

## 14A. FX and currency policy

Currencies:

- pricing currency
- charge currency
- settlement currency
- wallet currency

Rules:

- no implicit wallet currency conversion in the first release
- self-checkout FX override becomes explicit provider-profile or route policy, not ad hoc config
- every FX-applied payment stores `fx_rate`, `fx_source`, `fx_locked_at`, and rounding precision
- completion logic must define tolerance rules for underpayment and overpayment where the provider family supports them

First-release underpayment and overpayment workflow:

- `wallet_funding_payment` underpayment does not auto-credit a partial wallet balance in the first release unless a provider-family policy explicitly enables partial credit
- default `wallet_funding_payment` underpayment outcome is `underpaid` with operator review or customer retry required
- `wallet_subscription_payment` underpayment does not provision the subscription in the first release; it remains `underpaid` pending retry, fallback, or manual review
- overpayment never silently provisions extra value; any extra amount must follow explicit provider-family or operator compensation policy

## 15. Wallet auto-renew policy

Wallet auto-renew must be explicit market policy, not hidden side effect.

Recommended policy:

```text
Market rule:
  auto_renew_from_wallet = true
  grace_before_expiry_hours = 24
  retry_count = 2
  insufficient_balance_action = send_payment_link
```

Required UX states:

- `Wallet auto-renew enabled`
- `Renewal scheduled`
- `Wallet charged successfully`
- `Wallet insufficient, fallback sent`
- `Wallet retry failed, operator review needed`

## 16. Proxy versus direct

This is routing policy, not provider-specific branching.

Every market binding should expose:

- `direct`
- `proxy`
- `proxy_required_for_high_risk`

Risk inputs can include:

- market risk class
- product or content class
- provider policy constraints
- campaign or acquisition source

## 17. WordPress-facing behavior

WordPress-facing surfaces are part of the billing contract.

The CRM must expose:

- market-allowed billing methods
- wallet state and wallet auto-renew state
- safe active credential sync for WP integrations
- routing-safe self-checkout behavior per provider family

WP payload contract versioning:

- each payload family gets a `version`
- each version defines a compatibility window
- each version defines producer and fallback behavior
- parity tests must protect current payload expectations during migration

Required first-release payload definitions:

- `wallet_config_sync_payload.v1`: delivered through the current anchor-client pattern and includes `platform_id`, `mode`, `synced_at`, and `config`
- `wallet_config_sync_payload.v1.config`: `market.platform_id`, `market.currency`, `topup_presets`, `providers`, `show_refresh_button`, `allow_combined_topup_subscribe`, `recent_transactions_limit`, refresh-rate settings, polling interval, `sandbox_badge`, `business_name`, and `description`
- `wallet_balance_sync_payload.v1`: `platform_id`, `wp_user_id`, `wp_post_id`, `balance`, `currency`, `mode`, `refreshed_at`, `wallet_last_synced_at`, `last_topup`, and `transactions`
- `billing_methods_payload.v1`: `market_reference`, `activation_methods`, `renewal_methods`, `wallet_funding_enabled`, `self_checkout_families`, `default_customer_path`
- `wallet_credentials_sync_payload.v1`: `crm_api_base_url`, `wallet_api_base_url`, `platform_id`, `bearer_key`, and `hmac_secret`

Version governance:

- producer selects a payload family version explicitly via CRM compatibility settings
- consumers must advertise readiness before default version flips
- deprecation window is minimum 90 days and one stable release cycle
- wallet config sync remains anchor-client targeted until the WP bridge explicitly supports a broader delivery model
- active-environment credentials are pushed immediately during sync
- inactive-environment credential rotation is stored and deferred until that environment becomes active
- parity requirements must preserve current `mode`, `last_topup`, `transactions`, and anchor-client delivery semantics until a later coordinated WP contract revision

## 17A. WordPress sync retry and idempotency

Rules:

- CRM must persist sync-attempt outcome for wallet config, wallet balance, and credential sync operations
- failed syncs must be retryable without duplicating side effects on the WordPress side
- retries should be keyed by payload family, platform, target consumer identity, environment, and payload version
- successful sync state must record last successful sync time separately from last attempted sync time

## 18. Diagnostics requirements

Diagnostics must work across all billing surfaces.

The product must expose two diagnostics surfaces backed by the same diagnostics backbone:

- `Payment Diagnostics`: a per-payment investigation surface in the `Payments` workspace
- `Billing Diagnostics`: a system and configuration health surface inside `Settings > Billing`

These surfaces must not drift into separate logic stacks. The same backend assembler layer should power both, with different presentation and query scope.

Every billing event should expose:

- payment identity
- billing surface
- provider type
- provider profile
- route decision
- fallback chain
- direct or proxy mode
- provider transaction state
- webhook state
- wallet mutations
- provisioning state
- operator actions

### Payment Diagnostics

Purpose:

- explain what happened to one specific payment
- help operators resolve payment, wallet, webhook, and provisioning issues

Scope:

- one payment intent
- its provider transactions
- its routing decision
- its wallet impact
- its provisioning outcome
- its operator action history

Required sections:

- `Overview`
- `Routing`
- `Provider Transactions`
- `Provider Attempts`
- `Webhook Events`
- `Wallet Ledger`
- `Provisioning`
- `Proxy Lifecycle`
- `Recommended Actions`

### Billing Diagnostics

Purpose:

- explain whether billing is correctly configured and healthy for a market, provider, provider profile, or billing surface
- help admins validate readiness before operators or WordPress users hit live flows

Scope:

- provider profile readiness
- market routing configuration
- fallback chain readiness
- proxy versus direct posture
- webhook endpoint and signature health
- sandbox/live posture
- recent failure trends
- WordPress sync health
- audit history for configuration changes

Required sections:

- `Readiness`
- `Route Simulator`
- `Fallback Health`
- `Provider Health`
- `Webhook Health`
- `Proxy Health`
- `Sandbox / Live Posture`
- `WP Contract Health`
- `Recent Billing Failures`
- `Audit Trail`

Shared backend diagnostics sections:

```text
Overview
Routing
Provider Transactions
Provider Attempts
Webhook Events
Wallet Ledger
Provisioning
Proxy Lifecycle
Recommended Actions
```

### Diagnostics governance

Rules:

- secrets are never returned to either diagnostics surface
- `Billing Diagnostics` readiness shows `missing`, `configured`, `invalid`, or `masked`, not raw secret material
- `sub_admin` sees in-scope profile readiness and route outcomes, but not raw webhook bodies, secret-adjacent headers, or full callback tokens
- `sales` sees payment-scoped diagnostics only, with normalized event summaries instead of raw webhook payloads
- exported diagnostics are always redacted
- drill-through from `Billing Diagnostics` to `Payment Diagnostics` must reuse market-access checks

### Historical diagnostics continuity

Rules:

- diagnostics assembler reads new provider transactions first
- legacy payments without provider transactions fall back to `payment_attempts`, `raw_payload`, and legacy references
- UI must label whether the diagnostic source is `new_model` or `legacy_composed`
- Phase 2 must explicitly choose a strategy per historical cohort: one-time backfill, bounded selective backfill, or bounded `legacy_composed` support window
- permanent unbounded dual diagnostics logic for active operational lookback windows is not an acceptable target state

### Payments workspace action disposition

Required action preservation:

- `auto-match`: remains in `Payments` and continues to operate on payment queue records
- `manual match`: remains in `Payments` with improved lineage visibility from diagnostics
- `review-state changes`: remain in `Payments` and are not moved into Billing Settings
- `manual close`: remains in `Payments` with audit logging unchanged or strengthened
- `retry STK`: remains available where market policy and provider family allow it, but uses the new routing and snapshot contracts
- `send payment link`: remains available as an operator action, but route/provider choice is policy-driven unless an authorized admin override is used
- `provider check`: becomes a shared diagnostics-backed action surfaced in `Payments` and `Billing Diagnostics` according to permissions
- `create subscription`: remains available from operational flows that currently support it and must not be dropped during diagnostics refactor

Endpoint and response compatibility:

- current operator actions must keep equivalent endpoint semantics, response categories, and permission behavior until the new Payments workflow is fully cut over
- where endpoints move or payloads change, compatibility wrappers must be provided during migration

## 19. UI and UX wireframes

### Billing workspace

```text
+------------------------------------------------------------------+
| Settings / Billing                                               |
+------------------------------------------------------------------+
| Overview | Providers | Profiles | Routing | Wallet | Rules       |
| Diagnostics | Audit Log                                          |
+------------------------------------------------------------------+
| Market: [ Kenya v ]   Environment: [ Production v ]              |
+------------------------------------------------------------------+
| Primary wallet funding | Primary subscription route | Auto-renew  |
| Proxy posture          | WP contract health         | Alerts      |
+------------------------------------------------------------------+
| Alerts                                                          |
| - Daraja callback URL missing                                   |
| - KopoKopo fallback ready                                       |
| - Wallet auto-renew enabled for 3 products                      |
+------------------------------------------------------------------+
```

### Market routing

```text
+------------------------------------------------------------------+
| Routing / Kenya                                                  |
+------------------------------------------------------------------+
| Surface            Primary              Fallback       Mode       |
| subscription_push  Daraja Prod          KopoKopo Prod  direct     |
| subscription_link  ElemiTech Prod       DusuPay Prod   proxy-risk |
| wallet_funding     KopoKopo Prod        Daraja Prod    direct     |
| wallet_auto_renew  wallet_balance       subscription_link policy  |
+------------------------------------------------------------------+
```

### Diagnostics

```text
+------------------------------------------------------------------+
| Payment Diagnostics                                              |
+------------------------------------------------------------------+
| Overview | Routing | Provider Tx | Webhooks | Wallet | Provision |
+------------------------------------------------------------------+
| Payment: #12831     Surface: wallet_funding                      |
| Market: Kenya       Provider: KopoKopo Production                |
| Route: primary      Fallback: Daraja pending                     |
+------------------------------------------------------------------+
| Provider transaction                                             |
| - upstream id: COL-88921                                         |
| - status: pending_customer_action                                |
| - requested: 1000.00 KES                                         |
| - settled: --                                                    |
| - expires at: 2026-04-03 18:20                                   |
+------------------------------------------------------------------+
| Webhooks                                                         |
| - received: yes                                                  |
| - signature: valid                                               |
| - processor: completed                                           |
+------------------------------------------------------------------+
```

### Billing diagnostics

```text
+------------------------------------------------------------------+
| Billing Diagnostics                                              |
+------------------------------------------------------------------+
| Readiness | Route Simulator | Provider Health | Webhook Health   |
| Proxy Health | WP Contract | Failures | Audit Trail             |
+------------------------------------------------------------------+
| Market: Kenya    Surface: subscription_link    Env: Production   |
| Active route: ElemiTech Prod -> DusuPay Prod fallback            |
+------------------------------------------------------------------+
| Readiness                                                        |
| - ElemiTech credentials: valid                                   |
| - DusuPay credentials: valid                                     |
| - callback base URL: missing for Daraja sandbox                  |
| - WP method contract: in sync                                    |
+------------------------------------------------------------------+
| Route simulator                                                  |
| - input: Kenya / subscription_link / high-risk                   |
| - selected mode: proxy_required_for_high_risk                    |
| - primary: ElemiTech Prod                                        |
| - fallback: DusuPay Prod                                         |
+------------------------------------------------------------------+
| Recent failures                                                  |
| - 12 webhook signature failures in last 24h                      |
| - 3 proxy handoff failures in last 6h                            |
+------------------------------------------------------------------+
```

## 20. Backward-compatible rollout

Required rollout rules:

1. All new behavior ships behind billing feature flags.
2. Legacy shapes remain readable until final cleanup.
3. The new Billing workspace is read-only or dual-writing until runtime is cut over.
4. Deferred providers stay isolated from active-provider architecture.
5. Sandbox behavior must remain operationally visible throughout rollout.

## 21. Test plan

### A. Unit tests

Target:

- provider capability resolution
- routing engine selection
- fallback chain evaluation
- provider transaction normalization
- wallet auto-renew policy evaluation
- schema validation

### B. Feature tests

Target:

- settings save and load
- dual-write projection behavior
- payment-link generation
- wallet funding initiation and completion
- subscription initiation and provisioning
- renewal runner behavior
- payment diagnostics payload generation
- billing diagnostics health payload generation
- webhook receipt and processing

## 21A. Coverage minimums and suite split

### Compatibility suite

Must cover:

- legacy `wallet_settings`
- legacy `payment_link_providers`
- WordPress credential sync
- sandbox and `test_mode` semantics
- deferred-provider continuity

### New-model suite

Must cover:

- provider profiles
- market routing
- activation-method visibility
- policy-selected routing
- diagnostics redaction
- wallet auto-renew
- self-checkout provider-family behavior

### Browser minimums

Must cover:

- `admin`: create/edit/test provider profile, save route, view Billing Diagnostics, rotate/push WP credentials
- `sub_admin`: in-scope route edit, out-of-scope forbidden state, redacted Billing Diagnostics
- `sales`: activation modal without provider selector, payment diagnostics drawer, wallet state view, policy-driven fallback visibility
- degraded diagnostics state
- wallet auto-renew fallback state

## 22A. Wallet wording and copy migration

Required copy direction:

- `Manual Top-up` becomes `Admin Credit`
- `Record top-up` becomes `Apply admin credit`
- `Balance Adjustment` becomes `Admin Adjustment`
- `Wallet top-up` is reserved for customer-funded wallet payments only
- wallet renewal states must explicitly differentiate `Wallet auto-renew enabled`, `Renewal charged from wallet`, `Wallet insufficient`, and `Fallback link sent`

### C. Contract tests

Target:

- Daraja
- KopoKopo
- pawaPay
- ElemiTech
- DusuPay
- NOWPayments
- Pesapal compatibility

These should be fixture-based, not live-network tests.

### D. Browser automation

This is mandatory for rollout safety.

Priority flows:

1. configure provider profile
2. bind provider to market
3. activate subscription with market-allowed method
4. fund wallet
5. simulate expiry and wallet auto-renew
6. inspect diagnostics across routing and fallback

### E. Migration tests

These are mandatory.

We need tests proving:

- old `wallet_settings` still load
- old `payment_link_providers` still load
- system wallet config, PINs, and branding still behave correctly
- WordPress credential sync still behaves correctly
- sandbox and live behavior remain correct during cutover

## 22. UI and UX quality bar

World-class UI and UX here means:

- low cognitive load
- high trust
- no ambiguity in money movement
- clear fallback behavior
- clear operator next actions

Principles:

- provider internals hidden from normal operator flows
- progressive disclosure for advanced routing details
- explicit ready, partial, degraded, failed, empty, and loading states
- market context always visible
- every money movement has a visible trace

## 23. Final recommendation

Proceed with the vision, but only with the amended design.

The team should not start with provider adapter implementation.

The correct first move is:

1. model hardening
2. compatibility and precedence contract
3. Billing workspace seam extraction
4. browser automation setup

Only after that should the runtime refactor and provider adapter work begin.
