# Subscription Provisioning Branch Map

This document captures the activation branches that existed before Phase 3 convergence and the shared path they must now use.

## `DealController::activate()`

- `payment_method=manual`
  - Create a completed payment linked to the existing deal.
  - Activate the existing deal in WordPress.
  - Sync the client back into CRM.
  - Mark the deal active and emit CRM activation timelines.

- `payment_method=free_trial`
  - Do not create a payment.
  - Activate the existing deal in WordPress.
  - Sync the client back into CRM.
  - Mark the deal active with free-trial metadata and emit CRM activation timelines.

- `payment_method=stk`
  - Create an initiated payment linked to the existing deal.
  - Move the deal to `awaiting_payment`.
  - Return the payment payload and wait for provider callback or manual confirmation.

- `payment_method=link`
  - Create an initiated payment linked to the existing deal.
  - Move the deal to `awaiting_payment`.
  - Return the payment payload and wait for provider callback or manual confirmation.

## Completed payment provisioning callers

- `PaymentController::handleSuccessfulPayment()`
  - Provider callback or manual payment-status recovery path.
  - If the payment is already linked to a pending CRM deal, activate that deal.
  - If the payment is matched to a client but has no deal yet, create and activate a new deal.

- `PaymentQueueController::createSubscription()`
  - Operator-driven creation from a matched completed payment.
  - Must use the same provisioning path as provider callbacks.

- `PaymentMatchingService::createDealFromPayment()`
  - Auto-create path after reconciliation.
  - Must delegate to the same provisioning path instead of activating deals independently.

## Phase 3 target

All of the branches above must pass through `SubscriptionProvisioningService`.

The service owns:

- choosing whether to activate an existing deal or create a new one from a completed payment
- WordPress activation via `WpSyncService`
- client re-sync via `ClientSyncService`
- CRM deal/payment linkage updates
- shared activation timeline events

The service does not own caller-specific audit log entries or user-facing response messages.
