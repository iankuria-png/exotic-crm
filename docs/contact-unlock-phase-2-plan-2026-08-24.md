# Contact Unlock Phase 2 Plan

Date: 2026-08-24  
Status: Planning artifact after WordPress + CRM + billing discovery  
Scope: Visitor-paid contact unlock for SEO-preserved inactive profiles

## Executive Summary

Phase 1 keeps expired profiles published for SEO while hiding contact details and limiting owner edits. Phase 2 should monetize those inactive profile pages by allowing website visitors to pay to unlock contact details without reactivating the advertiser subscription.

The core architectural rule is separation:

- Advertiser subscription payments remain subscription payments.
- Visitor contact unlock payments become a new revenue stream.
- Unlock payments must not create deals, activate profiles, extend `escort_expire`, affect wallet balances, or count as subscription revenue.

Recommended implementation:

- `payments.purpose = visitor_contact_unlock`
- `BillingSurface::ContactUnlock`
- dedicated `visitor_contact_unlocks` entitlement table
- dedicated checkout and fulfillment services
- dedicated CRM accounting/reporting surface
- WordPress reveal endpoint guarded by server-side entitlement checks

## Confirmed Product Decisions

1. One-time unlock is for one profile only by default.
   - This must remain configurable in CRM settings, so the business can later switch to alternate scopes such as one market, one city, or a bundle.

2. Subscription unlock grants access to all inactive contacts.
   - The subscription entitlement should cover inactive/restricted profiles for the configured market scope.
   - Active profiles should continue to behave normally and should not require unlock.

3. Initial rollout markets:
   - Kenya using the existing KopoKopo rail.
   - Existing pawaPay-configured markets in CRM.
   - Manual/offline payment methods remain out of scope for this phase.

## Phase 1 Flow Map

### CRM Lifecycle

`ExpiredSubscriptionReconciler` is the central expiry transition service. It finds published profiles whose synced `escort_expire` has passed and whose market is configured for SEO lifecycle preservation.

When `platform.lifecycle_policy_enabled` is true, CRM calls `WpSyncService::setLifecycleState($postId, expired)` instead of taking the WordPress post private.

When the lifecycle policy is false, CRM preserves the legacy path and deactivates the WordPress profile.

### WordPress Sync Plugin

The `exotic-crm-sync` plugin exposes:

- `POST /wp-json/exotic-crm-sync/v1/clients/{post_id}/lifecycle`
- `GET|POST /wp-json/exotic-crm-sync/v1/lifecycle-policy`
- client sync payloads that include `crm_lifecycle_state`, `needs_payment`, `notactive`, `escort_expire`, phone, and email

For `expired` or `archived`, the lifecycle endpoint:

- keeps `post_status=publish`
- writes `crm_lifecycle_state`
- clears premium/featured exposure flags
- preserves SEO URL/content

### Child Theme

The child theme reads `crm_lifecycle_state` only. It intentionally does not infer lifecycle state from `escort_expire`, because that would accidentally enable the policy in markets that have not opted in.

Restricted states:

- `expired`: published, indexed, contact hidden, edit locked, can remain in listings
- `archived`: published, indexed, contact hidden, edit locked, excluded from city/category listings

Contact details are hidden in profile cards, hero CTAs, sticky/floating CTAs, and lower contact panels.

## Phase 1 Gaps To Fix Before Phase 2

These should be handled before monetizing unlocks:

1. Guard dedicated edit pages.
   - Some edit templates and form processors appear to allow owner edits without checking `crm_lifecycle_state`.
   - Add a reusable child-theme guard so expired/archived profiles cannot be edited by profile owners or agency users outside the display page.
   - Do not block WordPress admins or CRM-authorized admin operators from legitimate profile updates; admin updates are part of support and recovery operations.
   - Guard server-side POST handlers, not only UI links. Known surfaces to audit and harden:
     - `wp-content/themes/escortwp-child/template-edit-profile.php`
     - `wp-content/themes/escortwp-child/register-independent-edit-personal-information.php`
     - `wp-content/themes/escortwp-child/register-independent-personal-info-process.php`
     - `wp-content/themes/escortwp-child/register-independent-manage-my-tours-process-data.php`
     - `wp-content/themes/escortwp-child/ajax/save-tour.php`
     - `wp-content/themes/escortwp-child/ajax/edit-tour.php`
     - `wp-content/themes/escortwp-child/ajax/delete-tour.php`
     - `wp-content/themes/escortwp-child/template-photos.php`
     - `wp-content/themes/escortwp-child/template-videos.php`
     - `wp-content/themes/escortwp-child/template-tours.php`
     - `wp-content/themes/escortwp-child/template-profile.php` owner publish/private toggles
     - media upload/delete/set-main AJAX handlers reachable from restricted profile owners
     - `wp-content/themes/escortwp-child/single-profile.php` profile actions such as `settoprivate`, hero media preference, agency/admin profile save, tour save, and media management
   - The guard should be expressed as a reusable helper such as `escortwp_child_can_edit_lifecycle_restricted_profile($post_id, $user_id)`, returning true for WordPress admins and false for owners/agencies while restricted.

2. Block generic lifecycle/status bypasses.
   - The generic WordPress client update endpoint should move from broad meta acceptance to a positive allowlist for ordinary profile content fields. Anything outside the allowlist must be rejected by default.
   - At minimum reject `crm_lifecycle_state`, direct `post_status` transitions that bypass lifecycle side effects, `notactive`, `needs_payment`, renewal flags, subscription dates, package/current-duration fields, premium/featured flags, owner/user reassignment, verification/status fields, and public availability controls.
   - Lifecycle changes should only happen through the canonical lifecycle endpoint so side effects stay consistent.
   - This restriction is about preventing lifecycle state mutation through generic profile-update payloads. It must not prevent CRM admins from editing ordinary profile content/metadata through approved CRM update paths.
   - The WordPress sync-token layer cannot know CRM roles. CRM must authorize admin/operator update intent before it calls WordPress; WordPress should enforce field-level safety, while CRM enforces role/workflow permission.
   - CRM should add `crm_lifecycle_state` to the existing `ClientController::updateWpProfile` blocklist alongside `premium`, `featured`, `escort_expire`, `profile_status`, `needs_payment`, and `notactive`.

3. Replace inactive copy for visitor context.
   - Current copy says the advertiser should renew.
   - Visitor-facing inactive profiles should show `Unlock contact details` and explain that the profile is inactive and may not respond.

4. Confirm no contact leakage.
   - Re-check all template branches, tours, snippets, schema, JSON-LD, analytics payloads, caches, public engagement/invite endpoints, and old mobile surfaces for raw phone/social exposure.
   - Known item to fix: the profile floating action button area can render raw Telegram URL even though `$has_telegram_action` is restricted earlier. Audit and gate the later render path too.
   - Known item to fix: public engagement invite routes can return a raw-profile-phone WhatsApp URL. Restricted profiles must not expose phone/social contact through invite creation or invite status payloads.

## Phase 2 Target Experience

The inactive profile page should become a simple unlock flow:

1. Visitor sees inactive notice and a clear `Unlock contact` CTA.
2. Modal opens as a short stepper.
3. Visitor chooses:
   - one-time profile unlock
   - subscription for all inactive contacts
4. Visitor enters phone/email.
5. Visitor selects available payment rail.
6. CRM initiates KopoKopo STK or pawaPay checkout/deposit.
7. WordPress shows a pending state with polling.
8. Provider webhook confirms payment.
9. CRM grants entitlement.
10. WordPress reveals contact via a no-cache entitlement endpoint.

The UI should not optimistically show contact details after STK dispatch. It only unlocks after provider-confirmed completion.

## UX Requirements

### Profile Page CTA

Replace the generic inactive notice with:

- clear status: `This profile is inactive`
- business-safe warning: contact details are hidden because the advertiser subscription expired
- visitor action: `Unlock contact`
- low-friction promise: mobile money, quick unlock after payment confirmation

### Modal Structure

Use a 4-step modal:

1. Access
   - One-time unlock: this profile
   - Subscription: all inactive contacts

2. Details
   - Phone number for STK/payment
   - Email optional for receipt/recovery

3. Payment
   - Available rails from CRM market configuration
   - KopoKopo for Kenya
   - pawaPay for configured markets

4. Unlock
   - Waiting for payment
   - Payment failed/retry
   - Unlocked
   - Receipt/reference

### Important States

Build explicit states for:

- loading pricing
- market unsupported
- profile no longer inactive
- payment initiated
- STK prompt sent
- payment pending
- payment completed
- payment failed
- entitlement expired
- already unlocked
- subscription active
- refund/revocation

### Accessibility And Performance

- Modal must trap focus and restore focus on close.
- Buttons need clear labels, not generic `Continue`.
- Polling should have bounded retries and visible progress.
- No layout shift in profile hero/contact sections.
- No contact data in cached HTML.

## Domain Model

### New CRM Table: `visitor_contact_unlocks`

Suggested columns:

- `id`
- `platform_id`
- `client_id` nullable for subscription-wide entitlement
- `wp_post_id` nullable for subscription-wide entitlement
- `payment_id` nullable until payment creation completes
- `scope` enum: `single_profile`, `market_inactive_profiles`
- `status` enum: `initiated`, `pending_payment`, `active`, `expired`, `revoked`, `refunded`, `failed`
- `visitor_phone_hash`
- `visitor_phone_masked`
- `visitor_email_hash` nullable
- `visitor_email_masked` nullable
- `idempotency_key_hash`
- `session_token_hash`
- `public_token_hash`
- `starts_at`
- `expires_at`
- `last_revealed_at`
- `reveal_count`
- `metadata_json`
- timestamps

Indexes:

- `platform_id, scope, status`
- `payment_id`
- `client_id, status`
- `wp_post_id, status`
- `visitor_phone_hash, status`
- `public_token_hash`
- unique `idempotency_key_hash`
- unique `public_token_hash`
- unique active entitlement guard for `scope + platform_id + wp_post_id/client_id + visitor identity` where supported by the database; if MySQL partial indexes are not practical, enforce inside `ContactUnlockCheckoutService` under transaction/lock.

Public tokens are not enough by themselves. A reveal should require both the public unlock token and a short-lived browser session proof whose hash is stored on the entitlement/intent. Subscription entitlements use the same session proof to work across inactive profiles in the configured market.

### New CRM Table: `contact_unlock_pricing_rules`

Pricing must be configurable from the first implementation, so this is not optional for Phase 2. Store pricing in a first-class table rather than hardcoding values in services.

- `id`
- `platform_id`
- `scope`
- `label`
- `currency`
- `amount`
- `duration_days`
- `is_active`
- `provider_policy_json`
- `rate_limit_json`
- `starts_at` nullable
- `ends_at` nullable
- timestamps

Admin UI should read/write this table through CRM Settings or the Contact Unlocks workspace. Cache pricing only with short TTL and invalidate on settings save.

### Payment Record

Use existing `payments` with:

- `purpose = visitor_contact_unlock`
- `source = website_unlock`
- `provider_key = kopokopo|pawapay`
- `provider_environment`
- `payment_data.billing_surface = contact_unlock`
- `payment_data.unlock_id`
- `payment_data.unlock_scope`
- `payment_data.wp_post_id`
- `payment_data.client_id`
- `payment_data.idempotency_key`

Also populate first-class payment columns where they already exist:

- `escort_post_id = wp_post_id` for single-profile unlocks
- `client_id = client_id`
- `platform_id`
- `amount`
- `currency`
- `provider_key`
- `provider_environment`

Do not set subscription lifecycle fields for unlock payments.

### Visitor PII Handling

Provider initiation may require a raw phone number for STK/deposit dispatch, but the unlock entitlement model should not store raw visitor contact data.

Rules:

- normalize visitor phone before provider initiation
- pass raw phone only to the provider initiation service that requires it
- store `visitor_phone_hash` and `visitor_phone_masked` on `visitor_contact_unlocks`
- store email only as `visitor_email_hash` and `visitor_email_masked` unless receipts are explicitly enabled later
- redact visitor phone/email from `PaymentAttempt` request/response metadata and application logs
- avoid copying raw phone/email into provider transaction metadata when a provider reference can be used instead
- if existing provider compatibility requires `payments.phone`, store it only for the minimum reconciliation window and then purge/mask it after terminal success/failure/refund
- if purge is not acceptable for finance reconciliation, migrate to encrypted-at-rest storage for `payments.phone` before launch

Accounting and support views should display masked visitor identifiers plus provider references, not raw contact data.

### Raw Provider Payload Handling

Webhook and provider payloads are useful for reconciliation, but they may contain visitor phone numbers or other identifiers.

Rules:

- store raw provider/webhook payloads encrypted at rest when retaining the original body is required for audit
- maintain a redacted JSON projection for normal CRM diagnostics and support views
- redact phone/email/customer identifiers before writing application logs
- never expose raw payloads in exports by default
- apply a retention window for raw encrypted payloads, then keep only provider reference, canonical status, amount, currency, timestamps, masked identifiers, and hashes
- pawaPay and KopoKopo provider transaction records should store provider IDs/references as first-class searchable fields, with customer phone/email masked or encrypted
- `payments.raw_payload`, provider `payload_json`, and webhook `raw_body` fields must be classified as sensitive for this purpose

## CRM Services

### `ContactUnlockPricingService`

Responsibilities:

- resolve market pricing
- resolve one-time vs subscription scope
- validate market support
- return provider eligibility
- expose a versioned pricing/config payload to WordPress

### `ContactUnlockCheckoutService`

Responsibilities:

- validate profile is eligible
- create or replay an idempotent unlock intent
- create `payments` row with `purpose=visitor_contact_unlock`
- route through billing provider infrastructure
- initiate KopoKopo or pawaPay payment
- return frontend action payload

Do not call the existing wallet `BillingGatewayService::initiateTopup()` path for contact unlocks. It currently hardcodes wallet top-up purpose/source/surface metadata. Add a dedicated contact unlock initiation method or refactor provider initiation into a purpose-aware lower-level method used by both wallet top-up and contact unlock flows.

The contact unlock initiation path must set:

- `purpose=visitor_contact_unlock`
- `source=website_unlock`
- `billing_surface=contact_unlock`
- unlock ID/scope/profile metadata
- provider references suitable for reconciliation without wallet ledger side effects

### `ContactUnlockFulfillmentService`

Responsibilities:

- receive completed payment
- activate entitlement
- set `starts_at` and `expires_at`
- record timeline/audit event
- never activate profile subscription
- never create a deal
- never update WordPress `escort_expire`

### `ContactUnlockRevealService`

Responsibilities:

- validate token/session/identity
- check entitlement status
- check profile state before reveal
- return contact details with no-cache headers
- increment reveal audit fields
- rate-limit access

If a paid-for inactive profile becomes active again, the visitor's entitlement remains valid until its configured expiry. The reveal service may return the same contact through the entitlement endpoint or tell WordPress to use the normal active-profile contact UI. Do not auto-refund merely because the advertiser later renewed.

### CRM WordPress Service API Contract

WordPress should call CRM through the same service-auth pattern used by the existing SEO endpoints, not through provider-specific secrets stored in WordPress.

Add signed service routes under `routes/api.php`:

```php
Route::middleware(['wp.service.auth'])
    ->prefix('wp-svc/contact-unlock')
    ->group(function () {
        Route::post('/config', [ContactUnlockController::class, 'config']);
        Route::post('/intents', [ContactUnlockController::class, 'createIntent']);
        Route::post('/status', [ContactUnlockController::class, 'status']);
        Route::post('/reveal', [ContactUnlockController::class, 'reveal']);
    });
```

Auth headers:

- `X-Exotic-CRM-Sync-Key`
- `X-Exotic-Platform-Id`
- `X-Exotic-Timestamp`
- `X-Exotic-Signature`

Use the existing HMAC convention unless the middleware is versioned later: `hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedKey)`.

Generalize the current `wp.service.auth` middleware/config so contact unlock is not tied to SEO-only naming or allowlists. Use shared platform service auth configuration, or introduce a contact-unlock-specific allowlist/key setting that keeps the same HMAC verification semantics while returning contact-unlock-appropriate authorization errors.

Write routes also require an idempotency key:

- `X-Idempotency-Key` for `POST /intents`
- stored as a hash on the unlock intent/payment
- replaying the same key returns the original unlock/payment response

Required payloads:

- `POST /config`: `wp_post_id`, optional visitor country/locale hints
- `POST /intents`: `wp_post_id`, `scope`, `pricing_rule_id`, `provider_key`, `visitor_phone`, optional `visitor_email`, browser session proof
- `POST /status`: `public_token`, browser session proof, `target_wp_post_id`
- `POST /reveal`: `public_token`, browser session proof, `target_wp_post_id`

Required responses:

- config: enabled state, market, restricted profile state, supported scopes, pricing rules, provider options, terms copy, polling interval
- intent: unlock reference, public token, payment reference, provider action payload, status
- status: canonical payment status, entitlement status for the requested target profile, retry/failure reason, expiry if active
- reveal: contact methods for the requested target profile only after entitlement is active, with `Cache-Control: no-store`

The controller must resolve the platform from the authenticated `wp.service.auth` middleware attributes, not from untrusted request body fields. For `single_profile` entitlements, `target_wp_post_id` must match the paid profile. For `market_inactive_profiles` subscription entitlements, CRM must validate that the target profile belongs to the entitled platform/market and is currently lifecycle-restricted before revealing contact.

### CRM Admin Role Matrix

Do not harden Phase 1 by blocking legitimate admin support work. Harden by action and field.

- WordPress administrator: may edit restricted profiles directly in WordPress.
- CRM `admin`: may perform approved profile/content/media updates, lifecycle actions, contact unlock settings, refunds, and revocations.
- CRM `sub_admin`: may perform approved profile/content/media support updates, but cannot change contact unlock pricing, refund/revoke entitlements, or mutate lifecycle/control fields unless explicitly granted later.
- CRM `sales` and `field_sales`: keep existing ordinary profile/content/media update workflows, but cannot write lifecycle/control fields, force activation, alter subscription/payment fields, or override restricted-profile locks.
- CRM `marketing`: read-only for profile sync surfaces unless existing code grants a narrower marketing action.
- Profile owners and agency users: cannot edit expired/archived profiles while lifecycle restricted.

The CRM `PATCH /clients/{client}/wp-profile` path should remain usable for approved support updates, with field-level blocklists protecting lifecycle, subscription, payment, and public availability fields.

Refund and entitlement revocation are admin-only in v1.

## Billing Integration

### Add Billing Surface

Add:

```php
case ContactUnlock = 'contact_unlock';
```

Provider routing should support:

- KopoKopo for Kenya
- pawaPay for configured pawaPay markets

This requires more than enum work:

- provider adapters must advertise/support the `ContactUnlock` surface
- `billing_routing_rules` / active provider bindings must allow `billing_surface=contact_unlock`
- initiation metadata must carry `billing_surface=contact_unlock`, not the current wallet top-up metadata defaults
- pawaPay hosted-checkout/deposit metadata must include unlock ID/scope without marking the payment as wallet funding
- KopoKopo STK metadata must include unlock ID/scope and keep the callback URL on the billing callback path

### Feature Flag And Rollback

Add explicit CRM feature flags before any public UI is enabled:

- `CONTACT_UNLOCK_ENABLED`
- `CONTACT_UNLOCK_MARKET_IDS` or equivalent per-market DB setting
- `CONTACT_UNLOCK_SANDBOX_ONLY` for pilot mode

Rollback behavior:

- disabling the flag hides WordPress unlock CTAs
- new checkout intent creation returns a clear disabled response
- existing paid entitlements remain revealable unless manually revoked
- webhooks continue to process existing pending payments so visitors are not stranded
- CRM Contact Unlocks page remains visible to admins for audit/reconciliation

### Avoid Subscription Side Effects

Add explicit `purpose === subscription` guards to:

- `SubscriptionProvisioningService` and every caller that activates profiles/deals from payments
- `PaymentMatchingService` auto-match and auto-create-subscription branches
- `PaymentQueueController::createSubscription`
- `PaymentQueueController` manual approve/verify/confirm actions that can create deals
- `ManualPaymentSubmissionService`
- `ManualPaymentBundleService`
- legacy public `PaymentController` callback/update/manual routes that currently treat successful non-wallet payments as subscriptions
- revenue mix widgets and queue filters that currently treat all non-wallet payments as subscription revenue

Regression requirement: each listed path needs a test proving `purpose=visitor_contact_unlock` cannot create/activate an advertiser subscription, deal, wallet transaction, or WordPress package renewal.

### Webhook Completion

Webhook processing must:

- verify provider signatures where supported
- persist raw webhook event
- dedupe by provider event/transaction ID
- reduce upstream status to canonical internal state
- complete payment only on final successful state
- call contact unlock fulfillment for `visitor_contact_unlock`

Provider-specific must-haves:

- `BillingGatewayService::handleMpesaCallback` must accept `visitor_contact_unlock` in addition to `wallet_topup` and `subscription`, then route fulfillment by purpose.
- pawaPay callback completion must also dispatch unlock fulfillment when the payment purpose is `visitor_contact_unlock`.
- generic `PaymentCompletionService` should remain purpose-aware and must not assume unlock payments are subscriptions.

## WordPress Plugin Integration

Add REST endpoints under `exotic-crm-sync/v1` or a dedicated namespace if cleaner:

- `GET /contact-unlock/config?post_id=...`
- `POST /contact-unlock/intents`
- `POST /contact-unlock/status`
- `POST /contact-unlock/reveal`

Rules:

- Do not reveal contact in initial page HTML.
- Do not store provider secrets in WordPress.
- WordPress calls CRM for checkout and entitlement state.
- Reveal endpoint must send `Cache-Control: no-store`.
- Return only the contact methods the profile actually has.
- The reveal response must be a dynamic endpoint and must never be embedded into page caches, schema, Open Graph tags, or analytics payloads.

These WordPress endpoints are proxy endpoints only:

- receive browser requests from the theme
- validate nonce/rate limits/basic profile eligibility locally
- sign server-to-server requests to CRM with the same HMAC pattern as `class-seo-endpoint.php`
- pass CRM responses back to the browser after removing any internal fields
- never store KopoKopo/pawaPay credentials
- never calculate fulfillment or grant entitlement locally

Expected proxy mapping:

- WordPress `GET /contact-unlock/config?post_id=...` -> CRM `POST /api/wp-svc/contact-unlock/config`
- WordPress `POST /contact-unlock/intents` -> CRM `POST /api/wp-svc/contact-unlock/intents`
- WordPress `POST /contact-unlock/status` with `post_id` in the body -> CRM `POST /api/wp-svc/contact-unlock/status` with `target_wp_post_id`
- WordPress `POST /contact-unlock/reveal` with `post_id` in the body -> CRM `POST /api/wp-svc/contact-unlock/reveal` with `target_wp_post_id`

All contact-unlock proxy responses that carry session, token, payment, provider-action, entitlement, or contact state must include no-store headers at the WordPress layer too. This includes config, intent, status, and reveal responses.

Guest security rules:

- these are public visitor endpoints, so do not depend on logged-in WordPress admin nonces
- unlock/session tokens must never be placed in URLs, query strings, analytics events, referrers, or browser history
- `config` should issue or refresh a short-lived unlock session token stored in a `Secure`, `HttpOnly`, `SameSite=Lax` cookie when the site is HTTPS
- `intents`, `status`, and `reveal` must forward a proof derived from that session token to CRM
- CRM stores only `session_token_hash`
- reveal requires token + session proof + active entitlement
- default session TTL: 30 minutes idle, 24 hours absolute maximum for pending payments, bounded by entitlement expiry after activation
- token/session replay from a different session hash should be rejected and audited, not used to rotate ownership
- users who lose the session before payment completion can restart checkout with the same phone; idempotency prevents duplicate in-flight provider charges where the same idempotency key is replayed
- CORS should stay same-origin
- rate limits should combine IP, profile, phone hash, public token, and platform

## Child Theme Integration

All theme edits must stay in `wp-content/themes/escortwp-child/`.

Profile page changes:

- replace inactive notices with unlock CTA
- add modal shell
- enqueue unlock JS/CSS only on profile pages where `crm_lifecycle_state` is restricted
- preserve SEO content, headings, title/meta/schema, internal links, and profile URL
- ensure admin preview still works
- fix known contact leakage in later floating/FAB render paths, especially Telegram/social URLs

Suggested files:

- `single-profile.php`
- `functions.php`
- `css/profile.css` or a new scoped `css/contact-unlock.css`
- `js/contact-unlock.js`

## CRM UI And Accounting

Create a separate CRM revenue surface so unlocks never blend into subscriptions.

Navigation:

- Revenue
  - Subscriptions
  - Payments
  - Contact Unlocks

Contact Unlocks page:

- KPIs: gross, net, fees, successful unlocks, failed, refunded, active subscriptions
- filters: market, provider, scope, status, date, environment, test/live
- table rows: unlock reference, profile/client, visitor masked phone/email, amount, provider, payment status, entitlement status, reveal count
- export
- reconciliation state
- refund/revocation controls for admin roles
- diagnostics drawer using billing provider transaction/webhook records

Existing Payments and Subscription reports:

- add explicit filters/exclusions so unlock payments do not appear in subscription lifecycle totals
- update any `non_wallet` grouping to split at least `subscription`, `wallet_topup`, and `visitor_contact_unlock`
- keep unlock rows visible in general payment audit only when clearly labelled as contact unlock revenue
- exports must include `purpose`, `billing_surface`, `unlock_scope`, and entitlement status

Shared query contracts:

- add a `Payment::subscriptionRevenue()` scope for advertiser subscription revenue only
- add a `Payment::contactUnlockRevenue()` scope for visitor unlock revenue only
- replace successful-non-wallet revenue assumptions in dashboards, AI metrics snapshots, reporting views, payment queue summaries, exports, and reconciliation queries with explicit purpose-based scopes
- update SQL reporting views so subscription revenue and contact unlock revenue are separate columns/datasets
- require tests around `DashboardController`, `MetricsSnapshotService`, payment queue query builders, and AI reporting views so unlock payments cannot silently inflate subscription metrics

Settings:

- enable/disable per market
- one-time unlock price and duration
- one-time scope default: `single_profile`
- subscription unlock price and duration
- subscription scope: `market_inactive_profiles`
- providers allowed
- max unlocks per visitor/day
- refund/revocation policy
- sandbox/live mode visibility

Use `contact_unlock_pricing_rules` as the settings source of truth. Settings writes should be role-gated and audited.

No market can be publicly enabled without active pricing rows for the intended scope/provider/currency. There should be no hardcoded production default price. Seeds may create disabled sandbox examples only.

The exact production price/duration values are rollout blockers, not implementation blockers, because the implementation contract is configurable pricing with no hardcoded production defaults.

## Rollout Plan

### Phase 2A: Hardening

- lifecycle guard on all owner/agency edit and POST routes, with WordPress admin exemption
- explicit hardening for profile personal info, tours, photos, videos, media AJAX, and any account/sidebar edit action that can mutate restricted profiles
- convert generic WP update field handling to a positive allowlist and reject `crm_lifecycle_state`, unsafe `post_status`, `notactive`, `needs_payment`, renewal flags, package/current-duration fields, subscription dates, premium/featured flags, owner reassignment, and lifecycle/control meta writes
- add `crm_lifecycle_state` to the CRM `PATCH /clients/{client}/wp-profile` protected-field blocklist
- require CRM-side role authorization before approved CRM admin/operator profile updates are sent to WordPress
- preserve WP admin and CRM admin/operator ability to perform approved profile updates
- verify no contact leakage, including known Telegram/FAB render path
- update inactive copy/CTA skeleton behind feature flag

### Phase 2B: CRM Domain

- migrations
- models
- `contact_unlock_pricing_rules` settings source
- CRM service API routes under `wp-svc/contact-unlock`
- CRM admin role matrix enforcement
- pricing service
- checkout service
- fulfillment service
- reveal service
- visitor PII hashing/masking/redaction/purge or encryption support
- tests

### Phase 2C: Billing Rails

- `BillingSurface::ContactUnlock`
- dedicated contact unlock provider initiation path or purpose-aware refactor below wallet top-up/subscription entry points
- KopoKopo routing for Kenya
- pawaPay routing for configured markets
- provider adapter capability/binding updates for contact unlock surface
- KopoKopo callback acceptance for `visitor_contact_unlock`
- pawaPay callback fulfillment dispatch for `visitor_contact_unlock`
- webhook fulfillment dispatch
- subscription side-effect guards
- diagnostics support

### Phase 2D: WordPress Plugin

- config endpoint
- intent endpoint
- status polling endpoint
- reveal endpoint
- server-to-server auth
- no-cache/contact redaction tests

### Phase 2E: Theme UI

- inactive notice replacement
- stepper modal
- pending/payment/retry/unlocked states
- mobile-first polish
- browser verification

### Phase 2F: CRM Accounting

- Contact Unlocks page
- `Payment::subscriptionRevenue()` and `Payment::contactUnlockRevenue()` scopes/query contracts
- revenue filters
- export
- reconciliation
- refund/revocation tools

### Phase 2G: Pilot

- Kenya KopoKopo
- existing pawaPay-configured markets
- sandbox verification
- production provider approval
- staged enablement by market

## Test Plan

### CRM Tests

- create one-time unlock intent idempotently
- create subscription unlock intent idempotently
- CRM `wp-svc/contact-unlock` endpoints require valid service HMAC headers
- CRM `wp-svc/contact-unlock` endpoints resolve platform from middleware attributes, not body fields
- `POST /intents` requires and replays `X-Idempotency-Key`
- duplicate idempotency key replays the original payment/unlock and does not create a second entitlement
- public token is unique and cannot be guessed/reused across visitors
- unsupported market returns clear error
- market cannot be enabled publicly without active pricing rules
- inactive-only eligibility
- paid entitlement remains usable until expiry if the advertiser renews after visitor payment
- subscription reveal requires `target_wp_post_id` and validates target profile market/scope/restricted state
- one-time reveal rejects any `target_wp_post_id` other than the paid profile
- KopoKopo webhook completes unlock payment
- pawaPay webhook completes unlock payment
- duplicate webhook does not double-grant
- failed webhook does not grant
- refunded/reversed payment revokes entitlement
- refund/revocation actions are admin-only in v1
- subscription provisioning ignores unlock payments
- payment queue cannot create subscriptions from unlock payments
- payment matching cannot auto-create deals from unlock payments
- manual payment approval/bundle flows cannot convert unlock payments into advertiser subscriptions
- legacy public payment callback/update/manual routes cannot activate profiles from unlock payments
- unlock revenue excluded from subscription totals
- `Payment::subscriptionRevenue()` excludes unlock payments
- `Payment::contactUnlockRevenue()` includes only unlock payments
- dashboard, payment queue, AI metrics, and SQL reporting views use explicit revenue scopes instead of successful-non-wallet assumptions
- unlock payments are either excluded from existing generic queue/revenue widgets or labelled with `purpose=visitor_contact_unlock`
- `escort_post_id` is populated for single-profile unlock payments
- raw visitor phone/email are not persisted in unlock entitlements, attempt metadata, or logs
- `payments.phone` is purged/masked after terminal state or encrypted if reconciliation requires retention

### WordPress Plugin Tests

- config endpoint hides unsupported markets
- proxy endpoints sign CRM requests using the existing CRM sync HMAC convention
- reveal endpoint requires entitlement
- reveal endpoint no-cache headers
- config, intent, status, and reveal proxy responses include no-store headers whenever they carry session/token/payment/provider/entitlement/contact state
- reveal endpoint returns masked/limited payload before active entitlement
- restricted profile contact not present in HTML
- public engagement/invite endpoints do not expose raw profile phone/social URLs for restricted profiles
- generic client update rejects lifecycle/status/control fields while allowing ordinary CRM-approved content updates
- generic client update allows only named ordinary profile fields and rejects unknown meta by default
- lifecycle-restricted owner/agency profile, tour, photo, video, media, and account edit POSTs are rejected server-side; WordPress admins remain allowed
- owner publish/private toggles in account/profile templates are rejected for lifecycle-restricted profiles; WordPress admins remain allowed
- Telegram/social/FAB render paths do not expose contact URLs while restricted
- CRM `admin`, `sub_admin`, `sales`, and `field_sales` retain approved ordinary support update paths while lifecycle/control fields remain blocked

### Browser Tests

- inactive profile shows `Unlock contact`
- one-time unlock payment pending flow
- completed unlock reveals contact
- failed payment shows retry
- subscription unlock grants access on another inactive profile
- mobile modal has no overflow
- keyboard navigation and focus trap work

## Security Requirements

- Never expose contact details in cached public HTML.
- Unlock only after provider-final successful state.
- Use idempotency keys for intent creation and provider initiation.
- Store idempotency keys hashed and enforce uniqueness transactionally.
- Store raw webhook events and dedupe keys.
- Store raw webhook/provider payloads encrypted where retained, expose redacted projections for diagnostics, and apply retention.
- Verify provider signatures where supported.
- Store visitor phone/email as masked and hashed values on unlock entitlements.
- Purge/mask raw phone from payment compatibility fields after terminal state, or encrypt it if finance reconciliation requires retention.
- Redact visitor contact data from logs, `PaymentAttempt` metadata, and provider transaction metadata unless a provider contract strictly requires it.
- Rate-limit intent creation, polling, and reveal.
- Audit reveal attempts.
- Revoke entitlement on refund/reversal.
- Confirm pawaPay and KopoKopo terms allow this use case before production launch.

## Open Questions

These are rollout/business-configuration blockers, not implementation-design blockers.

1. What exact default price should one-time unlock use per market?
2. What exact default price and duration should subscription unlock use per market?
3. Should subscription unlock be per country/market only, or global across all pawaPay/KopoKopo markets?
4. Should visitors receive email/SMS receipts in v1?

## Non-Goals For This Phase

- Manual/offline unlock payment review
- advertiser subscription renewal redesign
- profile activation from visitor payment
- wallet-funded visitor unlocks
- card payments unless already supported by the selected market and contractually approved
- changing profile URLs, title tags, headings, schema, or indexed content

## Plan Audit Log

- 2026-08-24: Plan created after WordPress, sync plugin, CRM billing, and UX/security discovery.
- 2026-08-24: Clarified hardening scope: restrict non-admin lifecycle/edit bypasses while preserving WordPress admin and CRM admin/operator update authority.
- 2026-08-24 Round 1 audit: Verdict NOT READY. Blockers covered KopoKopo callback rejection for unlock purpose, incomplete subscription side-effect guards, generic WP update bypasses, insufficient owner/agency edit-route enumeration, and a known Telegram/FAB contact leak. Should-fix items covered provider adapter/binding work, accounting exclusions, entitlement uniqueness/idempotency, concrete pricing settings, and `escort_post_id` consistency.
- 2026-08-24 Round 1 response: Added explicit KopoKopo/pawaPay webhook requirements, named subscription side-effect paths and regression tests, broadened WP update hardening while preserving admin/operator updates, enumerated server-side edit guards, called out the Telegram/FAB leak, made pricing rules a required table, added feature flags/rollback behavior, added provider adapter/binding requirements, added accounting exclusions, and defined entitlement uniqueness/idempotency constraints.
- 2026-08-24 Round 2 audit: Verdict NOT READY. Blockers covered missing CRM service API contracts for WordPress proxy calls and unresolved visitor phone PII handling. Should-fix items covered incomplete theme mutation surface enumeration, insufficient CRM role/action detail for admin-safe hardening, and unresolved active-after-unlock plus refund/revoke ownership decisions.
- 2026-08-24 Round 2 response: Added signed CRM `wp-svc/contact-unlock` route contracts, WordPress proxy mapping, idempotency header requirements, response payload contracts, visitor phone/email hashing/masking/redaction/purge-or-encrypt rules, concrete tour/media/photo/video hardening surfaces, a CRM/WP role matrix, admin-only refund/revocation for v1, active-after-renewal behavior, no-hardcoded-pricing launch gates, and matching regression tests.
- 2026-08-24 Round 3 audit: Verdict NOT READY. Blockers covered reveal auth being bearer-token-only/optional-session, missing raw webhook/provider payload PII retention rules, generic WP update hardening still relying on broad meta acceptance, and missing owner publish/private mutation paths. Should-fix items covered shared revenue query contracts, exact billing initiation refactor points, and SEO-specific `wp.service.auth` configuration naming.
- 2026-08-24 Round 3 response: Made browser session proof mandatory for intents/status/reveal, required no unlock tokens in URLs, added secure HttpOnly SameSite session-token guidance and combined rate-limit keys, required encrypted/redacted/retained raw provider payload handling, changed generic WP updates to positive allowlist semantics, added `template-profile.php` and `settoprivate` hardening paths, added purpose-specific revenue scopes/views/tests, prohibited reuse of wallet top-up initiation as-is, and required service-auth config generalization beyond SEO naming.
- 2026-08-24 Round 4 audit: Verdict NOT READY. Blocker covered missing target profile identity in subscription reveal/status contracts. Should-fix items covered no-store requirements for config/intent responses and public engagement invite endpoints that can expose raw WhatsApp URLs.
- 2026-08-24 Round 4 response: Added `target_wp_post_id` to status/reveal contracts, required single-profile target matching and subscription target market/scope/restricted-state validation, expanded no-store coverage to config/intent/status/reveal payloads, added public engagement/invite endpoint leakage hardening, and added matching CRM/WordPress tests.
