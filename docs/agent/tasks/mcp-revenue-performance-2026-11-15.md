# Long-range MCP revenue performance — 15 November 2026

Status: correction verified locally and prepared for the authorised source commit/push. Production still requires Ian's cPanel pull and the exact connector retest. No migration, configuration change or frontend rebuild is required.

Ian requested resolving and shipping the long-range failure observed in [live acceptance](mcp-live-acceptance-2026-11-14.md). Both August–November Uganda summary and dashboard failed after about60seconds with an HTTP405 transport response. The earlier date/bucket corrections were deployed; the remaining problem was execution cost.

## Cause and correction

A SELECT-only production EXPLAIN on MariaDB11.4.13 showed the dependent receipt-collision query scanning an estimated41,963 retained payments for every reported payment. The four-month Uganda interval contained1,557 all-status records. The exact dashboard also calculated the same financial report separately for summary, trend and market sections.

`RevenueReportingQuery::rows()` now computes query-local numeric receipt cohorts with DENSE_RANK over the full retained universe, ordered by receipt/currency/provider/environment. Both joined relations use the identical universe before requested site/date filtering. Ranking follows SQL collation equality, retaining Unicode, case and padding behaviour. A small numeric lookup avoids wide UTF-8 receipt-index limits and low-selectivity currency lookups. The complete existing collision predicate still decides receipt/account/bundle ambiguity; outer DISTINCT prevents multiple collision partners multiplying one payment. No hash, receipt truncation or durable identity was introduced.

`RevenueReportingService::dashboard()` calculates once and passes the same request-local report to existing summary/trend/market presenters. There is no persistent or cross-user financial cache. Per-payment USD rounding, cached-only FX, missing-rate metadata and exact boundaries are retained. `McpServer` selects this composition only for precise reporting; default/preset dashboards retain all three legacy presenters.

Scope: three backend files and `tests/Feature/Mcp/McpHourlyRevenueReportingTest.php`. Unrelated billing/collection/UI/build changes remain untouched. [Reviewed plan](../../../plans/mcp-revenue-performance-2026-11-15/plan.html): three audit rounds; the third returned READY for the numeric-cohort refinement.

## Evidence

- SQLite: **33 tests / 494 assertions pass**.
- Disposable MariaDB11.4.13 (matching the production version): **34 tests / 506 assertions pass**, including one additional volume benchmark.
- Embedded MCP dashboard: **24 contract assertions pass**. No HTML/JS application change or frontend build is needed.
- Production-shaped synthetic fixture:42,000 retained payments,1,557 requested all-status records, mixed eligibility/account/routing/bundle provenance and a deliberately concentrated single currency. Core query **1,589.85ms**; full dashboard including cached FX/presenters **1,972.70ms**; one candidate-data read. The numeric derived lookup uses a key. Expected native cash/earned values, per-payment USD amounts and four monthly point totals agree.
- SELECT-only production prototype of the final numeric join: full historical aggregate **2,096.68ms**, known exact week **1,736.93ms**; weekly values match the prior live tool/view result. It did not modify deployed PHP or the stored view. Private ops evidence: `var/reporting-numeric-join-probe-2026-11-15.out`; original plan/index read265b retained separately.

[Verification and source hashes](../../../output/mcp-revenue-performance-2026-11-15/verification.json), [SQLite results](../../../output/mcp-revenue-performance-2026-11-15/sqlite-tests.txt), [MariaDB results](../../../output/mcp-revenue-performance-2026-11-15/mariadb-tests.txt), [volume timings/plan](../../../output/mcp-revenue-performance-2026-11-15/benchmark.json), [test-copy adapter and benchmark source](../../../output/mcp-revenue-performance-2026-11-15/mariadb-harness.py).

The MariaDB adapter verifies the loopback disposable container's PDO driver/server version and creates a unique fixture database per test. Application, Local and production databases are never migrated/reset. Committed tests retain safe SQLite memory setup. The original string-key-only attempt failed the concentrated-currency fixture; the final numeric-cohort implementation passes it. Later fixture-only failures were unordered map comparison and binary floating-point summation in assertions; final checks sort identities and compare monetary minor units.

Regression coverage includes an independent240-record pairwise collision oracle; SQL-equality Unicode/case/padding and255-character receipts across sites/outside the requested interval; no transient cohort in public outputs; one dashboard data query and per-payment FX rounding; default/preset dispatch through four MCP protocols; existing eligibility, wallet/native/USD, view-shape, authorisation and country-demand cases. PHP syntax, scoped Pint and whitespace checks pass on the exact tested source bytes.

## Deployment and remaining acceptance

Pull the shipped source using the existing cPanel workflow, preserving deployed overlays, then refresh serving PHP through the established procedure. **No new migration, view recreation, permission change or frontend build is required.** The already-deployed aggregate view retains its previous equivalent definition; this correction fixes typed MCP summary/trend/market/dashboard execution. Long-range standalone SQL-view performance is a separate limitation.

After Ian's pull, rerun `exotic_revenue_summary` with `from=2026-08-01,to=2026-11-14,platform_id=5` and `exotic_render_revenue_dashboard` with the same fields plus `bucket=month`. Require timely responses, four August–November points, matching native/USD/category/site partitions and correct date boundaries. Retest the overnight period and default dashboard. Do not mark the live transport failure resolved until those calls pass. Provider-settlement reconciliation and actual rendered ChatGPT iframe acceptance remain separate.

Rollback: restore only the three scoped backend PHP files from the pre-pull source and refresh serving PHP. There is no schema/data rollback. The full retained-history scan is linear rather than per-payment; materialization still needs monitoring as retained volume grows. Existing candidate and bucket limits remain explicit and unchanged.
