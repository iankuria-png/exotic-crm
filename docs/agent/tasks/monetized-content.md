# Task: CRM commercial core and operations for monetized profile content

- ID / project: monetized-content / Exotic CRM + WordPress
- Updated / status: 2026-09-28, implemented and live for Kenya. Protected delivery and the owner Private content panel were verified after production configuration. No real-money purchase, pass activation, restoration, or settlement was performed during this rollout.
- Goal and acceptance criteria: own bi-weekly/monthly Monetize pricing, active-listing subsidies, passes, asset/single/bundle offers, entitlements, settlement and full-listed-price spend-wallet credits. Settings → Monetize is the sole audited control plane for global defaults, market overrides and WordPress runtime behavior; WordPress retains protected-file ownership.
- Scope authorized by the current request: reuse existing provider routing, contact-unlock checkout patterns and advertiser wallets; add a Web Visitors-quality Monetize page and market configuration; coordinate with the WP profile media owner/visitor experience.
- Source plan / decisions / relevant code: authoritative source and rendered plan are in the WP repository at `/Users/ian/Local Sites/exotic/app/public/plans/monetized-content-2026-09-27/plan.mdx` and `plan.html`. Relevant CRM sources inspected include `PaymentCompletionService`, `BillingGatewayService`, `WalletService`, `WalletCheckoutService`, contact-unlock models/services/controllers, `WebVisitors.jsx`, `ClientDetail.jsx`, `Sidebar.jsx`, `router.jsx` and `routes/api.php`.
- History reconciliation: CRM implementation commits are `bc9ef6ec`, `aea5da64`, and `ea2230e5`; the coordinated WP implementation is local commit `253111205`. Preserve the current uncommitted contact-unlock access recovery and all other unrelated work.
- Done with evidence: CRM adds Settings → Monetize, revisioned market/runtime policy, passes, offers, purchases, immutable entitlements, creator credit after a settled live sale, staff review and operations views. WordPress adds private media storage, protected delivery, purchase recovery and advertiser management. The missing `VisitorContentPurchase` model found in the live panel was restored in `aea5da64`. `ea2230e5` keeps unsaved setup choices visible and returns an actionable provider error instead of a generic server failure.
- Next concrete action: assign the rollout UX/automation repair below, then run a controlled end-to-end transaction only after the repair’s approval and production safeguards are reviewed.
- Verification: focused regression passed for the provider-validation path (1 test, 3 assertions); PHP lint and Pint passed for its CRM changes; CRM frontend production build completed. The full monetization suite was started but did not produce a reliable final result in the captured tool session, so it is not claimed as passed. Production evidence below verifies configuration and delivery only.
- Deployment: live on Kenya as of 28 Sep 2026. The feature’s configuration, protected delivery and owner availability are verified. A controlled end-to-end paid purchase, entitlement restoration and settled creator-credit test remains deliberately unperformed; do not infer it from readiness.
- Files/hunks owned; unrelated working-tree/index changes to preserve: this task owns only this task record. Preserve all existing CRM modifications/untracked files, especially contact-unlock recovery and guided KYC.
- Unresolved questions / blockers: none for the current live configuration. The repair backlog must be addressed before additional market rollouts.

## Production rollout record — Kenya, 28 Sep 2026

- CRM commits `bc9ef6ec`, `aea5da64`, and `ea2230e5` were pushed to `origin/main` and manually pulled on the CRM cPanel host. The production host then ran `php artisan optimize:clear`.
- The WordPress production plugin reported version `1.3.15` with the premium-content endpoint classes present. The deployed WordPress files were manually uploaded; deployment is evidenced by the live readiness response, not by a WP Git push.
- Protected storage is `/home/exotickenya/exotic-private-content`, outside `public_html`, permission mode `0700`. `wp-config.php` was configured before WordPress bootstrap with `EXOTIC_PREMIUM_STORAGE_DIR` pointing to that directory and `EXOTIC_PREMIUM_FFMPEG` set to `/bin/ffmpeg`.
- Server checks succeeded: FFmpeg `5.1.7` at `/bin/ffmpeg` with `libx264` and `aac`; PHP has GD and fileinfo; PHP can call `exec`; `wp-config.php` passed `php -l`.
- CRM/WordPress authentication was aligned to `production` for the actual Kenya platform ID `1` (not the wallet-provider identifier `76`). The platform WordPress API base was corrected to the canonical `https://www.exotickenya.com/wp-json/exotic-crm-sync/v1`, matching the probe host.
- Final CRM evidence: Kenya was enabled in Live mode, global monetization was enabled, WordPress revision was `10 / 10`, and protected delivery showed all six checks green: protected storage, anonymous denied, direct denied, range, no-store and video processing. The advertiser page subsequently exposed Private content rather than the unavailable-state message.

## Rollout defects and hand-off for the UX repair agent

The setup was operationally unacceptable: a simple market enablement required cPanel edits, terminal diagnosis and direct database changes. Treat the following as the required repair backlog, not as optional polish.

1. Replace the manual environment/bootstrap path with a guided, preflighted setup flow. It must identify the actual platform record, canonical WordPress URL, active wallet-auth environment, storage path, FFmpeg path and PHP capabilities before allowing a live rollout.
2. Do not allow a save to appear to erase the form. Preserve entered values on every failed request; show inline, provider-specific validation beside the relevant control and a durable success state after persistence/sync.
3. Validate checkout providers before save and reconcile the enabled-provider list with the Surfaces & checkout controls. The earlier PawaPay mismatch produced a generic server error even though KopoKopo was intended.
4. Make readiness failures explain the exact cause and repair. `Probe host mismatch` was only discoverable through CRM Tinker; the screen must show both expected and received host and offer the canonical URL repair path.
5. Remove the need for staff to mutate production records via Tinker. A controlled admin action should align the market’s premium-access environment, bump the revision and sync it with an auditable outcome.
6. Add deployment completeness checks so a missing required model cannot reach production. The first live owner-panel request failed because `VisitorContentPurchase.php` was absent from the deployed CRM tree.
7. Provide an explicit final live readiness screen: configuration saved, revision acknowledged, all protected-delivery checks passed, and the precise remaining user action. It must distinguish configuration verification from a real payment transaction.

## Checkpoint — 29 Sep: decision-ready Monetize workspace

Ian reported that CRM Monetize exposed too little useful performance information and that
the Sales table was difficult to investigate. The local workspace now adds a focused
operational layer without changing payment, entitlement or settlement behavior.

- Overview shows checkout attempts, successful-payment completion rate, active purchases,
  failed/pending/review counts, active sellers and passes expiring within seven days.
  Per-currency content GMV, average sale, purchase count and absorbed provider fees remain
  separate from selling-pass list value, subsidy and revenue. Current creator credit is
  labelled as a wallet position rather than date-filtered revenue.
- Sales adds debounced search across purchase reference, creator, masked buyer and payment
  reference; purchase/payment/offer/provider/environment filters; 15/30/50-row paging;
  provider and payment status badges; masked buyer/access context; and visible provider
  failure reasons. Existing refund and settlement-review permissions/actions remain.
- The API supplies the explicit summary contract and safe joined payment fields while
  preserving market authorization and sandbox exclusion. Export now honors the added
  search/payment/provider/environment filters.
- Verification: the complete `MonetizationContractTest` passes (29 tests, 117 assertions),
  PHP lint and Pint pass, the CRM production build completes, and scoped whitespace checks
  pass. The build retains the pre-existing Tailwind reduced-motion selector warning and
  large-chunk warning. Ian owns browser QA; no payment, refund, settlement, deployment or
  production data mutation was performed.

Included in the current CRM shipment; deployment still requires the cPanel pull. The
related WordPress creator-price input is recorded in the companion-experience task in the
WordPress repository.

## Checkpoint — 29 Sep: first-login owner recovery

A newly created WordPress profile could reach Private content before its corresponding
CRM `clients` cache row existed. The owner-state request then leaked Laravel's
`No query results for model [App\\Models\\Client]` exception into the creator workspace.

- `ListingEligibility::assertOwner()` now performs a bounded single-profile WordPress
  import when the market/profile cache row is missing, then applies the same market and
  WordPress-user ownership check as every established profile.
- If that source import cannot complete, CRM returns a stable 503 setup-pending message
  and records the exception server-side. It does not create monetization access, a pass,
  an entitlement or a payment.
- A contract test covers the first-visit import. The complete monetization suite passes:
  30 tests and 121 assertions; PHP lint, Pint and scoped whitespace checks also pass.

Included in the current CRM shipment; deployment still requires the cPanel pull. The
coordinated WordPress fallback is recorded in the companion-experience task; Ian owns
browser QA and no account, payment or production mutation was performed.
