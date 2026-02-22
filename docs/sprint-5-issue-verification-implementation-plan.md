# Sprint 5 — Issue Verification Implementation Plan

**Date:** 2026-02-22  
**Purpose:** Where we are, what’s verified, what’s not done, and how to proceed (tranche-by-tranche). No assumptions; references plans folder and tranche log.

---

## 1) Source documents (read for full context)

| Document | Location | Use |
|----------|----------|-----|
| **PRD** | `/Users/ian/Local Sites/exotic/app/public/plans/Sales CRM Technical PRD.md` | Product context, payment flow, two till numbers, Django vs KopoKopo |
| **Architecture** | `/Users/ian/Local Sites/exotic/app/public/plans/Sales CRM System Architecture.md` | As-built flows, STK push chain, CRM ↔ WP ↔ Django ↔ KopoKopo |
| **Issue verification (issues we are working on)** | `/Users/ian/Local Sites/exotic/app/public/plans/Sales CRM Issue Verification - WP DB, Payments, Subscriptions - 2026-02-21.md` | Verified findings, root causes, feasibility list |
| **Reconciliation backlog** | `/Users/ian/Local Sites/exotic/app/public/plans/Sales CRM Full Feedback Reconciliation Plan & Git Issue Backlog - 2026-02-20.md` | CRM-5xx issue status, feedback matrix |
| **Progress log** | `docs/sprint-5-tranche-log.md` (this repo) | Completed tranches 1–13, verification, decisions |

---

## 2) Where we are (current state)

### 2.1 Tranche progress

- **Tranches 1–13:** Completed (see `docs/sprint-5-tranche-log.md`).
- **Next:** Implementation of the **Issue Verification** items, in the order agreed:
  1. Failed-payment recovery (send payment link + **KopoKopo M-Pesa STK retry** for Kenyan market + provider settings).
  2. **KopoKopo base URL in Settings** — “base URL not configured” follow-up.
  3. Payment-to-subscription reconciliation (create/activate subscription from matched completed payments).
  4. Renewals scope expansion (historical/expired/private lifecycle).
  5. Queue/microcopy/tooltip clarity (including Performance Pulse).

### 2.2 What is already verified (no-code audit)

From **Sales CRM Issue Verification - WP DB, Payments, Subscriptions - 2026-02-21.md**:

- **Private escorts missing in Renewals/Subscriptions:** Renewals are deal-driven; WP sync `/expiring` is publish-only; private profiles without deals are excluded.
- **Payments (50 completed) vs subscriptions (2):** Matching sets `client_id` only; it does not create/activate a deal. Deals come from successful payment webhook flow only.
- **“Payment Review Queue” = completed but unlinked:** By design (`status=completed` AND `client_id IS NULL`). Not “failed”; copy can be clarified.
- **Failed-payment actions today:** Only “Auto-match” and “Match manually”. No “Send payment link”, no “Retry STK”, no send-URL actions.
- **Manual match:** Works once user searches; empty state until search can be improved.
- **Performance Pulse:** No tooltip yet; request is valid.
- **Failed-payment export:** Done (`failed-payment-breakdown-2026-02-21.tsv`). Many rows lack structured failure metadata (observability gap).

### 2.3 Code state (as of this plan)

| Area | State | Notes |
|------|--------|------|
| **Payment initiation (production)** | Django proxy only | `PaymentController::initiate()` uses `config('services.django.base_url')` → Django → KopoKopo. No direct CRM → KopoKopo STK in main flow. |
| **KopoKopo direct STK** | Exists, not used for CRM retry | `KopokopoService::initiateStkPush()` exists; used in debug path. Not exposed as “Retry STK” from Payments UI. |
| **KopoKopo config** | Env-only, read-only | `config('services.kopokopo.*')` and `config('kopokopo.*')`. Settings API returns status + base_url + till_number. When `KOPOKOPO_BASE_URL` unset → UI shows “Base URL not configured”. |
| **KopoKopo in Settings** | No save/test from UI | No `PATCH .../integrations/kopokopo` or `POST .../integrations/kopokopo/test`. No `integration_settings` key for KopoKopo (SMS uses it in Tranche 11). |
| **Payments.jsx row actions** | Auto-match, Match manually only | Same two actions for all rows. No status-conditional “Send link” or “Retry STK” for failed payments. |
| **integration_settings** | Exists; used for SMS only | Table + `IntegrationSetting` model; `NotificationService` read/write for SMS. No KopoKopo key yet. |

**Previous session note:** “Implementing backend support for editable KopoKopo runtime config in integration_settings and Settings API endpoints” — that work is **not** present in this codebase: no KopoKopo update/test routes, no integration_settings for KopoKopo, `KopokopoService` still reads only `config()`.

---

## 3) How to proceed (tranche order)

### Tranche 14 (recommended next): Failed-payment recovery (reuse existing STK push)

**Goal:** Add failed-payment recovery including **Retry STK** for Kenyan market, **without** adding new KopoKopo configuration. Use the existing STK push path (Django proxy) that already works.

**Decision — use existing STK push code:** The production flow is Laravel → Django proxy → KopoKopo → M-Pesa. Both `PaymentController::initiate()` and `manualStkPush()` call `POST {config('services.django.base_url')}/initiate/` with payment_id, phone, amount, product_id, platform_id, user_id, duration. For **Retry STK** we reuse this same path: resend to Django with the **existing** payment row’s id and data so the callback continues to update the same payment. No new KopoKopo config in CRM; Django already holds KopoKopo config. The “base URL not configured” in Settings refers to KopoKopo read-only display; fixing that can be limited to ensuring `DJANGO_API_BASE` (or the URL used for initiate) is set in env and optionally surfacing it in Settings as read-only or a single optional override later—not a full KopoKopo settings workspace.

**14a — Retry STK (reuse Django initiate)**

- **Backend**
  - New CRM endpoint e.g. `POST /api/crm/payments/{payment}/retry-stk` (scoped by market, with reason for audit). Load payment (status must be `failed` or `initiated`); load product/platform; compute amount from payment’s duration/product; normalize phone. Set payment `status = 'initiated'` (or keep as-is if Django expects it). Call the **same** Django initiate URL: `POST {config('services.django.base_url')}/initiate/` with payload: `payment_id` = **existing** payment id, same shape as `initiate()` / `manualStkPush()` (phone, amount, product_id, platform_id, user_id, duration, optional first_name/last_name/email from payment or client). No new payment row; callback will still receive this payment_id and update the same row. Optional: idempotency/rate limit (e.g. one retry per payment per N minutes).
  - Reuse existing `services.django.base_url`; no KopoKopo-specific config in CRM for this flow.
- **Frontend**
  - Payments table: for rows with `status === 'failed'` or `status === 'initiated'`, add action “Retry STK”. Confirm dialog with optional reason → call retry-stk API → toast; refresh row/query.
- **Verification**
  - Retry STK triggers Django → KopoKopo → M-Pesa; callback updates same payment row; no duplicate payment records.

**14b — Send payment link**

- **Backend**
  - Endpoint e.g. `POST /api/crm/payments/{id}/send-payment-link` (scope by market, optional channel: sms/email). Use payment’s product/amount; generate or use payment URL (e.g. WP checkout or deep link that can trigger STK). Integrate with `NotificationService` for SMS.
- **Frontend**
  - For failed (and optionally initiated) payments, add “Send payment link” action; modal (channel, optional phone) → confirm → toast.
- **Provider settings:** If “payment link” URL template or provider URLs (Pesapal/Paystack) are needed, add minimal config (env or one integration_settings key) when required; not part of a new KopoKopo configuration.

**14c — “Base URL not configured” (minimal follow-up)**

- **Option A:** Document that STK (including retry) uses Django; ensure `DJANGO_API_BASE` (or `services.django.base_url`) is set in `.env.example` and that Settings displays it read-only so operators know what is in use. No editable KopoKopo section.
- **Option B:** If product explicitly wants an editable base URL for the **Django** payment service (not KopoKopo), add a single optional override in Settings (e.g. one field in integration_settings) with fallback to env; keep a single “payment service URL” concept, not a full KopoKopo workspace.

**14d — Documentation and tranche log**

- Update `docs/sprint-5-tranche-log.md` with Tranche 14, verification steps, and decision: “Retry STK reuses existing Django initiate flow; no new KopoKopo configuration in CRM.”

---

### Tranche 15: Payment-to-subscription reconciliation

- On manual/auto match for **completed** payments: offer “Create subscription from payment” (create/activate deal from that payment, link payment to deal).
- Backend: endpoint or flag on confirm-match to create deal and set `deal_id` on payment; reuse existing activation logic where applicable.
- Frontend: after successful match, optional step or checkbox “Create subscription from this payment” and wire to new flow.

### Tranche 16: Renewals scope expansion

- Include historical/expired and optionally client-level expiry when deal is absent (`clients.escort_expire`).
- `RenewalService` and WP sync `/expiring` contract may need to be extended (e.g. include private with expiry for visibility only).

### Tranche 17: Queue/microcopy/tooltip clarity

- Rename or clarify “Payment Review Queue” to “Completed but Unlinked” (or similar) so it’s clear these are not failed.
- Add Performance Pulse tooltip and any other dashboard microcopy improvements.

---

## 4) Assumptions we are not making

- That we add a new KopoKopo configuration layer in CRM; we **reuse the existing STK push path** (Django proxy) for Retry STK.
- That payment link URL is a single global; it may be per-platform or per-product later.
- That Tranche 14 is a single PR; it can be split into 14a (Retry STK), 14b (Send link), 14c (base URL follow-up) for smaller reviews.

---

## 5) Quick reference — key code paths

| Concern | File(s) |
|--------|---------|
| Payment initiate (Django) | `app/Http/Controllers/API/PaymentController.php` :: `initiate()` |
| KopoKopo STK | `app/Services/KopokopoService.php` :: `initiateStkPush()` |
| KopoKopo config (read) | `config/services.php`, `config/kopokopo.php`; `SettingsController::integrations()` → `services.kopokopo` |
| SMS integration_settings pattern | `app/Services/NotificationService.php` (save/read); `SettingsController::updateSmsProvider()` |
| Payments UI actions | `resources/js/pages/Payments.jsx` (columns.actions) |
| Integration settings table | `database/migrations/2026_02_21_000016_create_integration_settings_table.php`; `app/Models/IntegrationSetting.php` |

---

## 6) Summary

- **Where we are:** Tranches 1–13 done. Issue verification doc and backlog define the next work. No failed-payment “Send link” or “Retry STK” in the CRM yet.
- **Approach:** Use **existing STK push code** (Django proxy) for Retry STK; do not add a new KopoKopo configuration in CRM.
- **Next step:** Execute **Tranche 14** — (14a) Retry STK via existing Django initiate, (14b) Send payment link, (14c) minimal “base URL not configured” follow-up if needed, (14d) update tranche log. Then Tranches 15–17 as above.
