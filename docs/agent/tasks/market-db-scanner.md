# Task: CRM market database scanner (Database Observatory)

- ID / project: CRM Database Observatory; plan `plans/market-db-scanner-2026-09-16/` (local, untracked).
- Updated / status: 2 October 2026; phase 1 deployed and migrations/packs confirmed by Ian. Kenya reader saved; preflight was blocked by measured level 2 load (73 PHP processes against a threshold of 48). Scoped load override verified for shipment with compiled assets; its production deployment and first successful canary remain pending.
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

## Simplified setup and persistent market load gate (2 October follow-up)

This supersedes the timed-override setup instructions below. Ian explicitly requested a persistent toggle and easier setup after repeated credential failures.

- Markets → Reader connection now has **Save & test connection**, explicit saved-credential indicators, a cPanel assignment checklist, advanced settings collapsed, inline errors and **Scan this market** after verification.
- **Load gate → Off**, then **Save & test connection** (or **Save settings**) persists until an admin switches it back on. It bypasses all load admission, including Critical and missing/stale telemetry, for this market's connection checks, manual scans, scheduled scans and continuations. Other markets and global CRM load policy are unaffected. No reason or timer required.
- Emergency stop, scanner enable/pause, health, credentials, read-only/TLS validation and concurrency/budget limits remain. Running workers read the current gate policy at checkpoints, so re-enabling takes effect during work. Gate changes are audited and do not invalidate credential proof.
- MySQL errors now distinguish login rejection (1045), database access (1044), table/column permissions (1142/1143) and required server permission (1227), without exposing raw driver messages or credentials.
- A new additive migration `2026_10_02_010000_add_load_gate_to_db_scan_connections` defaults every existing market to gate On.

Deploy this follow-up:

```bash
cd ~/crm.exotic-online.com
git pull --ff-only
php artisan migrate --force
php artisan config:cache
```

Reload the CRM → Markets → Kenya → Reader connection → Load gate Off → **Save & test connection**. Once verified, choose **Scan this market**. If MySQL still refuses access, follow the specific error and the cPanel checklist; disabling load cannot grant database permissions. Global scanner enablement (`DB_SCANNER_ENABLED=true` plus Schedules & limits → enabled) remains required for scans.

Local verification against base `7d2a78cf`: 14 focused tests / 124 assertions passed (105.64 s); 43 API, lifecycle, safety and scheduler regressions / 426 assertions passed (147.20 s). Changed PHP lint/Pint and production build passed; existing forecast CSS-selector and bundle-size warnings remain. Compiled assets included. Ian owns browser QA and the production connection/scan verification.

## Scoped load override (2 October 2026)

Ian authorized implementation and shipment after the Kenya credential check was blocked by elevated load.

- Admins can submit a reason (10–500 characters) for one credential check or a manual scan of exactly one selected market. The backend creates the scope, actor and expiry; the client cannot choose a duration or extend an existing grant.
- Credential checks get one synchronous attempt (maximum 60-second exception). Manual scans get 15 minutes from admission, including queue wait, one reader for that run and row traversal batches capped at 250. Inventory/aggregate queries retain their existing statement and byte limits.
- Only load levels 1 and 2 are bypassed. Critical level 3, missing/stale load state, emergency stop, scanner off/pause, market health, credentials/preflight, SELECT-only/TLS, market/host leases and daily/time budgets still apply. Preflight remains available while scanning is off.
- Workers recheck an override and load between statements at the existing two-second control cadence. An in-flight query retains its normal timeout (at most 10 seconds). Expiry pauses the run; Resume does not renew it. Use **Revoke & stop**, then a new Scan now if another override is needed.
- The permission is stored in the existing pass scope JSON. No migration or new environment variable. Scheduled runs and sweep continuations never inherit it. A partial sweep continuation waits for normal admission.
- Grants and revocation are audited in the same transaction as the mutation. Runs shows a load-override badge, reason, actor, expiry countdown and **Revoke & stop**. The credential/scan dialog shows the measured signal and threshold when load blocks the request.

### Deploy and run the Kenya canary

The initial migration and pack synchronization already succeeded; this follow-up needs neither repeated migrations nor another pack sync.

```bash
cd ~/crm.exotic-online.com
git pull --ff-only
php artisan config:cache
```

1. Reload the CRM. Open **DB Observatory → Markets → Kenya → Reader connection**, then **Run preflight**.
2. If the load panel appears at level 1 or 2, enter a reason such as `First Kenya canary during production setup`, then **Run credential check once**. Wait for Passed. The override applies to the saved connection.
3. To scan, ensure `.env` has `DB_SCANNER_ENABLED=true`, run `php artisan config:cache` after any edit, and enable the scanner in **Schedules & limits**. Keep schedules disabled during the canary.
4. Choose **Scan now → Quick → Kenya only → Start quick scan**. If load blocks it, enter the reason and click **Run this scan under load**. The existing minute scheduler starts the scanner worker; no new cron or permanent worker is needed.
5. Open the new pass in **Runs** to see the override countdown, progress, findings and coverage. **Revoke & stop** stops this scan and closes its sweep. Critical or stale load has no override button; retry when the load reading permits admission.

### Verification

- Focused override tests cover level-2 scans and smaller batches, metadata-only preflight with scanning off, unchanged global state, admin/single-market/reason validation, critical/missing/stale/health/credential/switch rejection, idempotent retries, expiry, revocation, no inheritance, between-read checks and audit-write rollback.
- Verification on 2 October against base HEAD `50981715`: full `artisan test --filter=DbScanner` passed 58 tests / 585 assertions in 104.88 s (3 opt-in MySQL skips). The subsequently added audit-rollback test passed separately (1 test / 5 assertions), for 59 unique passing tests. Changed PHP lint and Pint passed. `npm run build` passed with existing forecast CSS-selector and bundle-size warnings; compiled assets included. Local tests use disposable SQLite fixtures; this change does not alter the MySQL reader or grant/TLS enforcement.
- Ian owns browser QA and production canary verification. This shipment does not establish a successful production credential check or scan.

## Next action

After this follow-up is pushed, pull in cPanel and follow the deploy/run steps above. Phase 2 (alerts, custom rules, reputation, vulnerability lookups) and phase 3 (files/HTTP probes) remain unstarted.
