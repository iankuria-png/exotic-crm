# Feature demand by country and requested profile city

Started 13 November; local checkpoint 14 November 2026 (Africa/Nairobi).
Owner: Ian Kuria. Executor: `/root/visitor_demand_country`; coordinator completed final integration/copy review and handoff after the executor's usage limit ended its turn.

Status: source and matching assets prepared for the authorised 14 November commit/push; not deployed. Existing main baseline `052b4aff83382c9cef37eed89281c73a8a8116dd`; unrelated work preserved.

Ian confirmed that locations means **profile cities being requested**. This supersedes the earlier visitor-origin interpretation. No new visitor-geolocation collection is required.

## Result

The existing Web Visitors → Demand report now shows attempts and browser sessions by country and website, with a country filter and a table of requested profile cities. Ghana and both Tanzania websites remain visible even when checkout is disabled. Country selection intersects staff market permissions and site selection. CSV exports preserve unavailable values and counting definitions; requested-city values can be copied. Failed requests show an alert and Retry; missing history is unavailable rather than zero interest.

`cta_click` counts attempts to open the contact-unlock feature, including repeats. Distinct session hashes are counted per website, not as unique people across websites. Cities come from the requested profile's current CRM cache, not a historical event-time city snapshot. Locations are bounded to 100 rows with explicit limit/total/truncated metadata; attempt totals remain uncapped. Existing purchase/conversion widgets are retained and labelled separately. `eligible_view` only records a ready checkout drawer and cannot serve as all-profile-view demand denominator.

Backend coverage reports observed events as a lower bound. No history means collection is unavailable, not proof of no demand. Checkout configuration is settings-only and does not prove payment-provider readiness. Feature/payment settings were not enabled or changed.

## Files and evidence

- New `app/Services/ContactUnlockDemandService.php`, plus `ContactUnlockPulseService.php` and `app/Http/Controllers/CRM/ContactUnlockAdminController.php` integration.
- `app/Services/Mcp/McpAnalyticsService.php` visitor-demand method; shared country/date/timezone schema and metadata integration is in the hourly task's MCP files.
- `resources/js/pages/WebVisitors.jsx`, `resources/js/components/visitors/{VisitorAttemptDemand,VisitorDemandPanel}.jsx`.
- `tests/Feature/ContactUnlockCountryDemandTest.php`.
- [Demand tests](../../../output/visitor-demand-country-2026-11-13/test-result.txt): **9 tests, 46 assertions pass**; combined final run **23 tests, 150 assertions pass** in [shared evidence](../../../output/crm-hourly-reporting-2026-11-13/final-tests.txt).
- [Final browser checks](../../../output/visitor-demand-country-2026-11-13/final-browser-result.txt), [CSV checks](../../../output/visitor-demand-country-2026-11-13/final-export-check.json), [isolated build](../../../output/visitor-demand-country-2026-11-13/final-build.txt), [source manifest](../../../output/visitor-demand-country-2026-11-13/delivery-manifest.json).
- [Desktop](../../../output/playwright/visitor-demand-country-2026-11-13/final-desktop.png), [mobile](../../../output/playwright/visitor-demand-country-2026-11-13/final-mobile.png), [error state](../../../output/playwright/visitor-demand-country-2026-11-13/final-error.png). Synthetic fixture data is clearly labelled and does not establish production counts or sessions.

Tests use disposable SQLite memory fixtures. They exercise country/site permission intersection, admin visibility of both TZ sites, forbidden-site rejection/auth middleware, no-history/inactive sites, site-scoped sessions, CTA-only city aggregation with invalid cross-site links, location truncation metadata and real off-feature `ContactUnlockEventService` ingestion/idempotency. Four-protocol MCP integration additionally verifies new arguments and unavailable metadata with mocked analytics output.

The final browser fixture exercises the actual WebVisitors component with stubbed API responses: country/site filtering, both TZ sites, requested city display, CSV, error→Retry, desktop/mobile and no whole-page horizontal overflow. Earlier fixture failures (wrong heading locator, opaque `about:blank` local storage and JSX harness setup) were test-harness issues and were corrected; they are not counted as passes. Component empty/loading evidence is retained in `browser-states.txt`.

`npm run build -- --outDir output/visitor-demand-country-2026-11-13/build --emptyOutDir` passes on final source (existing CSS animation and large-chunk warnings retained). **This validates the full dirty source tree and is not a task-only release package.** Shared `public/build/` was not modified by these streams. Scope the source and rebuild matching production assets before an authorised release.

## Collection and release prerequisites

Existing local theme `js/contact-unlock.js` emits `cta_click` when the modal opens before payment readiness. Sending requires a configured events endpoint; `settings.configured === false` or an absent endpoint stops emission. WP must retain the event proxy/session-proof flow, signed service authentication to CRM `/api/wp-svc/contact-unlock/events`, and a matching `(platform_id, wp_post_id)` cached advertiser. `ContactUnlockEventService` validates that mapping and deduplicates event IDs without checking checkout enablement. No sender or authentication code was changed here.

Deployed Ghana/TZ sender parity, signed end-to-end event arrival and continuous tracking have not been verified. The 13 November read-only baseline observed Ghana CTA events but no events for either Tanzania site; absence is not a demand count. On release, verify fresh attempts and correct profile cities on all three sites while preserving their feature settings. Existing historical events can be reported; missing historical events cannot be reconstructed. Measure all-history coverage aggregation on production volume before claiming performance acceptance. No database migration is required for this report.

Coordination: [WP three-stream task](</Users/ian/Local Sites/exotic/app/public/docs/agent/tasks/reporting-seo-delegation-2026-11-13.md>). 14 November: Ian authorised shipping; scoped commit/push preparation is in progress. No production deployment or teammate message sent.

## Ship validation — 14 November

The release source combines current remote main `2bdd6d5b` with only this reporting/demand scope. All 23 backend tests / 150 assertions pass on disposable SQLite and MariaDB 10.6.28; MariaDB view creation and rollback pass. The final scoped source build passes, and the embedded dashboard retains 13 passing assertions. Production data volume, historical reconciliation and live Ghana/TZ event arrival remain deployment acceptance checks. See [release verification](../../../output/reporting-release-2026-11-14/verification.json) and [release checklist](reporting-release-2026-11-14.md).
