# CRM exact-time and hourly reporting

Started 13 November; local checkpoint 14 November 2026 (Africa/Nairobi).
Owner: Ian Kuria. Executor: `/root/crm_hourly_reporting`; coordinator completed integration review, evidence and handoff after the executor's usage limit ended its turn.

Status: source and matching assets **PUSHED to origin/main as `33a77116`** on 14 November 2026; not deployed. Baseline `052b4aff83382c9cef37eed89281c73a8a8116dd`, existing main checkout. Unrelated working-tree and staged changes preserved.

## Result

MCP revenue summary, trend, market breakdown and embedded dashboard accept exact offset timestamps without truncating them to calendar dates. Intervals use inclusive start/exclusive end; date-only `to` includes that local calendar day. Default timezone is Africa/Nairobi. True hour buckets retain their dates across midnight. Country filters include all authorised constituent sites, including both Uganda sites. The existing default dashboard path remains available; precise reporting is selected by explicit boundaries, country/timezone or hourly bucket.

Reports separate new cash, earned-service value, wallet redemptions and unknown funding. Categories include subscriptions/renewals, contact unlocks and other services; wallet top-ups are excluded. Native amounts remain visible. USD conversion uses cached historical FX with live fetching disabled; missing rates yield unavailable USD rather than a 1:1 substitute or zero. Exact-window customer mix/snapshot metrics are explicitly unavailable. Exact collections always use USD plus native currencies; a requested alternative reporting currency is disclosed rather than falsely echoed.

One eligibility query reuses `Payment::reportableSuccessful()` and drives the tool and aggregate-only `vw_mcp_payment_collections` migration. Receipt ambiguity is checked across retained eligible history before interval filtering; ambiguous rows are excluded while valid bundle splits remain additive. View `amount`/`payments_count` represent new cash; earned-service columns are separate. No customer identities/references are exposed in the view. SQL hatch remains disabled by default and retains SELECT allowlists and existing limits.

## Files and evidence

- New `app/Services/Mcp/RevenueReporting{Window,Query,Service}.php`.
- MCP integration in `McpServer.php`, `McpRequestAdapter.php`, `ToolRegistry.php`, and `config/mcp.php`; embedded app `resources/mcp/apps/revenue-dashboard-v1.html`.
- Migration `database/migrations/2026_11_13_180000_create_mcp_payment_collections_view.php`.
- `tests/Feature/Mcp/McpHourlyRevenueReportingTest.php` and `tests/scripts/mcp-revenue-app-contract.cjs`.
- [Reviewed visual plan](../../../plans/crm-hourly-reporting-2026-11-13/plan.html).
- [Final backend evidence](../../../output/crm-hourly-reporting-2026-11-13/final-tests.txt), [JUnit](../../../output/crm-hourly-reporting-2026-11-13/final-phpunit.xml), [app assertions](../../../output/crm-hourly-reporting-2026-11-13/app-contract.txt), [source manifest](../../../output/crm-hourly-reporting-2026-11-13/delivery-manifest.json).

## Verification

`/usr/local/opt/php@8.2/bin/php artisan test --filter='McpHourlyRevenueReportingTest|ContactUnlockCountryDemandTest' --log-junit output/crm-hourly-reporting-2026-11-13/final-phpunit.xml` — **23 tests, 150 assertions pass** (14 reporting/integration tests plus 9 demand tests). Disposable SQLite memory connections; no business database migrations/reset.

`node tests/scripts/mcp-revenue-app-contract.cjs` — **13 assertions pass**. Includes missing FX rendering as Unavailable, cash headline, service/wallet separation and default app compatibility. Scoped Pint and diff whitespace checks pass.

Fixtures cover Julian's Uganda 31 October 15:00 EAT through end of 6 November and overnight 12 November 20:00–13 November 05:09, exact/subsecond boundaries, timezone offsets, success/test/bundle exclusions, retained-history collisions, wallet variants, missing/cached FX and four MCP protocol versions. These are synthetic acceptance fixtures, not verified historical production totals. The added visitor transport case mocks analytics output to test argument/coverage propagation; real demand scoping/ingestion is exercised separately.

## Remaining release work

Disposable MariaDB 10.6.28 view compilation, rollback and all 23 tests / 150 assertions pass. **Production-volume query runtime and historical payment reconciliation remain unverified.** The scoped source and matching assets are pushed. At deployment, apply only the new view migration, reconcile existing SQL read grants if required, refresh serving PHP and verify both historical intervals through Julian's connector. Rollback removes the view and restores scoped source/assets. No production migration or grant was applied here.

Coverage remains CRM-recorded: provider settlement and historical completeness are unverified. Missing receiver provenance uses platform/provider fallback and can miss cross-site receipt relationships; unknown funding and absent FX are disclosed. Reports cap candidates at 50,000, windows at 366 days and trend buckets at 9,000; limits do not establish database performance.

Coordination: [WP three-stream task](</Users/ian/Local Sites/exotic/app/public/docs/agent/tasks/reporting-seo-delegation-2026-11-13.md>). 14 November: Ian authorised shipping; scoped source/build commit `33a77116` is pushed to origin/main. No production deployment or message sent.

## Ship validation — 14 November

The release source combines current remote main `2bdd6d5b` with only this reporting/demand scope. All 23 backend tests / 150 assertions pass on disposable SQLite and MariaDB 10.6.28; MariaDB view creation and rollback pass. The final scoped source build passes, and the embedded dashboard retains 13 passing assertions. Production data volume, historical reconciliation and live Ghana/TZ event arrival remain deployment acceptance checks. See [release verification](../../../output/reporting-release-2026-11-14/verification.json) and [release checklist](reporting-release-2026-11-14.md).
