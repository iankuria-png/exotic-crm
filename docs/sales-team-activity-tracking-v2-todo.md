# Sales Team Activity Tracking V2 Implementation Checklist

This is the execution tracker for the production rollout described in:

- `docs/sales-team-activity-tracking-v2-implementation-plan.md`
- `docs/sales-team-activity-tracking-v2-production-execution-plan.md`

## Baseline

- Date locked: `2026-03-25`
- Target repo: `exotic-crm`
- Working branch at start: `main`
- Pre-existing automated baseline:
  - `php artisan test --filter=DealControllerTest` passed
  - `npm run build` passed
  - `php artisan test --filter=SupportBoardIntegrationTest` failed before this feature work
  - Known pre-existing failure details:
    - `tests/Feature/SupportBoardIntegrationTest.php:843`
    - `tests/Feature/SupportBoardIntegrationTest.php:901`
    - expected `sb_user_id` `5678`, received `0`
- Worktree note:
  - `public/build/*` currently contains generated changes from prior verification and must not be staged accidentally outside the frontend tranche

## Execution Rules

- [ ] Do not commit a tranche until its automated gates pass
- [ ] Stage only feature-related files for each tranche
- [ ] Preserve current role behavior outside the Team feature
- [ ] Re-run focused regressions before every commit
- [ ] Record any pre-existing failing tests separately from feature regressions

## Tranche 0: Contract Lock And Test Scaffolding

- [x] Create test files:
  - `tests/Feature/TeamHeartbeatSessionLifecycleTest.php`
  - `tests/Feature/TeamPresenceAuthorizationTest.php`
  - `tests/Feature/TeamLeaderboardAggregationTest.php`
  - `tests/Feature/TeamGoalsTest.php`
  - `tests/Feature/ComputeDailyStatsCommandTest.php`
- [x] Add common helpers/factories usage patterns for:
  - role-authenticated users
  - platform-scoped users
  - activated deals with explicit `activated_at`, `currency`, `assigned_to`
  - open and stale sessions
- [x] Capture contract expectations for:
  - mixed-currency leaderboard responses
  - per-tab session lifecycle
  - sub-admin scoped presence
  - historical revenue stability after status changes
- [x] Verification gate:
  - red or pending tests are acceptable here only if they accurately encode the contract
- [ ] Commit tranche 0

## Tranche 1: Schema, Models, And Data Contracts

- [x] Create migrations:
  - `agent_sessions`
  - `agent_daily_stats`
  - `agent_goals`
- [x] Add models:
  - `AgentSession`
  - `AgentDailyStat`
  - `AgentGoal`
- [x] Verify indexes, casts, and relations match the V2 architecture doc
- [x] Verification gate:
  - `php artisan test --filter=Team`
- [ ] Commit tranche 1

## Tranche 2: TeamActivityService And Session Lifecycle

- [x] Create `app/Services/TeamActivityService.php`
- [x] Implement session lifecycle methods:
  - open session
  - heartbeat
  - close session
  - close stale sessions
- [x] Implement aggregation methods:
  - presence
  - today leaderboard
  - history
  - self stats
  - goals
- [x] Preserve revenue rules:
  - use `deals.activated_at`
  - do not depend on current deal status
  - keep multi-currency unflattened when unfiltered
- [x] Verification gate:
  - `php artisan test --filter=Team`
  - targeted revenue/history coverage inside the new feature tests
- [ ] Commit tranche 2

## Tranche 3: Controller, Routes, Auth, And Commands

- [x] Create `app/Http/Controllers/CRM/TeamController.php`
- [x] Create commands:
  - `app/Console/Commands/CloseStaleSessionsCommand.php`
  - `app/Console/Commands/ComputeDailyStatsCommand.php`
- [x] Update:
  - `app/Http/Controllers/CRM/AuthController.php`
  - `routes/api.php`
  - `app/Console/Kernel.php`
- [x] Ensure route ordering keeps `/crm/team/me` above dynamic Team routes
- [x] Ensure session identity uses `user_id + session_token`
- [x] Stagger nightly scheduler from existing `00:05` job and protect overlap
- [x] Verification gate:
  - `php artisan test --filter=Team`
  - `php artisan route:list | grep team`
  - `php artisan list | grep crm:`
- [ ] Commit tranche 3

## Tranche 4: Frontend Session Integration

- [ ] Create `resources/js/hooks/useHeartbeat.js`
- [ ] Update:
  - `resources/js/hooks/useAuth.js`
  - `resources/js/layouts/MainLayout.jsx`
- [ ] Ensure session token rotation/clearing is safe across login/logout
- [ ] Ensure heartbeat respects visible-tab rules from the V2 UX contract
- [ ] Verification gate:
  - `npm run build`
  - targeted browser verification of login, logout, and heartbeat behavior
- [ ] Commit tranche 4

## Tranche 5: Team Page UI

- [ ] Create `resources/js/pages/Team.jsx`
- [ ] Update:
  - `resources/js/router.jsx`
  - `resources/js/components/Sidebar.jsx`
- [ ] Follow the Team UX contract:
  - tab structure
  - card hierarchy
  - empty/loading/error states
  - table-cell rules
  - mobile behavior
  - micro-interaction rules
- [ ] Preserve marketing allowlist behavior and marketing sidebar override
- [ ] Verification gate:
  - `npm run build`
  - browser validation at mobile, tablet, and desktop widths
  - role visibility pass for admin, sub-admin, sales, marketing
- [ ] Commit tranche 5

## Tranche 6: Backfill, Hardening, And Release Preparation

- [ ] Run backfill for action history only
- [ ] Verify session history expectations are launch-forward only
- [ ] Run final focused regression suite:
  - `php artisan test --filter=Team`
  - `php artisan test --filter=DealControllerTest`
  - `npm run build`
- [ ] Re-check known unrelated failures have not changed in scope
- [ ] Prepare rollout notes and residual risk summary
- [ ] Commit tranche 6 or final hardening changes if needed

## Commit Log

- [ ] Tranche 0 commit created
- [ ] Tranche 1 commit created
- [ ] Tranche 2 commit created
- [ ] Tranche 3 commit created
- [ ] Tranche 4 commit created
- [ ] Tranche 5 commit created
- [ ] Tranche 6 / final hardening commit created
