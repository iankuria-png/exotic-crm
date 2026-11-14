# Historical MCP revenue requests — 14 November 2026

Status: **shipping authorised; source commit/push in progress; not deployed**. CRM baseline `d98da588`. Ian requested root-cause diagnosis and corrections, then explicitly authorised fleet ops checks of live CRM. No production deployment, migration or permission change was requested.

## Confirmed root causes

1. The connected live trend and market-breakdown schemas expose `window`, but lack historical `from`/`to` fields. Summary advertises dates, but the legacy request adapter forwards them without setting the dashboard's required `horizon=custom`. `CeoDashboardDataService::context()` therefore substitutes 30 days. A live summary request with `from=2026-08-01,to=2026-11-14` returned `window.from=2026-10-16,window.to=2026-11-14`. Its metadata echoed the requested dates and marked the result complete, concealing the mismatch. Do not reuse that response as historical revenue.
2. Fleet read **`20261114T172210Z-d4792-d9410-read-crm-historical-revenue-read-045a`** proves production lacks `RevenueReporting{Window,Query,Service}.php`, the new view migration and `vw_mcp_payment_collections`; `exact_dispatch_present=false`. Reporting release `33a77116` is pushed but not deployed. The SQL hatch's cached default is disabled; the two older aggregate views exist. Stored runtime settings were not inspected, so this default does not establish effective hatch enablement.
3. The catalogue's historical start comes from `clients.created_at`, not payments. The SELECT-only live check found 42,706 retained payment records, from 5 January 2026 through 14 November, and records in all four requested months: August 7,636; September 7,638; October 8,294; November 4,263 at the check time. These are all-status retention counts, **not reportable revenue or proof of continuous/complete payment coverage**.
4. The current embedded dashboard silently kept only the first twelve trend points. The newer, undeployed parser also rejected explicit intervals longer than 366 days.
5. Raw `payments` SQL rejection is an intended boundary. Existing release `33a77116` provides the aggregate-only payment view and exact reporting tools; historical requests do not need arbitrary table access or SQL-hatch enablement.

## Corrections

- Reuse the earlier release's exact reporting path, historical date fields, eligibility query and aggregate view. Explicit dates select the requested interval even without `window=custom`.
- Validate preset/custom arguments before legacy fallback. Unknown windows, incomplete dates and invalid intervals fail explicitly. Recognised presets preserve the established dashboard calculation.
- The shared adapter now infers the custom horizon for the complete CEO dashboard too, retaining its established calendar-day calculation.
- Remove the arbitrary one-year restriction. The existing 50,000 candidate-record and 9,000 trend-bucket limits remain explicit errors; no truncation is introduced.
- Graph every returned point in a labelled, keyboard-accessible scroll region and display the period count.
- Label catalogue client-history coverage accurately and add separately scoped successful-payment-candidate dates. Payment retention is only exposed when the caller's revenue-tool role/grant permits it; it explicitly disclaims complete revenue history and settlement assurance.
- Describe historical tool inputs and SQL-view currency/funding semantics in the discoverable reporting reference. Current November figures are to date; a full November total cannot yet be known.

The [visual plan](../../../plans/mcp-historical-revenue-2026-11-14/plan.html) passed two independent audit rounds. Round one found the CEO and chart gaps; round two was READY with an accessibility nit, which was implemented. Ian subsequently authorised shipping to CRM and will deploy personally. Live testing waits for his deployment confirmation.

## Verification

PHP 8.2.30; disposable SQLite memory databases only. No application/Local/production database was migrated or reset.

`/usr/local/opt/php@8.2/bin/php artisan test --filter='McpHourlyRevenueReportingTest|ContactUnlockCountryDemandTest' --log-junit output/mcp-historical-revenue-2026-11-14/phpunit.xml` — **29 tests / 317 assertions pass**. This includes the 20 reporting tests plus nine existing country-demand regressions sharing reporting integration.

`node tests/scripts/mcp-revenue-app-contract.cjs` — **24 assertions pass**. Four-, 24- and 107-point histories include the final point; missing FX remains unavailable; default dashboard metrics remain compatible. These are script/DOM-contract checks, not an actual ChatGPT iframe/browser acceptance run.

Scoped Pint and `git diff --check` pass. The ops diagnostic passes PHP syntax validation and completed with exit zero in an eight-second-capped, database-enforced read-only transaction, without booting Laravel or calling a provider. Its registry entry and PHP reader are local to `exotic-ops`; unrelated registry edits were preserved.

Fixtures prove August–November monthly amounts agree across summary, trend, market breakdown and graph payload through all four protocol adapters, including boundary exclusions; CEO date inference, preset compatibility, rejected fallbacks, a two-year monthly interval, explicit bucket-limit errors, market-scoped catalogue retention and revenue-role restrictions are covered. Existing raw-table SQL refusal, view eligibility, duplicate/bundle handling and missing-FX tests remain passing. They are synthetic figures, not reconciled production revenue. The earlier release's MariaDB verification remains recorded in its task; this correction was not rerun on MariaDB.

## Delivery and next action

The task-owned diff and hashes are saved under `output/mcp-historical-revenue-2026-11-14/`; the patch is against `d98da588` and contains only the eight corrected source/test/reference files. No unrelated billing/collections/build overlays are included. No frontend rebuild is needed for this correction: the MCP app is served directly from its HTML resource. Preserve the existing release's separately built assets when shipping it.

Ian will perform deployment and notify Codex when live testing may start. Use the [earlier reporting release checklist](reporting-release-2026-11-14.md) together with this correction: protect deployed overlays; ship the reviewed correction; inspect the actual live-to-release delta; deploy the reporting prerequisites; apply only `2026_11_13_180000_create_mcp_payment_collections_view.php` after a verified database backup; refresh serving PHP/config; verify the newly advertised trend/market historical schemas. The SQL hatch can remain disabled; retain existing grants and raw-table protection. A stored SQL view allowlist/SELECT grant only needs reconciliation if using that optional hatch.

Then verify August–November through the actual connector: monthly point labels, summary/trend/market/app interval agreement, November-to-date labelling, native-currency reconciliation and missing-FX handling. Measure runtime on retained production volume and verify the final chart point in ChatGPT. Neither live historical financial reconciliation nor production performance acceptance is complete. Do not mark this incident live-fixed until those checks pass.

Rollback: revert only the eight-file corrective diff and refresh serving PHP/resources; no data repair is involved. The earlier release's view rollback is separate and must follow its migration/deployment checklist. The initial correction pass made no commit or push. On 14 November Ian authorised source shipping only; no production runtime/config/grant change or customer/provider mutation is included.
