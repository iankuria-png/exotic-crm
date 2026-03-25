# Sales Team Activity Tracking V2 Production Execution Plan

## Purpose

This document is the execution companion to:

- [sales-team-activity-tracking-v2-implementation-plan.md](/Users/ian/Projects/exotic-crm/docs/sales-team-activity-tracking-v2-implementation-plan.md)

That V2 file is the architecture and product contract.
This file is the delivery plan we will execute against: tranche order, file scope, testing, regression controls, rollout, and rollback.

## Delivery Principles

1. No commit until the current tranche passes its automated and manual gates.
2. Keep changes horizontally coherent: backend contract first, then frontend consumers.
3. Preserve current role behavior everywhere outside the new Team surface.
4. Prefer stable historical calculations over clever live derivations.
5. Add tests with each tranche instead of deferring them to the end.
6. Use existing components and patterns unless there is a strong reason not to.
7. Use the `playwright` skill during the UI verification tranche for browser-flow validation.

## Context Ledger

These are the invariants we must preserve while implementing:

1. The system keeps the current four roles only: `admin`, `sub_admin`, `sales`, `marketing`.
2. `marketing` is still restricted by the custom router allowlist and the custom sidebar override, so both must be updated together.
3. `routes/api.php` must keep `/crm/team/me` above dynamic `/crm/team/{user}/...` routes.
4. `sendBeacon()` is not viable because this app authenticates API requests with the Axios bearer-token interceptor in [api.js](/Users/ian/Projects/exotic-crm/resources/js/services/api.js#L13).
5. Presence is user-level, but sub-admin visibility is still market-scoped through overlapping accessible platform ids from [MarketAuthorizationService.php](/Users/ian/Projects/exotic-crm/app/Services/MarketAuthorizationService.php#L23).
6. Revenue must be based on `deals.activated_at` and must not depend on the deal's current status.
7. Unfiltered revenue must not flatten multiple currencies into one number.
8. Session history cannot be backfilled before launch; action history can.
9. `subscriptions:check` already runs daily at `00:05` in [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php#L31), so the new nightly job must not pile onto the same minute without intent.
10. There is no dedicated frontend unit-test runner in this repo today; frontend safety comes from `npm run build`, backend feature tests, and browser validation.

## Codebase Fit

Current tooling and patterns we should lean on:

- Backend: Laravel 10, Sanctum, PHPUnit 10
- Frontend: React 19, React Router 7, React Query 5, Vite 6
- Existing factories: `UserFactory`, `PlatformFactory`, `ClientFactory`, `DealFactory`, `ProductFactory`, `ProductPriceFactory`, `PaymentFactory`
- Existing reusable UI pieces:
  - [PageHeader.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/PageHeader.jsx)
  - [MetricCard.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/MetricCard.jsx)
  - [DataTable.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/DataTable.jsx)
  - [SectionFrame.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/SectionFrame.jsx)
  - [StatusBadge.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/StatusBadge.jsx)
  - [FilterSelect.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/FilterSelect.jsx)
  - [Timeline.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/Timeline.jsx)
  - [ConfirmDialog.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/ConfirmDialog.jsx)
- Existing CSV helper pattern:
  - [Reports.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Reports.jsx#L34)

## UI/UX Contract

This feature must ship with an explicit frontend contract, not just backend endpoints.

### Visual system

- Preserve the current CRM design language from [app.css](/Users/ian/Projects/exotic-crm/resources/css/app.css#L1):
  - IBM Plex Sans primary UI typography
  - IBM Plex Mono only for compact technical or numeric accents
  - teal accent and white surfaces
  - rounded-xl card treatment
  - slate borders and low shadow depth
- Reuse existing shared components first:
  - [PageHeader.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/PageHeader.jsx)
  - [MetricCard.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/MetricCard.jsx)
  - [SectionFrame.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/SectionFrame.jsx)
  - [DataTable.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/DataTable.jsx)
  - [StatusBadge.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/StatusBadge.jsx)
  - [FilterSelect.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/FilterSelect.jsx)
  - [ConfirmDialog.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/ConfirmDialog.jsx)
- Do not introduce new font imports, a second color system, or a Team-only design vocabulary unless the change is intentionally promoted to the whole CRM.

### Layout contract

- Desktop layout order:
  - page header
  - KPI summary row
  - tab switcher
  - tab content sections
- Tablet:
  - KPI cards collapse to two columns
  - filter controls wrap cleanly
- Mobile:
  - one-column page flow
  - no page-level horizontal scroll
  - tables may scroll inside their own bounded container only

### Role-based UX contract

- `admin` and `sub_admin` can access Presence, Leaderboard, Agent Detail, and Goals.
- `sales` and `marketing` see only self-service Team content.
- Marketing must still see Team inside the marketing-specific sidebar override and pass the custom router allowlist.
- Agents must never see public ranking UI, even if the API returns extra data in error.
- Managers should land on `Presence` by default.
- Sales and marketing should land on `My Stats` by default.

### Data-display contract

- Revenue display:
  - filtered to one platform: one scalar amount and one currency
  - unfiltered with multiple currencies: display a currency breakdown, not a flattened total
- `total_actions` is the default rank column.
- Active hours may appear in self-service and detail contexts, but not as the primary leaderboard KPI.
- Trend indicators must include text and not rely on color alone.

### Accessibility and interaction best practices

- Visible focus rings on all interactive controls.
- Minimum 44x44 targets for icon buttons, tab pills, and small action controls where practical.
- Icon-only buttons require `aria-label`.
- Keyboard order must match visual order.
- Loading, empty, and error states must exist in every Team tab.
- Primary actions should not be hover-only discoveries.
- Live polling must not cause layout jumps or focus theft.

### Motion and performance best practices

- Keep motion subtle and functional only.
- Use transform/opacity where animation is needed.
- Respect reduced-motion preferences for pulsing online indicators.
- Preserve layout height during async fetches.
- Poll only live views:
  - Presence: 30s
  - Today leaderboard: 30s
  - historical views: no background polling

### Frontend quality bar

Before release, the Team page must be checked at three widths:

- mobile around 390px
- tablet around 768px
- desktop around 1280px+

And it must pass:

- keyboard navigation pass
- no page-level horizontal scroll
- role-based visibility pass
- mixed-currency rendering pass
- default tab behavior pass
- smart-default period persistence pass
- micro-interaction styling pass

## Files In Scope

### New backend files

- `database/migrations/*_create_agent_sessions_table.php`
- `database/migrations/*_create_agent_daily_stats_table.php`
- `database/migrations/*_create_agent_goals_table.php`
- `app/Models/AgentSession.php`
- `app/Models/AgentDailyStat.php`
- `app/Models/AgentGoal.php`
- `app/Services/TeamActivityService.php`
- `app/Http/Controllers/CRM/TeamController.php`
- `app/Console/Commands/CloseStaleSessionsCommand.php`
- `app/Console/Commands/ComputeDailyStatsCommand.php`

### New frontend files

- `resources/js/hooks/useHeartbeat.js`
- `resources/js/pages/Team.jsx`

### Modified backend files

- [AuthController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/AuthController.php)
- [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php)
- [api.php](/Users/ian/Projects/exotic-crm/routes/api.php)

### Modified frontend files

- [useAuth.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useAuth.js)
- [MainLayout.jsx](/Users/ian/Projects/exotic-crm/resources/js/layouts/MainLayout.jsx)
- [Sidebar.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/Sidebar.jsx)
- [router.jsx](/Users/ian/Projects/exotic-crm/resources/js/router.jsx)

### New test files

- `tests/Feature/TeamHeartbeatSessionLifecycleTest.php`
- `tests/Feature/TeamPresenceAuthorizationTest.php`
- `tests/Feature/TeamLeaderboardAggregationTest.php`
- `tests/Feature/TeamGoalsTest.php`
- `tests/Feature/ComputeDailyStatsCommandTest.php`

## Tranche Plan

## Tranche 0: Contract Lock And Test Scaffolding

### Objective

Freeze the contracts that are easiest to break later: revenue semantics, session-token lifecycle, mixed-currency response shape, and role visibility.

### Work

- Create the new test files listed above.
- Establish common test helpers for:
  - authenticated Sanctum users by role
  - platform-scoped users
  - activated deals with explicit `activated_at`, `currency`, `assigned_to`
  - open and stale session rows
- Capture the response shape for leaderboard rows, especially `revenue_display` and `revenue_by_currency`.

### Regression Risks To Guard

- accidentally encoding revenue as a single scalar in all cases
- silently reusing a tab token across different users
- forgetting the marketing-specific router/sidebar override

### Automated Gate

Tests may start as red tests in this tranche, but they must describe:

- per-tab session lifecycle
- sub-admin market scoping
- mixed-currency no-filter leaderboard rows
- stable historical revenue after deal status changes

### Commit Gate

- the core contracts are captured in tests before implementation starts

## Tranche 1: Schema, Models, And Data Contracts

### Objective

Add the new tables and model layer with the right indexes and casts, without wiring any routes yet.

### Files

- migrations for `agent_sessions`, `agent_daily_stats`, `agent_goals`
- `AgentSession`, `AgentDailyStat`, `AgentGoal`

### Work

- create the three migrations with the indexes defined in the V2 plan
- ensure `agent_daily_stats` remains action-only
- add model casts and relations
- keep migration names and column names aligned with the V2 doc

### Regression Risks To Guard

- omitting the `session_token + user_id + ended_at` lookup support
- making `agent_daily_stats` nullable or ambiguous around revenue currency
- storing session totals in the daily stats table

### Automated Gate

Run:

```bash
php artisan test --filter=Team
```

Expected:

- schema-related tests pass

### Commit Gate

- migrations are reversible
- tests pass

## Tranche 2: TeamActivityService And Session Lifecycle

### Objective

Build the service layer first so controller logic stays thin and testable.

### Files

- `app/Services/TeamActivityService.php`
- `app/Models/AgentSession.php`
- `app/Models/AgentDailyStat.php`
- new Team test files

### Work

- implement `recordHeartbeat()`
- implement `closeUserSession()`
- implement `closeStaleSessionsJob()`
- implement presence aggregation with sub-admin overlap filtering
- implement leaderboard rollups for:
  - today from `audit_log`
  - week/month from `agent_daily_stats`
  - session totals from `agent_sessions`
- implement mixed-currency row building:
  - filtered by one platform -> scalar revenue
  - unfiltered -> `revenue_by_currency` plus `revenue_display`
- implement agent stats, self stats, activity feed, and goal progress methods

### Regression Risks To Guard

- session matching by token only instead of `user_id + session_token`
- leaking presence across markets for sub-admins
- returning duplicate agent rows when no platform filter is selected
- cross-currency summation in no-filter views

### Automated Gate

Run:

```bash
php artisan test --filter=TeamHeartbeatSessionLifecycleTest
php artisan test --filter=TeamPresenceAuthorizationTest
php artisan test --filter=TeamLeaderboardAggregationTest
```

Add targeted assertions for:

- stale session rollover
- same token reused by another user closes or isolates the old session safely
- mixed-currency aggregation returns one user row with a currency breakdown
- unfiltered views never return duplicate user rows

### Commit Gate

- all Team service tests pass

## Tranche 3: Controller, Routes, Auth, And Commands

### Objective

Expose the backend safely and wire in logout/session cleanup plus scheduled jobs.

### Files

- [AuthController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/AuthController.php)
- [api.php](/Users/ian/Projects/exotic-crm/routes/api.php)
- [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php)
- `app/Http/Controllers/CRM/TeamController.php`
- `app/Console/Commands/CloseStaleSessionsCommand.php`
- `app/Console/Commands/ComputeDailyStatsCommand.php`

### Work

- add Team routes in the exact safe order
- keep `/crm/team/me` above dynamic user routes
- add role protection for manager-only endpoints
- update logout to accept `session_token`
- close only the current tab's session
- schedule:
  - `crm:close-stale-sessions` every minute with `withoutOverlapping(1)`
  - `crm:compute-daily-stats` at `00:07` with `withoutOverlapping(30)`

### Revenue-specific Work

- implement nightly revenue computation from `deals.activated_at`
- do not use current status as a filter
- count free trials separately

### Regression Risks To Guard

- route order conflicts inside `/crm/team`
- killing all user sessions on logout
- scheduler collisions with existing midnight work
- backfill drift from mutable deal statuses

### Automated Gate

Run:

```bash
php artisan test --filter=ComputeDailyStatsCommandTest
php artisan test --filter=TeamGoalsTest
php artisan test --filter=DealControllerTest
php artisan route:list --path=crm/team
php artisan schedule:list
```

Expected:

- Team routes appear in the correct order
- scheduler entries are present and staggered correctly
- deal tests still pass after revenue-query logic is added

### Commit Gate

- backend routes are complete
- Team backend tests pass
- route and scheduler inspection looks correct

## Tranche 4: Frontend Session Integration

### Objective

Wire in heartbeat and route/nav access without building the full Team page yet.

### Files

- `resources/js/hooks/useHeartbeat.js`
- [useAuth.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useAuth.js)
- [MainLayout.jsx](/Users/ian/Projects/exotic-crm/resources/js/layouts/MainLayout.jsx)
- [Sidebar.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/Sidebar.jsx)
- [router.jsx](/Users/ian/Projects/exotic-crm/resources/js/router.jsx)

### Work

- add a per-tab `session_token` in `sessionStorage`
- also persist a `crm_session_user_id`
- on login:
  - rotate session keys if the logged-in user differs from the stored user id
- on logout:
  - include `session_token`
  - clear both session keys
- add `useHeartbeat()` to `MainLayout`
- add `/team` route
- add Team to the normal workspace nav
- add Team to the marketing override nav
- add `/team` to the marketing allowlist in `ProtectedRoute`

### Regression Risks To Guard

- infinite duplicate heartbeats because multiple `useAuth()` callers reinitialize state inconsistently
- marketing redirect regressions
- tab token surviving account switches

### Automated Gate

Run:

```bash
npm run build
php artisan test --filter=Team
php artisan test --filter=SupportBoardIntegrationTest
php artisan test --filter=CrmPushCampaignTest
```

Why those backend tests matter here:

- `SupportBoardIntegrationTest` already validates role and market access patterns similar to Team access
- `CrmPushCampaignTest` helps catch side effects from touching the marketing navigation and route flow

### Manual Gate

- load the app as `admin`, `sales`, and `marketing`
- confirm `/team` is reachable only where intended
- confirm marketing is not redirected away from `/team`

### Commit Gate

- frontend builds cleanly
- router/sidebar/access behavior is correct

## Tranche 5: Team Page UI

### Objective

Build the actual Team page against the already-tested backend contract.

Use the concrete Team page UX spec from:

- [sales-team-activity-tracking-v2-implementation-plan.md](/Users/ian/Projects/exotic-crm/docs/sales-team-activity-tracking-v2-implementation-plan.md#L363)

### Files

- `resources/js/pages/Team.jsx`
- any minimal helper code colocated with Team page needs

### Work

- build tabs for:
  - Presence
  - Leaderboard
  - My Stats
  - Agent Detail
  - Goals
- reuse existing shared components
- implement CSV export via the `Reports.jsx` helper pattern
- render revenue safely:
  - one currency when filtered
  - `revenue_display` or equivalent breakdown when unfiltered
- make admin/sub-admin tabs hidden for sales and marketing
- keep polling modest:
  - presence: 30s
  - leaderboard today: 30s
  - historical views: no polling
- keep the Team page visually aligned with the existing CRM component vocabulary and spacing scale
- ensure loading, empty, and error states exist for every tab
- ensure no page-level horizontal scrolling at mobile widths
- follow the defined mini wireframe contract, card hierarchy, table cell rules, and mobile column-collapse rules from the implementation plan
- follow the documented smart defaults, micro-interaction rules, and UX principles from the implementation plan

### Regression Risks To Guard

- accidentally exposing leaderboard tabs to agents
- assuming DataTable has built-in CSV export
- treating `revenue_display` as sortable numeric data
- creating a Team page that looks visually detached from the rest of the CRM
- relying on color-only status indicators or inaccessible tab controls

### Automated Gate

Run:

```bash
npm run build
php artisan test --filter=Team
```

### Browser Verification Gate

Use the `playwright` skill here for:

- admin flow to `/team`
- sub-admin flow with market scoping
- sales flow showing only self-service content
- marketing flow confirming sidebar visibility and route access
- CSV export smoke check
- mobile, tablet, and desktop layout checks
- keyboard navigation and focus-state spot checks
- no page-level horizontal-scroll check
- default-tab checks by role
- period-persistence check across reload
- top-3 rank styling, presence-state styling, and trend-indicator spot checks

### Manual Gate

- verify multi-window presence behavior manually because heartbeat timing is easiest to confirm live
- verify no-filter mixed-currency display against seeded or staged data
- verify loading, empty, and error states feel consistent with the rest of the CRM
- verify KPI density and table readability at mobile width
- verify the page still feels non-surveillant in copy and hierarchy: self-service for agents, coaching/overview for managers

### Commit Gate

- build passes
- browser flows pass
- no role leakage in the Team UI

## Tranche 6: Backfill, Hardening, And Release Preparation

### Objective

Prepare the feature for staging and production rollout without surprises.

### Work

- run action-history backfill for a bounded window
- confirm session history remains zero before launch dates
- check command performance on realistic data
- ensure command logs are readable
- verify polling endpoints behave acceptably under repeated refresh

### Commands

```bash
php artisan crm:compute-daily-stats --date=2026-03-23
php artisan crm:compute-daily-stats --date=2026-03-24
php artisan crm:compute-daily-stats --date=2026-03-25
php artisan test --filter=Team
php artisan test --filter=DealControllerTest
php artisan test --filter=SupportBoardIntegrationTest
npm run build
```

### Production Risks To Re-check

- long-running daily backfill on large `audit_log` volume
- multi-currency rows rendering badly in export or table cells
- sub-admin presence scoping missing overlap logic
- stale sessions not clearing if the scheduler is not healthy

### Commit Gate

- staging smoke test is complete
- backfill behaves as expected
- release notes and rollback notes are ready

## Test Plan

### New backend tests to add

`TeamHeartbeatSessionLifecycleTest.php`

- heartbeat creates first session
- fresh heartbeat updates the same row
- stale heartbeat closes the old row and creates a new one
- logout closes only the matching session token
- account switch with reused token does not merge different users

`TeamPresenceAuthorizationTest.php`

- admin sees all online users
- sub-admin only sees overlapping-market users
- sales and marketing cannot access manager-only Team endpoints

`TeamLeaderboardAggregationTest.php`

- today aggregates from `audit_log`
- week/month aggregate from `agent_daily_stats`
- unfiltered aggregation returns one row per agent
- mixed currencies return `revenue_by_currency`
- filtered platform returns scalar revenue and one currency
- historical revenue remains present even if deal status later changes

`TeamGoalsTest.php`

- admin/sub-admin can create and delete goals
- sales and marketing cannot mutate goals
- goal progress returns correct current-period values

`ComputeDailyStatsCommandTest.php`

- command writes one row per `(user_id, platform_id, date)`
- revenue uses `activated_at`
- free trials excluded from revenue
- lead response averages compute from the first contacted event

### Existing regression suites to keep running

- [DealControllerTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/DealControllerTest.php)
- [SupportBoardIntegrationTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/SupportBoardIntegrationTest.php)
- [CrmPushCampaignTest.php](/Users/ian/Projects/exotic-crm/tests/Feature/CrmPushCampaignTest.php)

## Manual Verification Matrix

### Admin

- sees Team in sidebar
- can access all Team tabs
- sees live presence
- sees mixed-currency revenue breakdown correctly when no platform filter is selected
- sees a layout visually consistent with the rest of the CRM
- lands on `Presence` by default

### Sub-admin

- sees Team in sidebar
- can access manager tabs
- only sees users and stats for overlapping markets
- does not see agents outside overlapping market scope in presence or leaderboard views
- lands on `Presence` by default

### Sales

- sees Team in sidebar
- lands on My Stats only
- cannot access manager-only Team endpoints
- does not see leaderboard ranking UI or hidden manager actions
- gets self-service trends, goals, and recent work without manager chrome

### Marketing

- sees Team in sidebar inside the marketing-specific menu path
- is allowed through the router guard to `/team`
- only sees My Stats
- still retains existing push-campaign access
- does not regress into redirect loops or broken sidebar state
- lands on `My Stats` by default

### Multi-session

- same user, two visible windows -> one user, two sessions
- minimize one window -> one session drops after timeout
- logout one window -> only that session closes

## Pre-Commit Checklist

1. Team feature tests pass.
2. Existing regression suites pass.
3. Frontend builds with `npm run build`.
4. `php artisan route:list --path=crm/team` looks correct.
5. `php artisan schedule:list` shows the expected cadence.
6. Browser validation is complete.
7. No unrelated files were modified or reverted.
8. Docs are updated if the implementation diverged from plan.
9. UI/UX contract checks passed at mobile, tablet, and desktop widths.
10. Accessibility spot checks are complete for tabs, filters, tables, and icon-only controls.
11. Default tab, period persistence, and micro-interaction checks are complete.

## Rollout Plan

1. Deploy migrations and code to staging.
2. Run targeted backfill for recent days in staging.
3. Verify admin, sub-admin, sales, and marketing flows.
4. Deploy to production.
5. Run the action-history backfill in production for the chosen date window.
6. Verify scheduler health and presence updates.
7. Do a production smoke test with one admin and one sales user.

## Rollback Plan

If rollout fails:

1. Revert the application code.
2. Leave new tables in place if they are harmless and empty or partially populated.
3. Stop running the new commands by reverting scheduler registration.
4. Remove the Team nav path from the frontend if the backend contract is not trustworthy.
5. Keep backfilled rows as disposable derived data unless they are confirmed valid.

## Definition Of Done

The feature is done when:

1. Presence works reliably for multi-window use.
2. Admin and sub-admin views are correctly scoped.
3. Sales and marketing only get self-service access.
4. Historical revenue is stable across recomputation.
5. Mixed-currency views are explicit and never flattened incorrectly.
6. Automated tests and browser checks pass.
7. Backfill and scheduler behavior are verified.

## Output Path

Saved at:

- `docs/sales-team-activity-tracking-v2-production-execution-plan.md`
