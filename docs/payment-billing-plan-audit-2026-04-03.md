# Payment Billing Plan Audit

Date: 2026-04-03
Project: Exotic CRM
Scope: Pre-implementation audit of the proposed billing decoupling, provider expansion, routing, wallet, renewal, proxy, and diagnostics plan.

## Overall verdict

The plan direction is good, but it is not execution-safe yet.

Current rating:

- Identification quality: `7/10`
- Execution readiness: `4/10`
- Regression risk if started as-is: `high`

Why:

- the target architecture is materially better than the current state
- the backlog is phased and thoughtful
- but several runtime contracts and migration assumptions are still unmodeled

## Highest-risk findings

### 1. The plan does not yet define a first-class provider transaction model

The proposed data model adds routing, profiles, and webhook events, but it still relies too heavily on the existing `payments` row plus `transaction_reference`, `raw_payload`, and `payment_data`.

That is too shallow for:

- NOWPayments invoice lifecycle
- async mobile-money settlement
- reversals and retries
- multiple upstream identifiers
- fee and net-settlement tracking
- confirmation depth versus final settlement

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Models/Payment.php`
- `/Users/ian/Projects/exotic-crm/app/Services/PaymentCompletionService.php`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-decoupling-spec-2026-04-03.md`

Decision needed before execution:

- define a normalized provider transaction model, or explicitly decide that `payments` remains canonical and add structured child records for upstream transaction state

### 2. Provider capability modeling is still too coarse

The plan distinguishes wallet, STK, hosted checkout, proxy, webhook, crypto, and self-checkout. That is useful, but it is not enough.

Missing dimensions:

- rail and network
- push versus redirect versus invoice
- supported currencies
- market or merchant-account eligibility
- settlement model
- confirmation semantics
- direct versus aggregator behavior

This is the exact area where provider-specific logic will leak back into controllers and services if not modeled now.

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Services/BillingModeService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/LegacyStkService.php`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-decoupling-spec-2026-04-03.md`

Decision needed before execution:

- define provider capabilities with channel, rail, operation, currency, and settlement constraints, not only broad surface badges

### 3. “Wallet top-up” is currently two different concepts

Today the CRM uses the same language for:

- manual admin wallet credit
- PSP-funded wallet payment initiation

Those are not the same workflow, risk model, or audit trail.

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/ClientWalletController.php`
- `/Users/ian/Projects/exotic-crm/app/Services/BillingGatewayService.php`

Decision needed before execution:

- rename and separate these concepts in the product model, API, UI copy, and backlog
- recommended split:
  - `wallet_adjustment`
  - `wallet_funding_payment`
  - `wallet_subscription_payment`

### 4. The compatibility bridge is under-scoped

The backlog currently treats compatibility mostly as projecting legacy `wallet_settings` and `payment_link_providers`.

That misses runtime dependencies already stored outside those arrays, including:

- wallet system mode
- operator, free-trial, and discount PINs
- billing branding
- SMTP-related billing notifications
- WordPress wallet credentials
- encrypted provider credentials

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Services/WalletSettingsService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/WalletSyncService.php`
- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/SettingsController.php`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-implementation-backlog-2026-04-03.md`

Decision needed before execution:

- define exactly what remains legacy
- define what becomes authoritative in the new billing model
- define precedence rules during migration

### 5. The M-Pesa and KopoKopo split is not resolved enough for multi-profile routing

The plan assumes Daraja and KopoKopo can become clean sibling provider profiles per market.

The current code does not support that assumption yet:

- `mpesa_stk` is both a provider label and a transport wrapper
- wallet top-up requires `direct_provider`
- legacy subscription STK still allows `django_proxy`
- some KopoKopo credentials live effectively as global service config, not true market profiles

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Services/BillingModeService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/BillingGatewayService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/LegacyStkService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/KopokopoService.php`

Decision needed before execution:

- define provider, transport, and surface boundaries for Kenya before building profile UI

### 6. The webhook inbox model is not complete enough yet

The proposed webhook table stores event type, reference, payload, and status fields. That is not enough for safe async processing.

Still needed:

- raw request body
- request headers
- provider delivery/event id
- dedupe key
- verification outcome detail
- replay counters
- processor error history
- direct link to provider transaction state

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/API/BillingController.php`
- `/Users/ian/Projects/exotic-crm/app/Services/BillingGatewayService.php`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-decoupling-spec-2026-04-03.md`

Decision needed before execution:

- expand webhook storage and processing contracts before adapter work starts

### 7. The new Billing workspace could become a source-of-truth trap

The backlog allows the Billing workspace to exist and save new config before runtime fully reads it.

That creates a dangerous state where:

- admins think configuration is live
- production still reads old arrays and hard-coded branches
- support and diagnostics become misleading

Current evidence:

- `/Users/ian/Projects/exotic-crm/app/Services/PaymentLinkService.php`
- `/Users/ian/Projects/exotic-crm/app/Services/DealPaymentService.php`
- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/DealController.php`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-implementation-backlog-2026-04-03.md`

Decision needed before execution:

- either keep the new Billing UI read-only until runtime is connected
- or dual-write legacy config until runtime migration is complete

### 8. Frontend refactor effort is understated

The current Settings page is a very large stateful workspace, not a light shell ready for a Billing tab set.

The current Payments, Deals, and ClientDetail screens also embed assumptions that the plan treats as later details.

Current evidence:

- `/Users/ian/Projects/exotic-crm/resources/js/pages/Settings.jsx`
- `/Users/ian/Projects/exotic-crm/resources/js/pages/Deals.jsx`
- `/Users/ian/Projects/exotic-crm/resources/js/pages/ClientDetail.jsx`
- `/Users/ian/Projects/exotic-crm/resources/js/pages/Payments.jsx`

Decision needed before execution:

- insert an explicit UI shell extraction phase before the Billing workspace rollout

### 9. Operator, WordPress, and diagnostics behavior is still underspecified

The plan correctly says operators should only see valid methods and should not need provider internals.

But current flows still:

- hard-code method lists
- ask operators to choose link providers
- do not expose wallet as a renewal/activation method in CRM modals
- have narrow diagnostics sections and provider checks

Current evidence:

- `/Users/ian/Projects/exotic-crm/resources/js/pages/ClientDetail.jsx`
- `/Users/ian/Projects/exotic-crm/resources/js/pages/Deals.jsx`
- `/Users/ian/Projects/exotic-crm/resources/js/pages/Payments.jsx`
- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/PaymentQueueController.php`
- `/Users/ian/Projects/exotic-crm/app/Http/Controllers/API/PaymentController.php`

Decision needed before execution:

- decide whether provider choice is ever an operator-facing concept
- define the exact WP method-discovery contract before runtime refactor starts

### 10. The test strategy is not yet strong enough for the UI rollout

The repo has no frontend test runner today, while the plan relies on UI smoke coverage in areas most likely to regress.

Current evidence:

- `/Users/ian/Projects/exotic-crm/package.json`
- `/Users/ian/Projects/exotic-crm/docs/payment-billing-implementation-backlog-2026-04-03.md`

Decision needed before execution:

- add browser automation or component/UI test coverage before major settings and diagnostics refactors

## Assumptions to eliminate before execution

These assumptions should be explicitly removed from the plan:

1. One `provider_key` is enough to represent provider, rail, transport, and network.
2. One `transaction_reference` is enough for every provider family.
3. `completed` always means settled and final.
4. Country coverage implies market eligibility.
5. NOWPayments can share the same contract as card-style hosted checkout.
6. The current Paystack/Pesapal flow shape should define the canonical future abstraction.
7. `mpesa_stk` is a true provider type instead of a compatibility alias.
8. The new Billing UI can safely save configuration before runtime reads it.
9. Legacy compatibility only means reading `wallet_settings` and `payment_link_providers`.
10. Admin/operator role language maps cleanly onto the current `admin`, `sub_admin`, and `sales` model.
11. “Wallet top-up” is a single concept.
12. UI smoke testing is enough for the rollout.

## Provider verification notes

Official provider documentation confirms that the active target providers do not all fit one legacy abstraction:

- pawaPay supports mobile-money style deposit flows and wallet/account constructs
- DusuPay requires callback verification discipline
- NOWPayments has invoice and crypto-specific lifecycle concerns
- KopoKopo and Daraja are not interchangeable at the transport and credential-scope level

This confirms that the adapter layer is necessary, but also confirms the current adapter contract is not detailed enough yet.

## Recommended gating changes before implementation starts

Do these first:

1. Add a pre-phase called `Phase 0A: Model hardening`.
   - provider transaction model
   - webhook event model
   - source-of-truth and precedence rules
   - provider capability taxonomy

2. Split `Settings.jsx` before the Billing workspace rollout.
   - extract a dedicated Billing workspace shell
   - reduce merge and regression risk

3. Add a compatibility contract document.
   - legacy reads
   - legacy writes
   - dual-write rules
   - cutover criteria

4. Resolve Kenya provider semantics before adapter implementation.
   - Daraja
   - KopoKopo
   - Django proxy compatibility
   - wallet top-up versus subscription STK

5. Add a frontend automation strategy.
   - Playwright or similar browser coverage for settings, activation, wallet, and diagnostics flows

## Go / no-go recommendation

Recommendation: `No-go for implementation as currently written`.

Recommended next state:

- keep the overall architecture direction
- revise the spec and backlog to eliminate the assumptions above
- then start implementation

If the plan is revised around those gaps, it can become a strong execution candidate.
