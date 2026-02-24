# Sprint 5 — Tranches 14c through 17

Complete the remaining Sprint 5 work as defined in the [sprint-5-issue-verification-implementation-plan.md](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/docs/sprint-5-issue-verification-implementation-plan.md). Tranches 14a+14b are already implemented in the `rec` worktree; this plan covers the remainder.

> [!IMPORTANT]
> All code changes target the `rec` worktree at `/Users/ian/.cursor/worktrees/exotic-crm/rec/`. The main repo at `/Users/ian/Projects/exotic-crm` is the running dev server but lags behind.

## Proposed Changes

### Tranche 14c — Base URL Follow-Up (Minimal)

Ensure operators know which payment service URL is in use. Option A (read-only display).

#### [MODIFY] [.env.example](file:///Users/ian/Projects/exotic-crm/.env.example)
- Add `DJANGO_API_BASE=` and `PAYMENT_LINK_PATH=/pay` with comments explaining their purpose.

#### [MODIFY] [SettingsController.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Http/Controllers/CRM/SettingsController.php)
- In `integrations()`, add a `payment_service` key to the `services` response:
```php
'payment_service' => [
    'status' => config('services.django.base_url') ? 'connected' : 'pending',
    'base_url' => config('services.django.base_url'),
    'payment_link_path' => config('services.payment_link.path'),
    'note' => 'STK retry and payment initiation use this Django proxy URL.',
],
```

#### [MODIFY] [Settings.jsx](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/resources/js/pages/Settings.jsx)
- In the Integrations tab, add a read-only "Payment Service" card below the KopoKopo card showing Django base URL and payment link path, with a status indicator (connected/pending). No edit/save — env-only.

---

### Tranche 14d — Documentation Update

#### [MODIFY] [sprint-5-tranche-log.md](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/docs/sprint-5-tranche-log.md)
- Mark Tranche 14 as **Completed**. Add verification notes for 14c (Settings read-only display, .env.example updated).

---

### Tranche 15 — Payment-to-Subscription Reconciliation

When a completed payment is matched to a client, offer the option to create and activate a deal from that payment.

#### [MODIFY] [PaymentMatchingService.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Services/PaymentMatchingService.php)
- Add `createDealFromPayment(Payment $payment, int $actorId): Deal` method:
  - Validates payment has `client_id`, `product_id`, `platform_id`, and `status === 'completed'`.
  - Creates a `Deal` from the payment data (client, product, platform, amount, duration → `starts_at = now()`, `expires_at` based on duration).
  - Sets `deal.status = 'active'`, `deal.activated_at = now()`.
  - Links payment: `payment.deal_id = deal.id`.
  - Returns the new deal.

#### [MODIFY] [PaymentQueueController.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Http/Controllers/CRM/PaymentQueueController.php)
- Add `createSubscription(Request $request, Payment $payment)` endpoint:
  - Validates: payment must be `completed` and have `client_id`; `deal_id` must be null (prevent duplicates).
  - Calls `PaymentMatchingService::createDealFromPayment()`.
  - Audit logs with `PAYMENT_CREATE_SUBSCRIPTION`.
  - Returns the new deal and updated payment.
- Modify `confirmMatch()`: after successful match, include a hint in the response (`can_create_subscription: true`) when the payment is completed and has no `deal_id`.

#### [MODIFY] [CrmAuditAction.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Support/CrmAuditAction.php)
- Add `PAYMENT_CREATE_SUBSCRIPTION = 'payment_create_subscription'`.

#### [MODIFY] [api.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/routes/api.php)
- Add `POST /payments/{payment}/create-subscription` → `PaymentQueueController::createSubscription`.

#### [MODIFY] [Payments.jsx](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/resources/js/pages/Payments.jsx)
- After a successful `confirmMatch` response, if `can_create_subscription` is true, show a follow-up dialog: "Create subscription from this payment?" with confirm/dismiss.
- Add a "Create subscription" action button on matched completed payments that lack a `deal_id`.
- Wire to the new `create-subscription` endpoint with mutation, toast, and query invalidation.

---

### Tranche 16 — Renewals Scope Expansion

Include expired deals and client-level expiry in the renewals view.

#### [MODIFY] [RenewalService.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Services/RenewalService.php)
- `buildOverview()` already includes `expired` status in `whereIn('status', ['active', 'expired'])` — confirmed.
- Add an `expired` bucket count to the summary: `(clone $summaryBase)->where('expires_at', '<', now())->count()`.
- Add client-level fallback: when `bucket` filter is `client_expiry`, query `Client` records where `escort_expire IS NOT NULL` and the client has **no active deal** — present these as "at-risk" rows even without a deal.

#### [MODIFY] [RenewalController.php](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/app/Http/Controllers/CRM/RenewalController.php)
- Pass through a new `include_client_expiry` filter to `RenewalService::buildOverview()`.

#### [MODIFY] [Renewals.jsx](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/resources/js/pages/Renewals.jsx)
- Add "Expired" and "Client Expiry" filter options to the bucket selector.
- Display an "expired" summary card alongside existing metrics.
- For client-expiry rows (no deal), show a distinct badge and disable the "Send reminder" action (no deal to attach it to).

---

### Tranche 17 — Queue/Microcopy/Tooltip Clarity

#### [MODIFY] [Dashboard.jsx](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/resources/js/pages/Dashboard.jsx)
- **Payment Review Queue**: Rename title from "Payment Review Queue" to "Completed — Unlinked Payments" and update subtitle to clarify these are completed payments without a matched client, not failed payments.
- **Performance Pulse tooltips**: Add a `title` attribute (or a small `?` icon with tooltip) to each `MetricProgress` bar explaining:
  - *Payment match quality*: "Percentage of completed payments in the selected window that are linked to a client."
  - *Lead backlog pressure*: "Proportion of leads still in 'new' or 'contacted' status vs. total leads."
  - *Active client coverage*: "Share of all client records that have an active (published) profile."

#### [MODIFY] [Payments.jsx](file:///Users/ian/.cursor/worktrees/exotic-crm/rec/resources/js/pages/Payments.jsx)
- Update the "Unmatched" metric card's `meta` text from "needs review" to "completed, no client linked" for clarity.

---

## Verification Plan

### Automated Tests

Existing tests are in [CrmStreamFourAuthorizationTest.php](file:///Users/ian/Projects/exotic-crm/tests/Feature/CrmStreamFourAuthorizationTest.php) (38 tests covering authorization, matching, renewals, leads, clients, roles). New tests to add:

1. **`test_create_subscription_from_matched_completed_payment`** — confirm that a completed, matched payment can create a deal, and the payment's `deal_id` is set.
2. **`test_create_subscription_rejects_unmatched_or_incomplete_payment`** — 422 when payment has no `client_id` or isn't completed.
3. **`test_create_subscription_rejects_duplicate_deal`** — 422 if payment already has a `deal_id`.
4. **`test_retry_stk_rejects_completed_payment`** — 422 for completed status (already covered implicitly by 14a code, but formalizes it).

```bash
php artisan test --filter='create_subscription|retry_stk_rejects' --stop-on-failure
```

### Build Verification
```bash
cd /Users/ian/.cursor/worktrees/exotic-crm/rec && npm run build
```

### Manual Verification
- After starting the dev server, open Settings → Integrations tab and confirm the "Payment Service" card shows the Django base URL (read-only).
- On the Payments page, match a completed payment to a client, then click "Create subscription" → verify a deal is created and visible on the Deals page.
- On the Dashboard, confirm "Completed — Unlinked Payments" heading and hover over Performance Pulse progress bars to see tooltips.
- On the Renewals page, select "Expired" bucket filter and confirm expired deals appear.
