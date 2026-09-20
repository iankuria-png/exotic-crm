# Task context: lifecycle restore/recovery

- Updated: 2026-09-20. Status: existing implementation; operational verification unknown.
- Goal: avoid restarting implementation from the obsolete July handover.
- Current source exists: `app/Models/LifecycleRestoreRun.php`,
  `app/Support/LifecycleRestoreEligibility.php`, `app/Services/ProfileLifecycleRestoreService.php`,
  `app/Services/LifecycleArchiveRecoveryService.php`, `app/Jobs/RunLifecycleRestoreJob.php`.
- Implementing commits: `66371e69` (12 Sep), `d0dac577` (17 Sep), `7aaaf703`
  (19 Sep) and `824c7671` (20 Sep). The old July handover is consumed historical context;
  inspect `git log -- <affected-path>` / `git show <commit>` before proposing more work.
- Test sources: `tests/Unit/LifecycleRestoreEligibilityTest.php` and
  `tests/Feature/LifecycleRestoreTest.php`. Their current pass/fail result was not checked
  during the context cleanup. Latest inspected commit `824c7671` concerns lifecycle drift.
- Next on a matching request: inspect those current files and the relevant Git history;
  compare requirements from the archived July handover with implemented behavior, then
  run `/usr/local/opt/php@8.2/bin/php artisan test --filter=LifecycleRestore` locally
  after reviewing the test database configuration and prerequisites.
- Keep historical requirements: configurable eligibility/pacing, trackable cohorts,
  preview/revert, and the intended Clients-page workflow. Current code defines what exists.
- Production: no live backfill without explicit authorization and Ian present; default
  to dry-run. Follow `prod-debug` for any authorized data repair. No production action is
  authorized by creating this context record.
