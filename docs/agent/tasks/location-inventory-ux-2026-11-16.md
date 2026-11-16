# Location inventory UX — 16 November 2026

Status: source and matching compiled assets **PUSHED** to origin/main as `c5819d5e8a6dc73c6149504a0bcc7a5ae63f5293`. Remote main independently confirmed. **Not deployed or live-verified.**

Ian requested better CRM locations search/sort/filter/metadata, identification of locations with the lowest profile counts and shipment ASAP. He confirmed “unlocked” means expired profiles visitors can unlock, permitted fleet ops skills and approved the concrete plan with “proceed”. The approved release scope is implementation, verification and source/assets shipment. Live apply remains subject to the separate Ops deployment contract; permission to use tools does not approve a reset or migration.

## Delivered behavior

The table leads the workspace. Search trims whitespace and ignores case. Available, Active, Unlockable and All clients each support count ranges and sorting. Default order is fewest Available first, with a stable city-name tie-break. Low inventory selects five or fewer Available, including zero; No active selects Active = 0; Unlockable only selects Active = 0 and Unlockable > 0. Map and performance filters combine with these controls. Invalid count ranges expose an inline error and disable export.

Available is Active + Unlockable. Active uses the existing `isActiveProfile()` rule with lifecycle_state now actually selected from the database. Unlockable requires published status, positive wp_post_id and lifecycle expired, matching the WordPress archive bucket. These are disjoint counts; Archived/private/draft/missing-post profiles do not enter Unlockable. All clients still counts every non-closed cached record in that city. Zero-available cities with cached clients remain visible; a census of taxonomy cities with no cached clients is outside this release.

The table paginates at 25/50/100 rows. CSV exports every filtered row in current sort order, across pages, with market/date/definition context, escaped cells and spreadsheet-formula protection. Verified, map state and period-specific engagement/performance/channel metadata live in keyboard-accessible inline disclosures. Numeric zero remains measured zero; unavailable values stay unavailable and sort last in either direction. The filtered map is collapsible below the table. Existing canonical city/market drill-through, SEO Boost and geocoding controls are retained. Market changes suppress previous-market rows/exports rather than showing cross-market placeholder data.

## Plan and review

[Plan source](../../../plans/location-inventory-ux-2026-11-16/plan.mdx) / [visual plan](../../../plans/location-inventory-ux-2026-11-16/plan.html). Round 1 NOT READY (missing-value sorting, explicit map/spotlight metadata ownership and unrelated migration scope); all addressed. Fresh round 2 READY; historical-overlay wording corrected.

## Verification

Source base: local main `9068f081`, whose parent feature `5bce002c` was the live/remote baseline. Release built from an isolated archive of HEAD plus eight owned source/test paths. Dirty wallet/billing/collection work was excluded. Existing tracked flexible-payment, pay-link and profile-lock integrations were retained in source and bundle.

- PHP 8.2 `DB_CONNECTION=sqlite DB_DATABASE=:memory: artisan test --filter=ClientLocations`: **14 tests, 65 assertions PASS**, disposable SQLite. Covers existing endpoint/geocode contracts, market denial, canonical merging, old/unavailable analytics and the new active/expired/archived/private/draft/missing-post/closed/cross-market inventory cases. [Result](../../../output/location-inventory-ux-2026-11-16/backend-tests.txt).
- Changed PHP syntax and scoped `git diff --check`: PASS. Pint reports the same two pre-existing file-level style findings on HEAD and the candidate; no broad formatting applied. [Baseline](../../../output/location-inventory-ux-2026-11-16/style-baseline.txt).
- Scoped `npm run build`: PASS. Existing forecast CSS selector and large-bundle warnings retained. Entry `assets/app-wrQ9yccB.js`, CSS `assets/app-CPe7wcFT.css`. Matching manifest committed; existing dirty local manifest left byte-for-byte unchanged. Previous hashed assets retained in Git. [Build](../../../output/location-inventory-ux-2026-11-16/build.txt), [owned source hashes](../../../output/location-inventory-ux-2026-11-16/source-manifest.json).
- **53 browser checks PASS** using the exact scoped-source components and release CSS: 49 primary plus four map/phone action checks. Search/ranges/presets, pagination, all-page CSV, keyboard metadata, null-last sorting, current-market retry/empty/unavailable states, delayed market switch, date request and city drill-through verified. Widths **320, 390, 768, 1440** have no page-wide overflow and 16px search text. Caught and fixed an absolute screen-reader label escaping the table scroll region. [Primary results](../../../output/location-inventory-ux-2026-11-16/browser-results.json), [additional results](../../../output/location-inventory-ux-2026-11-16/browser-extra-results.txt).
- Independently parsed CSV: all 33 filtered synthetic locations across pages, excluded high inventory, preserved null/zero and neutralized the `-1` label as a spreadsheet string. Helper checks validate bounds, missing-value ordering and formula escaping.
- [Desktop](../../../output/location-inventory-ux-2026-11-16/desktop.png), [phone](../../../output/location-inventory-ux-2026-11-16/width-390.png), [standalone functional preview](../../../output/location-inventory-ux-2026-11-16/preview.html). Browser data is synthetic; this proves no live advertiser count or WordPress freshness. Chromium coverage does not establish Safari or physical-device acceptance. No browser page errors.

## Deployment and recovery

Read-only fleet preflight `20261116T093339Z-d4792-d9410-read-crm-preflight-539d` PASS: production main/origin-main at `5bce002cddf4886c7febecde3c1bccf579601909`, clean tracked tree, 36 untracked, manifest missing files 0, flexible-overlay PASS, entry `assets/app-B8JbCHgH.js`. This updates the baseline for this task; older profile-lock handoff notes about a dirty live overlay do not govern this observed state. Recheck before applying.

1. Resolve target `d4792/d9410`, `/home/d9410/crm.exotic-online.com`, with a fresh genuine Ops `bin/mkt` preflight. Compare exact source/bundle/manifest, tracked/untracked state, deployed HEAD and origin/main. Preserve untracked paths and payment integrations. No blind reset.
2. Back up the owned source and active manifest/assets with extraction/hash verification, retaining an exact previous file manifest and a recovery boundary. Prepare a pinned update to the reviewed release SHA; the existing generic crm-pull script checks its expected SHA only after pulling, so it must not be treated as a fully pinned transactional deploy without addressing that limitation.
3. This feature introduces **no migration, seeder, configuration change or WordPress upload**. Do not run unrelated pending migrations incidentally. Refresh Laravel/serving-PHP caches only through approved commands; stale PHP must not serve the old aggregate with the new bundle.
4. Verify live API fields and real-data totals independently: per city `available_count = active_count + unlockable_count`, market totals reconcile, closed/inaccessible records excluded. Verify served JS/CSS hashes and native flexible-overlay guard. Then `/clients?tab=locations`: search, no-active, unlockable-only, fewest-first, metadata, all-page CSV and phone scroll/action checks. Confirm profile lock, flexible settings and pay-link integrations remain present. Check a bounded new error window.

Recovery restores only the previous owned PHP/UI source and manifest/build assets. No database rollback is needed by this feature. Keep old hashed assets available; do not remove untracked or unrelated paths.

## Ownership and remaining work

Owned application paths: ClientLocationController; ClientLocationsEndpointTest; LocationsView; locations/LocationTable, LocationFilters, locationInventory, ClientMap and CitySpotlight; two new hashed assets and the exact release manifest. Unrelated source, pre-existing deleted assets and local manifest were preserved. The Git index was empty before this task.

Next action: authorized pinned production deployment and actual-market acceptance. No live apply, customer mutation, payment, message, WordPress edit/upload or WP repository push performed. Changelog is maintained separately in the WP workspace; no Netlify publication.
