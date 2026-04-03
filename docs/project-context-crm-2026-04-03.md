# Project Context: CRM

Date: 2026-04-03
Project: Exotic CRM
Repo root: `/Users/ian/Projects/exotic-crm`

## 1. What this repo is

This is the Laravel CRM that owns:

- sales workspace and operator workflows
- clients, deals, leads, campaigns, and reports
- payment import, reconciliation, diagnostics, and queue review
- wallet settings, wallet sync, payment links, hosted checkout, STK, and renewal orchestration
- WordPress bridge payloads, credential push, and provisioning support

For the billing program, this repo is the system of orchestration and authoritative business logic.

## 2. Stack

Backend:

- PHP `^8.1`
- Laravel `^10`
- Laravel Sanctum
- Laravel Socialite
- Guzzle
- PhpSpreadsheet
- KopoKopo PHP SDK

Frontend:

- React `19`
- React Router `7`
- TanStack React Query `5`
- Vite `6`
- Tailwind `4`
- Recharts

Testing/tooling:

- PHPUnit `10`
- Laravel feature and unit tests
- Laravel Pint

Key files:

- [composer.json](/Users/ian/Projects/exotic-crm/composer.json)
- [package.json](/Users/ian/Projects/exotic-crm/package.json)
- [resources/js/app.jsx](/Users/ian/Projects/exotic-crm/resources/js/app.jsx)

## 3. Architectural shape

The app is a Laravel backend with a React SPA frontend mounted from `resources/js`.

Important frontend patterns:

- route-driven SPA using `react-router-dom`
- React Query for server-state fetching and invalidation
- page-level surfaces under `resources/js/pages`
- shared UI under `resources/js/components`

Important backend patterns:

- controller + service style, with significant logic in `app/Services`
- payment and wallet behavior currently concentrated in service classes rather than isolated billing modules
- billing refactor target is to move new abstractions into `app/Billing/*` while preserving compatibility

## 4. Billing-critical hotspots

Runtime billing and compatibility:

- [app/Services/BillingGatewayService.php](/Users/ian/Projects/exotic-crm/app/Services/BillingGatewayService.php)
- [app/Services/PaymentCompletionService.php](/Users/ian/Projects/exotic-crm/app/Services/PaymentCompletionService.php)
- [app/Services/DealPaymentService.php](/Users/ian/Projects/exotic-crm/app/Services/DealPaymentService.php)
- [app/Services/PaymentLinkService.php](/Users/ian/Projects/exotic-crm/app/Services/PaymentLinkService.php)
- [app/Services/HostedCheckoutService.php](/Users/ian/Projects/exotic-crm/app/Services/HostedCheckoutService.php)
- [app/Services/LegacyStkService.php](/Users/ian/Projects/exotic-crm/app/Services/LegacyStkService.php)
- [app/Services/RenewalService.php](/Users/ian/Projects/exotic-crm/app/Services/RenewalService.php)

Settings and billing control plane:

- [resources/js/pages/Settings.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Settings.jsx)
- [app/Http/Controllers/CRM/SettingsController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/SettingsController.php)
- [app/Services/WalletSettingsService.php](/Users/ian/Projects/exotic-crm/app/Services/WalletSettingsService.php)
- [app/Services/BillingModeService.php](/Users/ian/Projects/exotic-crm/app/Services/BillingModeService.php)

Diagnostics and payments workspace:

- [resources/js/pages/Payments.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Payments.jsx)
- [app/Http/Controllers/CRM/PaymentQueueController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/PaymentQueueController.php)

CRM activation and client-facing operator flows:

- [resources/js/pages/Deals.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Deals.jsx)
- [resources/js/pages/ClientDetail.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/ClientDetail.jsx)
- [app/Http/Controllers/CRM/DealController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/DealController.php)

WordPress bridge:

- [app/Services/WalletPayloadService.php](/Users/ian/Projects/exotic-crm/app/Services/WalletPayloadService.php)
- [app/Services/WalletSyncService.php](/Users/ian/Projects/exotic-crm/app/Services/WalletSyncService.php)
- [app/Services/WpSyncService.php](/Users/ian/Projects/exotic-crm/app/Services/WpSyncService.php)
- [app/Services/SubscriptionProvisioningService.php](/Users/ian/Projects/exotic-crm/app/Services/SubscriptionProvisioningService.php)

## 5. Existing testing posture

This repo already has meaningful billing coverage and should be treated as test-first for the refactor.

Representative tests:

- [tests/Feature/PaymentDiagnosticsTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/PaymentDiagnosticsTest.php)
- [tests/Feature/PaymentLinkProviderSettingsTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/PaymentLinkProviderSettingsTest.php)
- [tests/Feature/PaymentLinkProxyRouteTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/PaymentLinkProxyRouteTest.php)
- [tests/Feature/SubscriptionProvisioningConvergenceTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/SubscriptionProvisioningConvergenceTest.php)
- [tests/Feature/WalletSyncPhaseSixTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/WalletSyncPhaseSixTest.php)
- [tests/Feature/LegacyStkRoutingTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/LegacyStkRoutingTest.php)
- [tests/Unit/PaymentLinkServiceTest.php](/Users/ian/Projects/exotic-crm/tests/Unit/PaymentLinkServiceTest.php)

Gap to remember:

- there is no repo-native browser automation suite yet, which is why Phase `0B` includes automation setup before the new Billing workspace expands

## 6. CRM conventions to preserve

- do not bypass service-layer orchestration with controller-only fixes
- preserve sandbox vs live distinctions
- preserve compatibility behavior until explicit cutover
- preserve current operator workflows in `Deals`, `ClientDetail`, and `Payments` while refactoring under the hood
- prefer additive compatibility wrappers over destructive rewrites
- keep new billing abstractions under a clear namespace instead of spreading fresh logic across legacy services

## 7. Billing-program rules for agents

- no runtime billing change before Phase `0A` and `0B` gates close
- no provider adapter work before provider-agnostic routing contracts exist
- hot files such as `Settings.jsx`, `Payments.jsx`, `BillingController.php`, and the runtime billing services are single-writer locked
- WordPress-facing payloads are a contract, not an implementation detail
- diagnostics changes must preserve the split between `Payment Diagnostics` and `Billing Diagnostics`

## 8. What “good” looks like in this repo

Good changes in this codebase:

- keep billing logic explicit and observable
- leave behind tests and diagnostics, not just code
- preserve compatibility intentionally and document when it is removed
- keep UI changes aligned with access control, degraded states, and rollout flags
- treat migration, rollback, and shadow-read evidence as first-class deliverables
