# Wallet rebates

Updated 4 October 2026. Ian authorized implementing and shipping the r2 WP plan/prototype at `/Users/ian/Local Sites/exotic/app/public/plans/wallet-rebates-2026-10-04/` using its recommended defaults.

Implemented: four additive tables, immutable revisions/reasons, optimistic drafts, kill switch, schedule/audience/environment eligibility, strict initiator markers, pure ordered calculator, locked exactly-once post-settlement grants, retry and reversal/shortfall, wallet payload/summary, market-scoped admin APIs, Billing Rebates editor/journey simulator/real-volume projection/performance/ledger and compiled frontend assets. New programs are Off; Local Kenya fixture 2514 alone is Sandbox. No real-money credits were created by Local sandbox QA.

QA: broad wallet/sync/discount/Send Love regression 77 tests/530 assertions; additional checkout/manual-proof 24/266; final wallet/rebate 36/395; expanded rebate suite 12 tests including Sandbox-to-Live launch-budget transition (overlapping suites). Shared PHP/JS 26 cases; disposable InnoDB migration round-trip, forced post-mutation rollback/retry and concurrent budget cap; genuine Local sandbox settlement and WP config/balance sync; Chromium editor/publish/restore/ledger/performance and 360/390/1440 companion carousel/receipt/FAQ/pause plus compiled French/Swahili copy. Scoped PHP lint and build pass.

Conservative semantics: market budget editable until the first real credit, then fixed for that monthly period; reversals do not reopen issuance caps/budget; first bonus applies only to the first completed production top-up even below the minimum; reporting labels 30-day volume vs current-month issuance and excludes wallet spending from cash revenue. These are documented in the UI/rollout handoff.

Delivery checkpoint: application/build release `51ac400e` pushed to `origin/main` and remote SHA verified. WordPress release `038758431` committed locally and packaged with SHA-256 manifest (41 files). WordPress is a separate local commit/overlay upload, never pushed. Production is not deployed. Next: cPanel pull, `php artisan migrate --force`, `php artisan optimize:clear`, upload WP overlay, verify production owner URLs/assets, then KES 500 canary expecting one KES 15 rebate at defaults. No seeder/new environment variables. Migration: `2026_10_05_000000_create_wallet_rebate_tables.php`. Retry: `php artisan rebates:retry --market=<id> --limit=100`.

Evidence/QA runner/deployment handoff: WordPress plan `implementation.md` and `run-qa.sh`. Durable CRM QA scripts: `scripts/qa/wallet-rebates-{innodb,sandbox,setup}.php`, shared fixtures and browser spec. Local QA sessions are revoked at runner exit. Never run sandbox mutation helpers against production.

Final Local browser pass: 2 tests / 52.8s including actual CRM pause → WordPress config sync → hidden reward UI, followed by resume. QA runner revoked its temporary token/session. No production deployment or live canary.

Deployment edge case fixed: publishing a smaller Live budget updates an existing Sandbox/zero-issuance budget period. Once real credit is issued, edits apply next month. The publishing transaction uses the same program-before-budget lock order as settlement.
