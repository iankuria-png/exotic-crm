# Task: CRM market database scanner (Database Observatory)

- ID / project: CRM Database Observatory; plan `plans/market-db-scanner-2026-09-16/` (local, untracked).
- Updated / status: 1 October 2026; phase 1 implemented, verified locally, committed and pushed for cPanel pull. Not deployed or enabled in production; no production scan run.
- Goal: continuously scan eligible market WordPress databases for malware and integrity findings with attributable, inert evidence, explicit coverage, durable progress and bounded production load.
- Authorization: Ian asked to implement the plan end to end and ship once verified, with his own manual QA preferred over browser automation.

## What exists

- Admin → **DB Observatory** (`/db-observatory`): Overview, Runs (live run detail, event log, coverage), Findings (malware facet, grouping, triage drawer, suppressions, CSV export), Markets (inventory/drift, reader connection + preflight), Rules (overrides, lists, Test on market), Schedules & limits (scanner switches, envelope-bounded limits, health), Logs & audit.
- API `/api/crm/db-observatory/*` (33 routes) behind the full CRM middleware chain; `App\Support\DbScannerPermissions` (view admin/sub-admin scoped; operate/configure admin).
- Engine `app/Services/DbScanner/`: private non-reconnecting PDO reader with verified READ ONLY + timeout, closed `QueryCompiler`, surface registry (14 inventory + 10 row surfaces), secret-name policy applied before value reads, byte-bounded keyset traversal, bounded decoder (HTML/URL/JS escapes/fromCharCode/Base64/gzip), safe serialized parser, 55 rules in `resources/db-scanner/packs/{core,malware,exotic}.json` + 25 editable lists, durable passes/runs/sweeps with fenced leases, outbox, recovery, schedule occurrences, resolution with point recheck.
- Commands: `crm:db-scan-sync-packs`, `crm:db-scan-dispatch`, `crm:db-scan-recover`; Kernel registers them plus two `db_scan` workers only when `DB_SCANNER_ENABLED=true`. Retention added to `crm:prune-history`.
- One additive migration `2026_10_01_200000_create_db_scan_tables` (26 tables). No market DDL/DML ever.

## Evidence (local, 1 Oct 2026)

- `artisan test --filter=DbScanner`: 52 passed / 515 assertions with a MySQL 8.0.35 CRM DB and the opt-in real-engine suite; 49 passed + 3 opt-in skips on the default SQLite run.
- Named regressions: SchedulerConcurrency, LegacyStkRouting, LegacyPaymentCompatibility, LegacyMutationRouteAuthorization, PlatformApiBillingPolicy, DeferredActivation, SubscriptionActivationOrphan, OperationsReport, SheddableJob, SystemDegradation — 94 passed.
- Real data, read-only via a temporary SELECT-only account: deep scan of `zim_audit_20260916` (78k rows, 24 MB, ~7 s, p95 7 ms) reproduces critical `support_admin@wordpress.com`, critical `crawl-page-optimizer`, `widget_text` malformed link, and no Yoast-transient CJK false positive. Local Kenya standard scan through the real queue: 387k rows, 28.6 s, p95 8.4 ms.
- MySQL-only defect found and fixed: lease renewal trusted affected-row counts (MySQL reports changed rows), which made a worker drop its lease after back-to-back commits.
- Browser smoke check (in-app pane, temporary local token since revoked): all seven tabs and run detail render; a closed-drawer crash was found and fixed. This is not Ian's QA.

## Deliberate deviations from the plan

- Credential preflight runs synchronously in the request (same gates, leases and admission) instead of being queued.
- Continuation intent is the open sweep row itself (deduplicated by the market admission claim), not a separate table.
- Older missed schedule occurrences are not inserted as `skipped` rows; only the latest within 24 h is created.
- Threshold aggregates run as single statement-timeout-bounded queries rather than incremental per-range totals; a timeout marks the surface incomplete.
- Drift findings (new admin, plugin/theme/settings changes, new outbound domains) never auto-resolve; operators acknowledge them.
- MariaDB timeout behaviour is implemented (`max_statement_time`) but only MySQL 8 was exercised locally.

## Deployment checklist (manual cPanel)

1. Pull; `php artisan migrate --force` (additive only); `php artisan crm:db-scan-sync-packs`.
2. Leave `DB_SCANNER_ENABLED` unset/false initially; keep both gate env vars at defaults (true). Run `php artisan config:cache` if config is cached.
3. Per canary market: create a MySQL user with `GRANT SELECT ON <schema>.*` only; enter it in Markets → Connection; Run preflight (works while scanning is off).
4. Set `DB_SCANNER_ENABLED=true`, enable scanning in Schedules & limits, scan one shared-host and one remote canary (Quick, then Standard, then Deep), watch query p95, timeouts, market health and callback latency; then five markets; then enable schedules.
5. Rollback: scanning off + schedules off, Stop active scans, confirm no scanner workers/leases, then revert code. Never roll back the migration; credential revocation is the database-level emergency stop.

## Next action

Ian's manual QA locally (see the summary in chat for the local setup), then cPanel pull + steps above. Phase 2 (alerts, custom rules, reputation, vulnerability lookups) and phase 3 (files/HTTP probes) remain unstarted.
