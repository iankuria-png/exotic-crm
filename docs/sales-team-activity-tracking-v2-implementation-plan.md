# Sales Team Activity Tracking V2 Implementation Plan

## Purpose

This is the execution-ready V2 of the activity-tracking plan for the CRM. It keeps the same product goal as V1, but tightens the parts that were still risky in review:

- revenue is now based on activation events, not mutable current deal status
- mixed-currency reporting has an explicit backend and UI contract
- per-tab session tokens are rotated and matched safely
- new scheduler jobs are staggered and overlap-protected

The scope remains activity tracking only. Roles and permissions stay unchanged: `admin`, `sub_admin`, `sales`, `marketing`.

## Goals

- Show who is online right now
- Give admins and sub-admins a leaderboard and agent drill-downs
- Give every user a personal "My Stats" view
- Track market-scoped actions without introducing surveillance-style metrics
- Keep historical reporting stable when records evolve later

## Core Decisions

| Decision | V2 choice | Why |
|----------|-----------|-----|
| Presence tracking | `agent_sessions` with per-tab UUIDs | Supports multi-window presence without merging sessions |
| Leaderboard rank metric | `total_actions` | Encourages productive actions instead of tab parking |
| Active hours | Tracked, but not ranked | Useful for presence and self-review, not for competition |
| Revenue source | `deals.activated_at` + `assigned_to` + `is_free_trial = false` | Stable over time and tied to actual activation |
| Revenue currency handling | Single currency only when platform filter is set; otherwise return `revenue_by_currency` | Avoids invalid cross-currency summation |
| Historical action stats | `agent_daily_stats` | Fast rollups for week/month views |
| Historical session stats | Query `agent_sessions` directly | Sessions are user-level, not platform-level |
| Session lifecycle | Match by `user_id + session_token`; rotate token on login/logout transitions | Prevents stale-tab and cross-account leakage |

## KPI Mapping

Derived from what the system already records today.

| Metric | Source | Rule |
|--------|--------|------|
| Profiles created | `audit_log.action = client_create` | Count entries |
| Subscriptions activated | `audit_log.action = deal_activate` | Count entries |
| Subscriptions renewed | `audit_log.action = deal_renew` | Count entries |
| Free trials approved | `audit_log.action = deal_free_trial` | Count entries |
| Payments matched | `payment_match_confirm`, `payment_match_auto` | Exclude `payment_match_batch` |
| Subscriptions created | `payment_create_subscription` | Count entries |
| SMS sent | `conversation_sms_sent`, `renewal_sms_sent` | Count entries |
| Leads contacted | `lead_status_update` where `after_state.status = contacted` | Matches current lead flow |
| Leads converted | `lead_convert_to_client` | Count entries |
| Chat replies | `support_chat_reply` | Already emitted today |
| Credentials dispatched | `client_credential_send` | Count entries |
| Revenue recognized | `deals` table | Sum `amount` where `assigned_to = agent`, `activated_at` in range, `is_free_trial = false` |

### Revenue rules

Revenue is the most sensitive part of this feature, so V2 makes the rules explicit:

1. Revenue is recognized from the deal row that was activated in the period.
2. Revenue is not filtered by the deal's current status.
3. Free trials do not contribute revenue.
4. Renewals count from the new renewal deal row that receives `activated_at`, not from the old deal that later becomes `renewed`.
5. Platform-scoped views may show a single `revenue_total` and `revenue_currency`.
6. Unfiltered all-platform views must not flatten multiple currencies into one number.

### Revenue query contract

For nightly stats:

```sql
SELECT
  assigned_to AS user_id,
  platform_id,
  DATE(activated_at) AS activity_date,
  currency,
  SUM(amount) AS revenue
FROM deals
WHERE activated_at >= :start
  AND activated_at < :end
  AND activated_at IS NOT NULL
  AND assigned_to IS NOT NULL
  AND is_free_trial = 0
GROUP BY assigned_to, platform_id, DATE(activated_at), currency
```

Important:

- Do not add `status IN ('active','renewed')`.
- Historical recomputes must stay stable even if the deal later becomes `expired` or `cancelled`.

## Database

### New table: `agent_sessions`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| user_id | bigint unsigned FK -> users | |
| session_token | varchar(36) | UUID per browser tab |
| started_at | datetime | First accepted heartbeat |
| last_heartbeat_at | datetime | Updated every heartbeat |
| ended_at | datetime nullable | Set on timeout or explicit logout |
| ip_address | varchar(45) nullable | |
| user_agent | varchar(500) nullable | |

Indexes:

- `(user_id, ended_at, last_heartbeat_at)`
- `(session_token, user_id, ended_at)`
- `(last_heartbeat_at)`

### New table: `agent_daily_stats`

Per-platform, action-only daily rollups.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| user_id | bigint unsigned FK -> users | |
| platform_id | bigint unsigned FK -> platforms | |
| date | date | |
| profiles_created | smallint unsigned default 0 | |
| subs_activated | smallint unsigned default 0 | |
| subs_renewed | smallint unsigned default 0 | |
| payments_matched | smallint unsigned default 0 | |
| subscriptions_created | smallint unsigned default 0 | |
| leads_contacted | smallint unsigned default 0 | |
| leads_converted | smallint unsigned default 0 | |
| chats_replied | smallint unsigned default 0 | |
| sms_sent | smallint unsigned default 0 | |
| credentials_sent | smallint unsigned default 0 | |
| revenue | decimal(12,2) unsigned default 0 | Sum of activated non-free-trial deals for that user/platform/date |
| revenue_currency | varchar(3) | Currency for that platform row |
| free_trials_given | smallint unsigned default 0 | |
| avg_lead_response_secs | int unsigned nullable | |
| total_actions | smallint unsigned default 0 | |

Unique index:

- `(user_id, platform_id, date)`

### New table: `agent_goals`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| platform_id | bigint unsigned FK -> platforms nullable | `null` = global |
| metric | varchar(50) | |
| target | int unsigned | |
| period | enum('weekly','monthly') | |
| set_by | bigint unsigned FK -> users | |
| timestamps | | |

Unique index:

- `(platform_id, metric, period)`

## Backend

### New service: `app/Services/TeamActivityService.php`

#### Heartbeat

```php
recordHeartbeat(User $user, string $sessionToken, string $ip, string $ua): void
```

Rules:

1. Match open sessions by `user_id = $user->id` and `session_token = $sessionToken`.
2. If found and fresh, update `last_heartbeat_at`.
3. If found but stale, close it and create a new row.
4. Before creating a new row, close any open row using the same `session_token` for a different `user_id`.
5. Never update another user's session just because the token matches.

```php
closeUserSession(User $user, string $sessionToken): void
```

- Closes only the matching open row for that user and tab token.

```php
closeStaleSessionsJob(): void
```

- Closes sessions where `ended_at IS NULL` and `last_heartbeat_at < NOW() - INTERVAL 2 MINUTE`.

#### Presence

```php
getPresence(User $viewer): array
```

- Users are online if they have any open session with heartbeat in the last 2 minutes.
- Admin sees all users.
- Sub-admin sees only users whose accessible platforms overlap with the sub-admin's accessible platforms.
- Presence filtering must happen at the user set level because session rows are not platform-scoped.
- Multiple open sessions per user are grouped into one presence card.

Returns:

- `user_id`
- `name`
- `role`
- `is_online`
- `session_count`
- `current_session_duration_seconds`
- `last_seen_at`
- `last_action`

#### Leaderboard

```php
getLeaderboard(string $period, ?int $platformId, User $viewer): array
```

Action aggregation:

- `today`: aggregate from `audit_log`
- `week` and `month`: aggregate from `agent_daily_stats`
- always return one row per user

Session aggregation:

- query `agent_sessions` separately using overlap filtering with clamped bounds
- join session totals back by `user_id`

Revenue aggregation:

- if `platform_id` filter is set:
  - return a single `revenue_total`
  - return `revenue_currency`
  - render as a normal currency value
- if no platform filter is set:
  - aggregate by `user_id + currency`
  - return `revenue_by_currency`
  - also return `revenue_display` for direct UI use
  - do not collapse mixed currencies into one scalar total

Example unfiltered row shape:

```json
{
  "user_id": 12,
  "name": "Jane",
  "total_actions": 67,
  "subs_activated": 12,
  "subs_renewed": 4,
  "revenue_by_currency": [
    { "currency": "KES", "amount": "48000.00" },
    { "currency": "TZS", "amount": "120000.00" }
  ],
  "revenue_display": "KES 48,000 | TZS 120,000"
}
```

#### Agent stats

```php
getAgentStats(User $agent, string $from, string $to, ?int $platformId): array
getMyStats(User $user): array
getAgentActivityFeed(User $agent, string $date, ?int $platformId): array
```

Rules:

- action stats come from `agent_daily_stats`
- session totals come from `agent_sessions`
- revenue follows the same currency rules as leaderboard
- personal views can show revenue by currency when the selected period spans multiple markets

#### Goals

```php
getGoals(?int $platformId): array
setGoal(string $metric, int $target, string $period, ?int $platformId, User $setter): AgentGoal
deleteGoal(int $goalId): void
getGoalProgress(User $user, ?int $platformId): array
```

#### Nightly computation

```php
computeDailyStats(Carbon $date): void
```

Rules:

1. Aggregate audit-log metrics by `(actor_id, platform_id, date)`.
2. Aggregate revenue from `deals.activated_at`, not current status.
3. Count free trials separately from `deal_free_trial`.
4. Upsert one row per `(user_id, platform_id, date)`.
5. Do not write session totals into `agent_daily_stats`.

## Controller and Routes

### New controller: `app/Http/Controllers/CRM/TeamController.php`

| Method | Route | Access |
|--------|-------|--------|
| `heartbeat` | `POST /api/crm/heartbeat` | all authenticated users |
| `presence` | `GET /api/crm/team/presence` | `admin`, `sub_admin` |
| `leaderboard` | `GET /api/crm/team/leaderboard` | `admin`, `sub_admin` |
| `agentStats` | `GET /api/crm/team/{user}/stats` | `admin`, `sub_admin` |
| `activityFeed` | `GET /api/crm/team/{user}/activity` | `admin`, `sub_admin` |
| `myStats` | `GET /api/crm/team/me` | all authenticated users |
| `goals` | `GET /api/crm/team/goals` | `admin`, `sub_admin` |
| `setGoal` | `POST /api/crm/team/goals` | `admin`, `sub_admin` |
| `deleteGoal` | `DELETE /api/crm/team/goals/{goal}` | `admin`, `sub_admin` |

Route order inside `routes/api.php`:

```php
Route::post('/heartbeat', [TeamController::class, 'heartbeat']);
Route::get('/team/me', [TeamController::class, 'myStats']);

Route::middleware('role:admin,sub_admin')->prefix('team')->group(function () {
    Route::get('/presence', [TeamController::class, 'presence']);
    Route::get('/leaderboard', [TeamController::class, 'leaderboard']);
    Route::get('/goals', [TeamController::class, 'goals']);
    Route::post('/goals', [TeamController::class, 'setGoal']);
    Route::delete('/goals/{goal}', [TeamController::class, 'deleteGoal']);
    Route::get('/{user}/stats', [TeamController::class, 'agentStats']);
    Route::get('/{user}/activity', [TeamController::class, 'activityFeed']);
});
```

Keep `/team/me` above the `/{user}` routes.

### Auth change

`app/Http/Controllers/CRM/AuthController.php`

- `logout()` must accept the current tab's `session_token`
- it must close only that tab's session
- successful logout should not close the user's other active sessions

## Frontend

### UI/UX contract

The Team feature must extend the existing CRM visual system instead of introducing a parallel design language.

#### Design system alignment

- Preserve the current typography and token system from [app.css](/Users/ian/Projects/exotic-crm/resources/css/app.css#L1):
  - IBM Plex Sans for primary UI
  - IBM Plex Mono only for compact numeric or technical secondary data
  - teal as the primary accent color
  - white surfaces on the existing slate background
  - rounded-xl card geometry, slate borders, and low-elevation shadows
- Reuse the existing CRM primitives wherever possible:
  - [PageHeader.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/PageHeader.jsx)
  - [MetricCard.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/MetricCard.jsx)
  - [SectionFrame.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/SectionFrame.jsx)
  - [DataTable.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/DataTable.jsx)
  - [StatusBadge.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/StatusBadge.jsx)
  - [FilterSelect.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/FilterSelect.jsx)
  - [ConfirmDialog.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/ConfirmDialog.jsx)
- Do not add new global fonts, a separate color palette, or an isolated Team-only card style.
- If new global utility classes are introduced, they should use the existing `crm-*` convention and be reusable beyond this page.

#### Layout contract

- Desktop order:
  - page header
  - KPI summary row
  - tab switcher
  - tab content sections
- Tablet:
  - KPI cards collapse to two columns
  - filters stack cleanly
- Mobile:
  - one-column layout
  - no page-level horizontal scrolling
  - tables may scroll only inside their own table container
- Presence cards and stats blocks should keep the current CRM spacing rhythm rather than switching to a denser dashboard style.

#### Interaction contract

- Primary actions must be visible without hover-only discovery.
- Polling views must refresh without layout jumps or focus loss.
- "Today" views may auto-refresh; historical views should remain stable unless the user refreshes or changes filters.
- Sales and marketing users must never see public ranking or manager-only actions.

#### Accessibility contract

- Maintain visible focus states on all interactive elements.
- Keep touch targets at least 44x44 where practical for pills, icon buttons, and tab controls.
- Do not rely on color alone for online/offline, positive/negative trends, or goal status.
- Icon-only controls must include accessible labels.
- Each Team tab must have clear loading, empty, and error states.

#### Data presentation contract

- Revenue rules:
  - one platform filter: show one scalar value with one currency
  - no platform filter and multiple currencies: show a currency breakdown, never a flattened pseudo-total
- `total_actions` remains the default ranking metric.
- Active hours may appear in self-service or detail views, but not as the main leaderboard column.
- Trend values should combine iconography and text, for example `+15% vs last week`.

#### Motion and performance contract

- Motion should stay subtle and functional.
- Prefer transform/opacity transitions over layout-triggering animation.
- Respect reduced-motion preferences for pulsing live-status indicators.
- Loading states should preserve content height to avoid jumping layouts.

### New hook: `resources/js/hooks/useHeartbeat.js`

Responsibilities:

1. Generate a tab UUID with `crypto.randomUUID()`.
2. Store it in `sessionStorage`.
3. Store a second key with the current authenticated user id, for example `crm_session_user_id`.
4. If the stored user id does not match the current user id, rotate the token and overwrite both keys.
5. POST heartbeat every 60 seconds only when `document.visibilityState === 'visible'`.
6. Do not use `sendBeacon()`.
7. Cleanup interval on unmount.

### Update: `resources/js/hooks/useAuth.js`

On login:

- after successful login, compare the stored session user id with `data.user.id`
- if different, clear old session keys so the heartbeat hook starts a fresh tab session for the new account

On logout:

- include `session_token` in the logout request body
- on successful or final cleanup, remove both session keys from `sessionStorage`

### New page: `resources/js/pages/Team.jsx`

Visible to all roles.

- `sales` and `marketing` default to "My Stats"
- `admin` and `sub_admin` get Presence, Leaderboard, Agent Detail, and Goals

#### Concrete Team page UX spec

##### Page shell

- Use [PageHeader.jsx](/Users/ian/Projects/exotic-crm/resources/js/components/PageHeader.jsx) at the top.
- Title: `Team`
- Subtitle:
  - managers: `Live presence, performance, and coaching signals across your sales team.`
  - agents: `Track your progress, goals, and recent activity.`
- Header actions:
  - managers: period selector and platform filter
  - agents: period selector only

##### Mini wireframe contract

Manager view:

```text
+---------------------------------------------------------------+
| Team                                   [Period] [Market]      |
| Live presence, performance, and coaching signals...           |
+---------------------------------------------------------------+
| KPI 1 | KPI 2 | KPI 3 | KPI 4                                 |
+---------------------------------------------------------------+
| [Presence] [Leaderboard] [My Stats] [Goals]                  |
+---------------------------------------------------------------+
| Tab content card stack                                        |
| - primary section                                             |
| - secondary section                                           |
| - detail or activity section                                  |
+---------------------------------------------------------------+
```

Agent view:

```text
+---------------------------------------------------------------+
| Team                                           [Period]       |
| Track your progress, goals, and recent activity.              |
+---------------------------------------------------------------+
| Personal KPI row                                               |
+---------------------------------------------------------------+
| My Stats content only                                          |
| - current metrics                                              |
| - goal progress                                                |
| - recent activity                                              |
+---------------------------------------------------------------+
```

##### Tab structure

- Managers:
  - `Presence`
  - `Leaderboard`
  - `My Stats`
  - `Goals`
  - `Agent Detail` is contextual and opens when a person is selected; it should not appear as a dead tab before selection
- Sales and marketing:
  - only `My Stats`
  - if only one tab is available, omit the full tab strip and render a simple section header instead

##### Smart defaults contract

- Manager default landing tab: `Presence`
- Sales and marketing default landing tab: `My Stats`
- Default period for all roles: `This Week`
- Default market filter for managers: `All`
- Persist the selected period in `localStorage` so returning users keep their last chosen period
- Do not persist a stale `Agent Detail` selection across sessions; detail state is contextual and should reset on a fresh visit

##### Card hierarchy

Top level:

- 1 page header
- 1 KPI summary row
- 1 navigation strip or single-tab label
- 1 to 3 `SectionFrame` blocks inside the active tab

Presence tab:

- section 1: online user card grid
- section 2: offline or recently seen list if useful

Leaderboard tab:

- section 1: filter row
- section 2: leaderboard table
- section 3: selected agent preview or summary footer if useful

My Stats tab:

- section 1: personal KPI row
- section 2: goals and trend summary
- section 3: recent activity feed

Agent Detail tab:

- section 1: agent identity and status header
- section 2: KPI row
- section 3: trend blocks
- section 4: activity timeline

Goals tab:

- section 1: create-goal controls
- section 2: current goal list
- section 3: per-agent progress bars

##### Empty, loading, and error states

Use the same style as existing empty states in:

- [Dashboard.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Dashboard.jsx#L100)
- [Reports.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Reports.jsx#L91)

Presence empty state:

- title: `No one is online right now`
- message: `Active sessions will appear here when agents have the CRM open in a visible window.`

Leaderboard empty state:

- title: `No tracked activity for this period`
- message: `Try switching to Today or clearing the market filter.`

My Stats empty state:

- title: `No activity yet`
- message: `Your calls, chats, leads, and subscription actions will appear here once you start working.`

Goals empty state:

- managers: `No goals have been set for this scope yet.`
- agents: `No goals have been assigned for this period.`

Agent Detail empty state:

- title: `Select an agent`
- message: `Choose someone from Presence or Leaderboard to inspect their detailed activity.`

Loading state rules:

- preserve final layout height
- use skeleton blocks or the existing loading treatments instead of spinner-only blank screens
- do not flash the whole page on 30-second refetches

Error state rules:

- show inline tab-level error banners or empty cards
- use toast notifications for mutations, not for passive data refetch errors

##### Leaderboard table cell rules

Rank cell:

- fixed narrow width
- top 3 get medal or highlight treatment
- all others show numeric rank only

Agent cell:

- avatar initial
- full name
- small secondary line with role or status
- optional live online indicator for "today" views

Revenue cell:

- right-aligned
- use `crm-mono` for numbers
- one platform filter: single-line scalar value
- no platform filter and multiple currencies: stacked values or inline breakdown, for example:
  - `KES 48,000`
  - `TZS 120,000`
- never truncate away a currency label

Metric cells:

- right-aligned numeric values
- use tabular visual rhythm where possible
- `Total Actions` should have the strongest emphasis among numeric columns
- unavailable values render as `--`

Sorting rules:

- default sort is `total_actions desc`
- if revenue is mixed-currency in the current dataset, do not treat the rendered revenue cell as a sortable scalar
- clicking a row opens `Agent Detail`

##### Mobile behavior contract

- below tablet width:
  - KPI cards collapse to a single column
  - filters become full-width stacked controls
  - manager tab strip becomes a horizontally scrollable pill row
  - presence cards become one column
- leaderboard on mobile:
  - use a compact column set by default:
    - Rank
    - Agent
    - Revenue
    - Total Actions
  - deeper detail comes from tapping the row into `Agent Detail`
- agent detail on mobile:
  - KPI cards stack
  - trend and activity sections become one column
- goals on mobile:
  - form controls stack vertically
  - progress rows wrap without clipping labels

##### Copy and tone contract

- Use operational, plain-English language.
- Avoid surveillance framing such as `monitor`, `tracking every move`, or `idle detection`.
- Prefer terms like `activity`, `progress`, `presence`, `goal progress`, and `recent work`.

##### Micro-interaction contract

Presence cards:

- Online state uses a pulsing emerald dot plus text, not color alone
- Offline state uses a static neutral dot plus "Last seen" text
- Avatar initials use the same teal-to-cyan gradient language already used in the sidebar
- Clicking anywhere on a presence card opens `Agent Detail`

Leaderboard:

- Top 3 rank cells use distinct gold, silver, and bronze styling
- Non-top-3 rank cells use plain numeric rank
- Row hover follows the existing table hover pattern already used elsewhere in the CRM
- Clicking a row opens `Agent Detail`

Trend indicators:

- Improvement uses an upward indicator plus positive text styling
- Decline uses a downward indicator plus negative text styling
- Flat performance uses a neutral indicator and neutral copy
- Trend copy should read like `+15% vs last week`, not just a bare arrow

Goal progress:

- Progress bars should follow the same visual language as the existing CRM progress treatments
- Each goal row must show absolute progress and percentage together, for example `12/15 (80%)`

No-alert-fatigue rule:

- Heartbeat failures stay silent
- Passive refetch errors should not spam toasts
- Presence changes should not trigger notification noise

##### UX principles applied

| Principle | Implementation |
|-----------|---------------|
| No surveillance feeling | Agents see self-service stats only. Active hours are de-emphasized and heartbeat stays invisible. |
| Immediate value | Agents get useful personal progress, goals, and recent work context instead of manager-only reporting. |
| Progressive disclosure | Managers start from Presence, then drill into Leaderboard, then Agent Detail. |
| Consistent design language | Team uses existing `MetricCard`, `SectionFrame`, `DataTable`, `StatusBadge`, and CRM spacing/tokens. |
| Contextual actions | Activity entries should link to the relevant client, lead, or deal whenever the entity exists. |
| Smart defaults | Managers land on `Presence`, agents on `My Stats`, and the default period is `This Week`. |
| No alert fatigue | Background heartbeat and stale-session cleanup happen quietly. |

##### Role walkthrough contract

Sales or marketing user:

- opens `Team`
- lands directly in self-service progress
- sees current KPIs, goals, recent activity, and trend context
- never sees public ranking UI

Admin or sub-admin:

- opens `Team`
- lands on `Presence`
- scans who is online first
- moves to `Leaderboard` for weekly or monthly performance
- drills into `Agent Detail` only when someone needs coaching or review

#### KPI row

Admin and sub-admin only:

- Online Now
- Active Today
- Total Actions Today
- Goal Completion

#### Presence tab

- online/offline cards
- grouped session count
- refresh every 30 seconds

#### Leaderboard tab

Columns:

- Rank
- Agent
- Revenue
- Subs Activated
- Subs Renewed
- Payments Matched
- Leads Contacted
- Chats
- SMS
- Total Actions

Revenue cell rules:

- filtered by one platform: show `KES 5,000`
- no platform filter and one currency: show one value
- no platform filter and multiple currencies: show stacked or inline currency values, for example `KES 48,000 | TZS 120,000`

CSV export:

- implemented in page logic
- export `revenue_display`
- if useful for downstream analysis, also include `revenue_breakdown_json`

#### My Stats tab

- current period metrics
- trend vs prior period
- goal progress
- recent activity
- sparkline

#### Agent Detail tab

- selected agent header
- KPI cards
- trend comparison
- date picker
- activity feed

#### Goals tab

- list goals
- add goal
- delete goal with confirmation
- per-agent progress bars

### Existing frontend files to update

`resources/js/layouts/MainLayout.jsx`

- call `useHeartbeat()` once inside the authenticated shell

`resources/js/components/Sidebar.jsx`

- add Team to the normal Workspace group
- also add Team to the marketing override menu

`resources/js/router.jsx`

- add `/team`
- extend the marketing allowlist to include `/team`

## Scheduler

### New command: `crm:close-stale-sessions`

- runs every minute
- use `withoutOverlapping(1)`
- use `onOneServer()`

### New command: `crm:compute-daily-stats`

- default date: yesterday
- schedule at `00:07`
- use `withoutOverlapping(30)`
- use `onOneServer()`

Reason:

- `subscriptions:check` already runs at `00:05`
- staggering reduces avoidable contention in `Kernel.php`

## Build Sequence

1. Create migrations for `agent_sessions`, `agent_daily_stats`, `agent_goals`
2. Add `AgentSession`, `AgentDailyStat`, `AgentGoal`
3. Build `TeamActivityService`
4. Add `TeamController`
5. Register routes
6. Add artisan commands and scheduler entries
7. Update `AuthController` logout flow
8. Update `useAuth.js` session key lifecycle
9. Add `useHeartbeat.js`
10. Wire heartbeat into `MainLayout.jsx`
11. Build `Team.jsx`
12. Update `Sidebar.jsx`
13. Update `router.jsx`
14. Backfill historical action data for the last 30 days

## Backfill rules

- Backfill only action-based history
- Session history cannot be reconstructed for dates before launch
- Historical views before launch may show valid action totals but zero session time
- Revenue backfill is safe because it comes from `deals.activated_at`

## Files to Create

- `database/migrations/xxxx_create_agent_sessions_table.php`
- `database/migrations/xxxx_create_agent_daily_stats_table.php`
- `database/migrations/xxxx_create_agent_goals_table.php`
- `app/Models/AgentSession.php`
- `app/Models/AgentDailyStat.php`
- `app/Models/AgentGoal.php`
- `app/Services/TeamActivityService.php`
- `app/Http/Controllers/CRM/TeamController.php`
- `app/Console/Commands/CloseStaleSessionsCommand.php`
- `app/Console/Commands/ComputeDailyStatsCommand.php`
- `resources/js/hooks/useHeartbeat.js`
- `resources/js/pages/Team.jsx`

## Files to Modify

- `app/Http/Controllers/CRM/AuthController.php`
- `app/Console/Kernel.php`
- `routes/api.php`
- `resources/js/hooks/useAuth.js`
- `resources/js/layouts/MainLayout.jsx`
- `resources/js/components/Sidebar.jsx`
- `resources/js/router.jsx`

## Verification

1. Open CRM in two visible windows as the same user -> presence shows one user with two sessions.
2. Minimize one window -> after two minutes it expires -> presence shows one session.
3. Log in as two different users in two different browsers -> presence shows both online.
4. Log out one user -> only that user's current tab session closes.
5. Log into account A in one tab, then log out and log into account B in the same tab -> old session token is rotated and no presence leakage occurs.
6. Create a client, activate a deal, contact a lead, reply to chat -> today's leaderboard increments immediately.
7. Activate a paid deal on March 20, later let it expire, then run `crm:compute-daily-stats --date=2026-03-20` -> historical revenue for March 20 still includes it.
8. Approve a free trial -> free trial count increases but revenue does not.
9. For an agent active in Kenya and Tanzania, no-filter leaderboard shows revenue split by currency, not flattened into one number.
10. With a platform filter selected, revenue shows as a single value in that platform's currency.
11. Log in as `marketing` -> Team appears in the sidebar and `/team` is not redirected away.
12. Log in as `sales` -> Team loads and only self-service content is available.
13. Run the scheduler locally and confirm `crm:compute-daily-stats` is staggered after `subscriptions:check`.
14. Verify Team renders cleanly at mobile, tablet, and desktop widths with no page-level horizontal scroll.
15. Verify keyboard users can reach tab controls, filters, and row actions with visible focus states.
16. Verify every Team tab has explicit loading, empty, and error states that do not cause layout jumping.
17. Verify managers land on `Presence`, while sales and marketing land on `My Stats`.
18. Verify the selected period persists across reloads and defaults to `This Week` on first visit.
19. Verify presence dots, top-3 rank styling, trend indicators, and goal progress visuals follow the documented interaction contract.

## Recommended Output Path

This V2 plan lives in the repository so it can travel with the codebase:

- `docs/sales-team-activity-tracking-v2-implementation-plan.md`
