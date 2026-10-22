# Task: daily reporting FX refresh, freshness status and failure alert

- ID / project: reporting-fx-refresh / exotic-crm (evidence check in exotic-ops)
- Updated / status: 22 Oct 2026 — measurement done; code ready locally, uncommitted, not deployed.
- Goal and acceptance criteria: answer the revenue-report FX questions with production evidence;
  quantify 30-day CRM USD vs transaction-date rates by market; confirm per-currency freshness;
  add a daily closing-rate pull, a visible freshness status, and failure alerting (Error Logs).
- Scope authorized by the current request: Ian approved building the daily pull, health check and
  alert, plus the read-only quantification and freshness review. No commit, push or deploy was requested.
- Source plan / decisions / relevant code: `app/Services/ReportingCurrencyService.php` (lookup:
  manual exact date → cached ≤ date within `stale_days` → live fetch only where `allowLiveFetch`;
  Dashboard passes false). New `app/Services/ReportingFxRefreshService.php`, command
  `crm:refresh-fx-rates` (`app/Console/Commands/RefreshReportingFxRates.php`, Kernel 01:42 UTC),
  `refresh` block on `GET /crm/settings/reporting-currency`, `ReportingFxRefreshStatus` in Settings.jsx.
  Decisions: final = fetched after its UTC day closed; final rows are never overwritten; provisional
  intraday rows in the stale window are replaced by the close; no restatement of past days.
- History reconciliation: HEAD 5430494b; no prior FX refresh task or scheduled FX command existed.
- Done with evidence: Ops check `crm-fx-variance-read` (exotic-ops runs 20261022T190400Z…7e40 and
  …190438Z…0055; benchmark via the later run, quota 106/300 used after). Report and CSV:
  `output/fx-reporting-review-2026-10-22/`. Core 30-day CRM USD 62,123.29 vs transaction-date
  62,022.22 = +101.08 (+0.16%); no market material; 0 manual overrides, 0 missing, 0 unresolved.
  Intraday `/latest` captures stored as the day's rate drive the bias (up to 0.6% vs close).
- In progress / remaining: Ian decides commit/push (needs matching `npm run build` — resources/js is
  shared, build from a clean archive if other UI work is dirty) and deploy; optional restatement of
  past intraday rates is a finance decision.
- Next concrete action or command: on approval, ship skill with owned files only; after deploy run
  `php artisan crm:refresh-fx-rates --dry-run` then the real run, and check the settings card.
- Verification: `php artisan test --filter='ReportingFxRefreshTest|ReportingCurrencyServiceTest|ReportingFxDateBoundaryTest|SchedulerConcurrencyTest|ReportingCurrencySurfacesTest'`
  22 passed/180 assertions, 22 Oct 2026, PHP 8.2, HEAD 5430494b plus the owned files below.
  Pint pass on new PHP files. Isolated Vite fixture (real panel, mocked API) checked degraded,
  never-run, expanded list and 375px layout; fixture removed afterwards.
- Deployment: local only.
- Files/hunks owned: new ReportingFxRefreshService.php, RefreshReportingFxRates.php,
  tests/Feature/ReportingFxRefreshTest.php; Kernel.php hunk after `crm_prune_job_batches`;
  SettingsController.php import + `refresh` key in `reportingCurrency()` (file has other sessions'
  hunks near line 2011, preserve); Settings.jsx `ReportingFxRefreshStatus` + its one render line.
  exotic-ops: `remote/php/crm-fx-variance-read.php` and its `remote/checks.json` entry (that file
  has other sessions' uncommitted entries).
- Unresolved questions / blockers: CurrencyAPI plan is 300 requests/month, ~10/minute; the daily pull
  uses ~31. SLE/STN and other non-revenue currencies may be unsupported by the provider; the first
  run will reveal them in Error Logs.
