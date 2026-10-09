# Monetize guided market rollout

9 October 2026. CRM release `8ff5b117` is committed and pushed to `origin/main`; the
remote SHA was verified. WordPress support remains local and uncommitted for a separate
manual upload. CPanel pull/migration and production verification remain pending. No
production upload, activation, credential change or real-money test was performed.
Uganda remains Off; Kenya's production configuration was not touched.

## Implementation and boundaries

The first Settings → Monetize tab is now **Guided setup**. It shows the actual market ID,
configured and reported WordPress addresses, and ordered connection, server, wallet,
provider, pricing, delivery and activation gates. Stored preflight/setup reports survive
reloads; current configuration revision, URL, environment, provider availability and
15-minute check freshness determine readiness. The page refreshes saved status each minute.

WordPress preflight reports only declared facts: canonical home/REST addresses, plugin
version/capabilities, required file presence, existing/writable/private storage, GD,
fileinfo, PHP exec, FFmpeg executable and encoder availability. It verifies the authenticated
WordPress → CRM return trip without a payment. The signed endpoint also accepts a WordPress
administrator application password. Responses use private/no-store caching.

Connect WordPress uses the existing market administrator application password to align
market identity and the selected active wallet environment. It generates missing wallet
authentication credentials using the existing Wallet System mechanism, retains the
market's existing grant/device secrets, and audits the outcome without returning secrets.
It does not enable selling or alter wallet/provider modes. Canonical repair accepts only
the registered site's exact www/non-www alias, audits before/after and requires a fresh
reported address. It never accepts a browser-supplied destination.

All new transport and delivery probes validate HTTPS/public DNS, pin the verified address
in cURL, disable proxies and automatic redirects, and permit at most one controlled www
redirect. Private, shared, mapped, mixed public/private and unsafe origins fail closed.
HTTP on .local/.test is restricted to local/testing application environments.

New markets begin with no provider selection. Provider choices use the actual market and
environment configuration/credentials. Every selected provider is validated on save,
including Off drafts; Off drafts can be saved with no provider. Existing live settings
remain editable, but the general save endpoint cannot activate a new live market.

**Enable live market** is separate, revision checked, audited and gated by production
wallet/provider settings, complete releases, saved prices, global/market sales pauses,
and the authoritative delivery checks. WordPress must acknowledge the new revision.
A lost/failed activation confirmation retains the prior market mode and sends a higher
compensating revision. Sandbox passes expire only after confirmed live activation.

Drafts contain editable policy fields and the audit reason, scoped to staff ID and market
in sessionStorage for 24 hours. Failed requests and background refetches preserve them;
successful persistence clears only that market's draft. Revision conflicts prevent a
silent overwrite. Feedback stays visible; price/offer/access errors have field associations,
tabs support arrow/Home/End keys and keyboard focus, and primary actions stay visible.

Storage checks resolve symlinks and both WordPress/public document roots before creating
a directory. A WordPress subdirectory cannot make public storage appear private. An explicit
invalid FFmpeg override fails closed. Existing files, denial, ranges, no-store and originals
remain under the protected-storage contract. No wp-config.php, cPanel or SSH automation exists.

## File-level change map

| Area | Files |
|---|---|
| CRM setup/state/security | `app/Services/Monetization/SetupService.php`, `WordPressDestination.php`, `ReadinessService.php`, `SyncService.php` |
| Saved policy/provider validation | `app/Services/MonetizationSettingsService.php`, `app/Models/ContentMonetizationSetting.php` |
| Authorized actions | `app/Http/Controllers/CRM/MonetizationController.php`; only the new Monetize setup route hunk in `routes/api.php` |
| Persistent reports | `database/migrations/2026_10_09_000001_add_monetization_setup_reports.php` adds nullable `preflight_json`/`setup_json` without changing market modes |
| Operator UI | `resources/js/components/settings/monetization/MonetizationSettings.jsx`, `MonetizationSetup.jsx`, `monetizationDraft.js`; matching `public/build/` |
| CRM completeness | `app/Console/Commands/CheckMonetizationRelease.php` checks declared model/service/controller files and referenced frontend assets; optional migration check |
| WordPress runtime | `wp-content/plugins/exotic-crm-sync/includes/class-premium-content-storage.php`, `class-premium-content-endpoint.php` |
| WordPress completeness | `wp-content/plugins/exotic-crm-sync/scripts/check-premium-content-release.php` checks required files, PHP parsing and preflight presence without booting WordPress |
| Tests | CRM `MarketSetupTest.php`, the established provider fixture in `VideoTeaserTest.php`, `tests/browser/monetization-market-setup.spec.js` and its isolated fixtures; WP `tests/premium-preflight-contract.php` |

The WordPress loader/version file is already dirty from other work and was **not changed
by this task**. Guided support is negotiated with schema/capabilities, not a version bump.
Preserve the unrelated Observatory/containment routes, state changes and all other work.
New plugin scripts may be ignored; inspect their exact paths and selectively force-add
them only as part of an authorized WordPress release. The initial implementation build
included shared dirty sources. The shipped CRM build was rebuilt in a temporary export
of HEAD plus only this task’s files; all unrelated Observatory sources/routes were excluded.

## Deployment order

1. Prepare a reviewed compatible plugin release. Upload the storage file before the endpoint
   file, or upload the matching complete plugin release. Keep unrelated local loader and
   pending-feature changes out of an unreviewed overlay. The new preflight uses existing
   classes, so this task needs no loader edit. Old CRM retains the existing six-check routes.
2. CRM release `8ff5b117` includes **all** mapped backend/UI files, the migration,
   release command and matching isolated build assets. It is pushed to `origin/main`.
   WordPress is separate; do not push the WordPress repository.
3. During the normal CRM deployment, run migration before staff use updated setup. After
   the authorized cPanel pull, use the complete block below:

   ```sh
   git pull --ff-only origin main
   php artisan migrate --force
   php artisan monetization:check-release --database
   php artisan optimize:clear
   php artisan queue:restart
   ```

   Stop rollout if the completeness command fails. It detects omissions such as
   `VisitorContentPurchase.php` and missing compiled assets. It does not prove payments work.
   The optional WP check, from the WordPress public directory, is:

   ```sh
   php wp-content/plugins/exotic-crm-sync/scripts/check-premium-content-release.php
   ```

4. If CRM arrives before the preflight-capable plugin, an **already enabled Live market**
   whose preflight returns 404 continues using the existing signed six-check readiness path.
   A new market cannot activate through that compatibility path. Upload plugin support
   before attempting Uganda readiness/activation. Other failed preflight conditions fail closed.

Future market setup after this application deployment requires no Tinker, direct database
mutation or terminal diagnosis. Host-only work remains a guided cPanel File Manager/PHP
extension step. The UI generates exact detected private paths and config/optional commands;
do not paste Kenya's paths into Uganda or invent Uganda's hosting account/path.

## Manual verification after deployment

1. **Kenya / market #1:** confirm the existing saved Live mode/provider/currency. Run checks
   without changing policy. Verify revision acknowledgement, required delivery checks and
   the existing owner Private content panel. Inspect the real public profile/affected assets.
   Do not change production wallet credentials or submit a purchase during this check.
2. **Uganda / market #5 / UGX:** confirm Off. Open Guided setup, use its market-specific
   connection link if needed, select the intended wallet environment and save. Connect
   WordPress; compare both displayed addresses. Use canonical repair only when offered.
3. Resolve server issues using the generated cPanel instructions. Definitions go before
   WordPress bootstrap; replace existing definitions, not duplicate them. No protected
   originals belong in uploads or CDN storage. Re-check PHP execution through the UI.
4. Follow the real Wallet System link for market #5; configure only the intended enabled
   provider/environment. Return, choose an available provider, review pass/offer policies
   and save. Check the durable saved outcome and reload to verify retained values.
5. Run checks & sync. Confirm saved reports, current revision and all required storage,
   anonymous/direct denial, range, no-store and video checks. Test keyboard/mobile flow.
6. Stop at **ready to enable**, still Off. Do not click Enable live market without a separate
   explicit operator decision. These checks are not a real payment/refund/restoration/
   settlement canary. Any later payment test requires its own controlled plan.

## Local verification evidence

Source baselines: CRM `1632d4def4a991997132d2ade3b9bcb3031be664`; WP
`b049e991b337fd96ae1182afaad06ee1f3b5b10d`, plus the owned dirty/untracked files above.
PHP checks use `/usr/local/opt/php@8.2/bin/php`; Laravel tests use configured in-memory
SQLite. Browser checks use the actual components on isolated Vite `127.0.0.1:5174`,
mocked API responses and Chromium; they never access production or the application DB.

- Complete monetization suite: **69 tests / 367 assertions passed** (`artisan test tests/Feature/Monetization --compact`). Includes the report migration down/up cycle, retained market configuration, activation compensation, private DNS/origin checks and secret filtering. The final focused setup rerun also passes **21 tests / 99 assertions** against the last deep-link/status edits.
- Seven browser contracts passed, including failed validation/500 responses, reload and
  market-switch draft recovery, available-only providers, explicit activation, keyboard
  tabs/focus and overflow at 1440/768/390px. Screenshots:
  `output/playwright/monetization-setup-{1440,768,390}.png`.
- WordPress: **196 isolated checks** across eight subprocess modes passed. They execute
  the real storage/endpoint/HMAC code with stubbed WordPress I/O and cover exact signature,
  wrong market/body/time, missing/public/symlink storage, missing/inaccessible/non-running
  FFmpeg, missing encoders, disabled exec and absent extensions. They do not replace the
  post-deployment six live delivery probes. WP PHPUnit library is unavailable; no test DB
  was installed or production/site DB reset.
- PHP lint passes for 12 CRM and four WP files; scoped Pint passes for 11 owned CRM
  files; both release checkers and scoped whitespace checks pass. Vite
  production build passes with the pre-existing reduced-motion CSS parser and large
  bundle warnings. Shared sources were preserved; no invented frontend lint/test command.

No WordPress deployment package/ZIP or production success is implied. The CRM release
has a separate changelog entry; its status is pushed, awaiting manual deployment.

## CRM shipment — 9 October

At Ian’s request, shipped only the CRM rollout repair as `8ff5b117` to `origin/main`
(remote SHA `8ff5b1170dadc956ccf8e39a3a15e9c83e9a52b8` verified). The isolated
release passed **69 tests / 367 assertions** in 135.29 seconds, **seven browser tests**
in 21.5 seconds, syntax for 12 files, Pint for 11 files, release completeness and build.
Build assets are `app-zYdPGJTB.js` and `app-kVXqvXIT.css`; the known CSS parser and
large-bundle warnings remain. Logs are `/tmp/exotic-monetize-ship-{tests,browser,build}.log`.
Only the Monetize setup route was staged from the shared route file. Unrelated sources,
routes, documentation and generated draft assets were retained in the working tree.
The temporary export was a verification snapshot, not a feature branch or worktree.
No production requests or writes were made; the manual deployment block above remains.
