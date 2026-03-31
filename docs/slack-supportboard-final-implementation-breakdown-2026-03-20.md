# Slack + Support Board Final Implementation Breakdown

**Date:** 2026-03-20  
**Status:** Final implementation breakdown for pilot and production rollout  
**Scope:** Slack-first notification workflow, CRM-owned Support Board ingest, and dashboard message triage  
**Out of scope for phase 1:** Discord

---

## 1) Final decisions

These decisions are now fixed for implementation:

1. **Slack is the only notification platform in phase 1.**
2. **The CRM is the routing brain.**
3. **Support Board is the chat source, not the routing source.**
4. **Market ownership comes from the CRM, not Support Board departments.**
5. **The dashboard gets a message triage widget with two tabs.**
6. **Slack Free is acceptable for the pilot, but the long-term production design assumes Slack Pro later.**

---

## 2) What success looks like

When a customer replies in Support Board:

1. The CRM detects the new message.
2. The CRM resolves the customer to a CRM record if one exists.
3. The CRM determines the market from `platform_id`.
4. The CRM determines the owner from `assigned_to`.
5. The CRM posts the alert to the market Slack channel.
6. If the customer is assigned, the CRM mentions or DMs the assigned agent.
7. The dashboard immediately shows the item in the correct message triage tab.

Example:

- User X replies in Support Board
- User X is linked to a CRM client in Kenya
- Client is assigned to Agent A
- CRM posts into Kenya Slack channel
- CRM mentions Agent A in that Kenya channel
- CRM shows the item under `Client Replies`

If User X is not yet linked to CRM:

- CRM still identifies the market from the Support Board platform integration
- CRM posts into the Kenya channel
- CRM shows the item under `Needs Matching`

---

## 3) Final architecture

## 3.1 Source of truth

- **Payments:** CRM after webhook verification
- **Chat replies:** Support Board as message source, CRM as event processor and router
- **Market ownership:** CRM
- **Slack delivery:** CRM

## 3.2 Core flow

### Payments

1. Provider webhook reaches CRM
2. CRM verifies payload
3. CRM updates payment state
4. CRM emits normalized event
5. Router resolves market + owner
6. Slack delivery job posts alert
7. Dashboard and in-app state update

### Support chat

1. Customer reply lands in Support Board
2. CRM ingest worker fetches new Support Board activity
3. CRM stores normalized message record
4. CRM resolves known client or a chat that needs matching
5. CRM emits normalized event
6. Router resolves market + owner
7. Slack delivery job posts alert
8. Dashboard triage widget updates

---

## 4) Existing code anchors

These are the most relevant current files for implementation:

- [Platform.php](/Users/ian/Projects/exotic-crm/app/Models/Platform.php)
- [User.php](/Users/ian/Projects/exotic-crm/app/Models/User.php)
- [Client.php](/Users/ian/Projects/exotic-crm/app/Models/Client.php)
- [SupportBoardService.php](/Users/ian/Projects/exotic-crm/app/Services/SupportBoardService.php)
- [BillingGatewayService.php](/Users/ian/Projects/exotic-crm/app/Services/BillingGatewayService.php)
- [MarketAuthorizationService.php](/Users/ian/Projects/exotic-crm/app/Services/MarketAuthorizationService.php)
- [DashboardController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/DashboardController.php)
- [Dashboard.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Dashboard.jsx)
- [useDashboardWidgets.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useDashboardWidgets.js)
- [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php)

---

## 5) Data model changes

## 5.1 Add Slack member mapping to users

### Table change

Add to `users`:

- `slack_member_id` nullable string

### Why

This lets the CRM mention or DM a specific agent in Slack, even on the Free plan.

### UI implication

Add a Slack member ID field to CRM user settings alongside:

- role
- assigned markets
- `sb_agent_id`

## 5.2 Add Slack channel mapping per market

### Preferred storage

Store per market/platform:

- `slack_enabled`
- `slack_channel_id`
- `slack_channel_name`
- `slack_escalation_channel_id` nullable
- `slack_user_group_handle` nullable for future Pro use

### Storage options

Option A:

- new table `platform_notification_settings`

Option B:

- structured JSON in `integration_settings`

### Recommendation

Use a dedicated table if possible. It will age better than packing more logic into a single JSON settings blob.

## 5.3 Add Support Board ingest state

### New table

`support_board_ingest_states`

### Fields

- `platform_id`
- `last_seen_conversation_id`
- `last_seen_message_id`
- `last_polled_at`
- `last_webhook_at`
- `last_successful_sync_at`
- `last_error`
- timestamps

### Why

The CRM must know exactly where it last stopped reading Support Board activity for each market.

## 5.4 Add normalized Support Board messages

### New table

`support_board_messages`

### Fields

- `platform_id`
- `sb_user_id`
- `sb_conversation_id`
- `sb_message_id`
- `client_id` nullable
- `lead_id` nullable
- `message_direction`
- `message_body`
- `message_preview`
- `sent_at`
- `payload`
- `is_known_client`
- `is_unassigned`
- `assigned_to` nullable
- `notified_at` nullable
- `acknowledged_at` nullable
- timestamps

### Constraints

- unique index on `platform_id + sb_message_id`

### Why

This table gives the CRM its own durable message queue for:

- Slack notifications
- dashboard triage
- dedupe
- auditability

## 5.5 Add notification event and delivery tracking

### New tables

- `notification_events`
- `notification_deliveries`

### Minimum fields for `notification_events`

- `event_key`
- `event_type`
- `platform_id`
- `entity_type`
- `entity_id`
- `severity`
- `payload`
- `occurred_at`

### Minimum fields for `notification_deliveries`

- `notification_event_id`
- `destination_type`
- `destination_key`
- `status`
- `provider_message_id`
- `response_payload`
- `attempted_at`
- `delivered_at`
- `failed_at`

---

## 6) Services to implement

## 6.1 Support Board ingest layer

### New services

- `SupportBoardIngestService`
- `SupportBoardMessageResolver`
- `SupportBoardMessageNormalizer`

### Responsibility

- poll Support Board for new conversations/messages
- normalize replies into CRM-side records
- resolve known client vs chat that needs matching
- update ingest cursors safely

### Data resolution rules

Resolution order:

1. `sb_user_id` exact CRM match
2. phone match
3. email match
4. no match -> queue as `Needs Matching`

### Important filtering rule

Only customer-originated messages should create notification events. Agent-originated Support Board messages should not trigger triage alerts.

## 6.2 Notification event layer

### New services

- `NotificationEventService`
- `NotificationRoutingService`
- `NotificationDeliveryService`

### Responsibility

- create canonical internal events
- decide where alerts go
- enqueue delivery jobs
- track results

### First event types to support

- `payment.confirmed`
- `payment.failed`
- `payment.needs_review`
- `chat.user_replied`
- `chat.user_replied.unassigned`

## 6.3 Slack delivery layer

### New services

- `SlackNotificationService`
- `SlackMessageFormatter`

### Responsibility

- post to a market channel
- mention an assigned agent if `slack_member_id` is known
- DM an assigned agent for urgent items
- store response metadata for troubleshooting

### Phase 1 implementation recommendation

Use a Slack app plus Web API, not only incoming webhooks.

Why:

- you need channel posting
- you need person mentions
- you likely want DMs for assigned owners

Incoming webhooks alone are too limited for the full routing behavior.

---

## 7) Jobs and scheduling

## 7.1 New jobs

- `PollSupportBoardMessagesJob`
- `ProcessSupportBoardMessageJob`
- `DispatchNotificationEventJob`
- `SendSlackNotificationJob`

## 7.2 Scheduling

### New schedule target

Run Support Board polling every **30 to 60 seconds** for configured markets during business hours.

### Why

The existing Support Board sync in [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php#L64) runs every 15 minutes, which is too slow for operational notification use.

### Operational recommendation

- use queue workers, not sync queue
- stagger platform polling to avoid spikes
- log platform-specific ingest errors without stopping other markets

## 7.3 Reliability rules

- idempotent processing by `sb_message_id`
- never notify twice for the same customer message
- update cursor only after successful persistence
- retry Slack delivery separately from message ingest
- preserve failed deliveries for admin review

## 7.4 Production runtime and dependencies

### Required dependencies

- Slack workspace
- Slack app credentials
- Support Board API credentials per configured market
- queue worker infrastructure
- scheduler/cron
- database migrations for new tables
- application logging and alerting

### Recommended production runtime

- `QUEUE_CONNECTION=database` or `redis`
- at least one queue worker dedicated to notification jobs
- scheduler running reliably every minute
- per-platform polling staggered to avoid spikes
- structured logs for ingest and Slack delivery

### Operational best practice

Separate these concerns:

- Support Board ingest
- notification event creation
- Slack delivery
- dashboard reads

This prevents one slow dependency from blocking the entire notification flow.

## 7.5 Error handling and edge cases

### Support Board unavailable

If Support Board API fails:

- do not lose the last cursor
- log the platform-specific failure
- retry on the next schedule
- surface the failure in admin diagnostics

### Slack unavailable or rate limited

If Slack delivery fails:

- keep the normalized CRM message record
- keep the notification event
- retry delivery independently
- mark the delivery as failed if retries exhaust

### Unknown customer resolution

If a Support Board user cannot be matched to a CRM client:

- classify as `Needs Matching`
- preserve market context
- do not discard the message

### Owner without Slack mapping

If `assigned_to` exists but `slack_member_id` is missing:

- still post to the market channel
- show owner as assigned in the dashboard
- log a non-fatal configuration warning

### Market mismatch risk

If a client record is linked to a platform that conflicts with new Support Board hints:

- keep the CRM market authoritative
- do not auto-move the client across markets
- surface the mismatch for review

### Duplicate or replayed messages

If Support Board returns the same message more than once:

- rely on the unique message constraint
- do not create a second Slack alert

### Large backlog after downtime

If the poller is down and returns many messages on recovery:

- backfill in batches
- keep newest-first dashboard ordering
- avoid flooding Slack with historical low-value alerts
- optionally alert only messages inside the active notification window

## 7.6 Empty state and fallback behavior

The system must always degrade clearly, never silently.

### No Slack configured for market

- keep message records in CRM
- show triage items in dashboard
- surface a configuration warning to admins

### No Support Board configured for market

- hide triage rows for that market
- show an admin-only diagnostics note in settings

### No assigned owner

- route to market channel only
- mark row as `Unassigned`
- allow assignment from the widget flow

## 7.7 Database risks and mitigations

### Risk: table growth

`support_board_messages` and `notification_deliveries` can grow quickly.

Mitigations:

- index by `platform_id`, `sent_at`, `acknowledged_at`
- archive or prune old delivery logs
- retain only the normalized fields needed for fast triage

### Risk: duplicate inserts under concurrency

Mitigations:

- unique index on `platform_id + sb_message_id`
- idempotent service logic
- queue job retry safety

### Risk: cursor corruption

Mitigations:

- update cursor only after successful write
- keep last successful sync metadata
- maintain admin-visible error state

### Risk: oversized payload storage

Mitigations:

- store compact preview fields for UI
- keep raw payload as JSON only where needed
- avoid loading full payloads in dashboard queries

---

## 8) API implementation

## 8.1 Settings endpoints

### New endpoints

- `GET /api/crm/settings/integrations/slack`
- `PATCH /api/crm/settings/integrations/slack`
- `POST /api/crm/settings/integrations/slack/test`

### Purpose

- configure Slack app/bot credentials
- configure market-to-channel mappings
- send a test message to a market channel

## 8.2 User settings enhancement

### Extend existing role/user settings

Update the user settings payload to include:

- `slack_member_id`

This fits naturally beside the existing `sb_agent_id` flow in [SettingsController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/SettingsController.php#L2153).

## 8.3 Dashboard payload

### Option A

Add message triage to the existing dashboard response:

- `message_triage.client_replies`
- `message_triage.needs_matching`
- `message_triage.summary`
- `message_triage.meta`

### Option B

Create a dedicated endpoint:

- `GET /api/crm/dashboard/message-triage`

### Recommendation

Use **Option B** if the payload grows quickly or refresh cadence differs from the rest of the dashboard.

Use **Option A** only if you want the simplest first integration.

### Suggested item shape

- `platform_id`
- `platform_name`
- `client_id`
- `lead_id`
- `sb_user_id`
- `sb_conversation_id`
- `sb_message_id`
- `name`
- `assigned_to`
- `assigned_agent_name`
- `assigned_agent_slack_member_id`
- `preview`
- `last_message_at`
- `minutes_waiting`
- `is_known_client`
- `is_unassigned`
- `priority`

## 8.4 Triage actions

### Client Replies tab actions

- `Open client chat`
- `Mark acknowledged`

### Needs Matching tab actions

- `Review`
- `Match to client`
- `Create lead`
- `Assign owner`

---

## 9) Dashboard widget implementation

## 9.1 Widget concept

### Name

`Message Triage`

### Placement

Place the widget **below the KPI strip and below the primary action header**, not at the very top of the dashboard.

### Reason

It is important, but it should not push the high-level summary out of view. The dashboard should still open with:

- high-level sales state first
- triage queue second
- lower-priority informational widgets after that

### Notification center companion

Also add a **notification icon** in the top-right dashboard action area.

Recommended behavior:

- show unread count badge
- click to open the panel
- support hover preview on desktop as an enhancement only
- never rely on hover as the only interaction

The icon is for quick awareness. The widget is for structured work.

Recommended panel groups:

- `New client replies`
- `Needs matching`
- `Payment alerts`

Recommended panel behavior:

- show the five most recent unresolved items first
- let users click through directly to the CRM destination
- include a muted footer link to `View all activity`

## 9.2 Tab design

### Tab 1

`Client Replies`

Muted subtext:

- `Known CRM clients with new incoming messages`

Show only:

- customer-originated messages
- linked to an existing CRM client
- within the active dashboard market filter
- not yet acknowledged or resolved

### Tab 2

`Needs Matching`

Muted subtext:

- `Incoming market chats not yet linked to a CRM client`

Show only:

- customer-originated messages
- not linked to a CRM client
- from the active dashboard market
- not yet acknowledged or converted

## 9.3 Row design

Each row should show:

- customer/conversation name
- market badge
- assigned agent or `Unassigned`
- reply preview
- time waiting
- urgency badge
- compact action area

### CTA placement

Do not use large row-level buttons inside the widget.

Recommended pattern:

- make the row itself clickable for the main destination
- place one small inline primary text action on the right
- keep secondary actions inside a compact overflow menu

Examples:

- known client row primary action: `Open chat`
- unknown chat row primary action: `Review`
- overflow actions: `Match`, `Create lead`, `Assign owner`, `Acknowledge`

## 9.4 UX rules

- use tab counts
- keep rows compact
- support keyboard navigation
- preserve visible focus states
- never merge known and unknown items into one list
- use strong market signifiers when dashboard is cross-market

### World-class dashboard UX standard

The triage surfaces should feel fast, data-dense, and calm rather than loud.

That means:

- one row should equal one clear decision
- market badge, wait time, and ownership should be scannable in under a second
- semantic color should be used sparingly for urgency, not as decoration
- rows should use subtle hover highlight and clear focus rings
- important states should never be hidden behind hover only
- primary actions should stay lightweight text actions, not large block buttons
- loading, empty, stale, and error states should preserve layout to avoid visual jumping

### Microcopy standard

Use operational language, not marketing language.

Good patterns:

- `Client Replies`
- `Needs Matching`
- `Open chat`
- `Review`
- `Unassigned`
- `Last synced 2 minutes ago`

Avoid:

- vague labels like `Inbox`, `Messages`, or `New Contacts`
- oversized CTAs that compete with list scanning
- decorative copy that slows decision making

### Period filtering

The widget should include a compact period control.

Recommended options:

- `Latest` default
- `1h`
- `24h`
- `7d`
- `Custom` later if needed

`Latest` should mean:

- newest unresolved items first
- optimized for live triage
- not a historical reporting mode

On desktop, use compact filter tabs or pills. On smaller screens, collapse this to a dropdown.

Filtering rules:

- keep the selected period when users switch between tabs
- keep the selected market filter in sync with the rest of the dashboard
- reset to `Latest` only on a fresh dashboard visit, not on every refresh

### Empty state handling

#### Client Replies empty state

Title:

- `No new client replies`

Muted subtext:

- `Known CRM clients who reply will appear here.`

#### Needs Matching empty state

Title:

- `No chats waiting for matching`

Muted subtext:

- `Unlinked chats from this market will appear here for review.`

#### No market configured

Title:

- `Choose a market to review chat activity`

Muted subtext:

- `Filter the dashboard by market to load the latest replies and chats waiting for matching.`

### Error state handling

If Support Board ingest is delayed or unavailable:

- keep the widget shell visible
- show a soft warning banner
- preserve the last successful refresh time
- provide a small retry action

Example copy:

- `Chat activity is temporarily delayed`
- `Last successful refresh 2 minutes ago`

### Loading and stale-state handling

When the widget is loading:

- use compact skeleton rows, not a blank card
- keep tab labels and counts visible where possible
- avoid layout shift between loading and loaded states

When data is stale but still usable:

- keep last known rows visible
- mark the surface as `Delayed`
- show the last successful sync time in muted text

## 9.5 Widget configuration

Extend the dashboard widget config in [useDashboardWidgets.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useDashboardWidgets.js#L5) with:

- `message_triage: true`

Add corresponding label metadata:

- name: `Message Triage`
- description: `Client replies and chats waiting for matching`

---

## 10) Slack setup for the pilot

## 10.1 Workspace setup

- one Slack workspace
- invite the 9 sales team members
- create only pilot market channels first

Suggested pilot channels:

- `#market-ke-sales`
- `#market-ng-sales`
- `#ops-payments-global`
- `#support-unassigned`

## 10.2 Slack app setup

Create one CRM Slack app with the permissions needed for:

- posting messages to channels
- reading minimal channel metadata if needed
- sending DMs

Do not add unnecessary apps during the pilot.

## 10.3 Pilot mapping

During pilot, store:

- market -> Slack channel
- user -> `slack_member_id`

Because Slack Free does not support user groups, use:

- direct user mention in channel
- optional DM for urgent assigned items

---

## 11) Notification rules for phase 1

## 11.1 Payments

### `payment.confirmed`

- destination: market channel
- if assigned owner exists: mention owner
- if high value: also post to `ops-payments-global`

### `payment.failed`

- destination: market channel
- if assigned owner exists: mention owner
- if repeated failure or waiting too long: escalate

### `payment.needs_review`

- destination: market channel
- if no owner: also post to `support-unassigned` or operations channel

## 11.2 Chat replies

### `chat.user_replied`

- destination: market channel
- if assigned owner exists: mention owner
- add dashboard row in `Client Replies`

### `chat.user_replied.unassigned`

- destination: market channel
- no owner mention
- dashboard row goes to `Client Replies` if linked client exists but unassigned, or `Needs Matching` if unknown

### `chat.sla_breach`

- destination: market channel
- if assigned owner exists: mention owner again
- add escalation if waiting threshold exceeded

## 11.3 Operational safeguards and best practices

- use feature flags so notification center, widget, and Slack delivery can be enabled market by market
- use idempotency keys for both ingest persistence and delivery attempts
- add queue health monitoring for lag, retry volume, and dead-letter growth
- log structured context for every failure: market, message id, event type, destination
- add admin diagnostics for Slack mapping gaps, Support Board auth failures, and stale cursors
- introduce alert throttling so backlog recovery does not spam channels
- keep Slack as the interrupt layer and CRM as the decision layer

---

## 12) Implementation phases

## Phase 1: Configuration foundation

### Deliverables

- Slack integration settings
- Slack market mappings
- user `slack_member_id`

### Files likely involved

- [SettingsController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/SettingsController.php)
- [routes/api.php](/Users/ian/Projects/exotic-crm/routes/api.php)
- [Settings.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Settings.jsx)

## Phase 2: Support Board ingest

### Deliverables

- ingest state table
- normalized message table
- fast poller job
- client/lead resolution logic

### Files likely involved

- [SupportBoardService.php](/Users/ian/Projects/exotic-crm/app/Services/SupportBoardService.php)
- [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php)

## Phase 3: Notification engine

### Deliverables

- notification event table
- delivery tracking
- Slack delivery service
- event routing by market and owner

### Files likely involved

- new services under `app/Services`
- new jobs under `app/Jobs`

## Phase 4: Dashboard widget

### Deliverables

- backend triage payload
- message triage widget
- two-tab UX
- row-level actions

### Files likely involved

- [DashboardController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/DashboardController.php)
- [Dashboard.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Dashboard.jsx)
- [useDashboardWidgets.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useDashboardWidgets.js)

## Phase 5: Pilot rollout

### Deliverables

- Kenya and one or two additional markets live
- 9 sales users invited
- Slack alerts validated
- dashboard triage used daily

---

## 13) Acceptance criteria

## 13.1 Support chat

- A new customer reply in Support Board appears in CRM triage within the target polling window.
- A known CRM client reply appears only in `Client Replies`.
- An unknown conversation appears only in `Needs Matching`.
- Kenya messages do not appear in Nigeria when dashboard is Kenya-filtered.
- Loading, empty, stale, and delayed states are understandable without training.

## 13.2 Slack

- Payment confirmed for Kenya posts to Kenya channel.
- Customer reply for Kenya posts to Kenya channel.
- If the client is assigned, the assigned agent is mentioned in the Kenya channel.
- No duplicate Slack messages are sent for the same Support Board message.

## 13.3 Dashboard UX

- Widget loads fast enough for daily operational use.
- Tab counts are accurate.
- Agents can open the correct CRM screen from each row.
- Market-aware filtering is preserved across refreshes.

---

## 14) Pilot test plan

## Test A: Client reply

1. Link a Support Board user to a Kenya CRM client.
2. Assign the client to Agent A.
3. Send a new customer reply in Support Board.
4. Confirm:
   - Kenya Slack channel receives alert
   - Agent A is mentioned
   - dashboard row appears in `Client Replies`

## Test B: New contact

1. Create a new Support Board conversation on the Kenya market integration with no CRM match.
2. Confirm:
   - Kenya Slack channel receives alert
   - no assigned-agent mention is sent
   - dashboard row appears in `Needs Matching`

## Test C: Payment confirmed

1. Complete a verified Kenya payment.
2. Confirm:
   - CRM emits one event
   - Kenya Slack channel gets one alert
   - no duplicate alerts occur

## Test D: Cursor resilience

1. Stop the poller briefly.
2. Send multiple customer replies.
3. Restart the poller.
4. Confirm:
   - messages are backfilled
   - no duplicates are emitted

---

## 15) Free-plan pilot constraints

For the pilot, Slack Free is acceptable.

Pilot-safe assumptions:

- 9 sales users is fine
- one CRM Slack app is fine
- a few market channels are fine

Phase 1 limitation handling:

- no market user groups yet
- use direct user mentions instead
- use DMs for urgent owner-specific alerts

Production upgrade trigger:

- once pilot routing and team behavior are validated, upgrade to Slack Pro before relying on market-wide group mentions and longer-term operational history

---

## 16) Recommended immediate build order

If engineering starts now, the order should be:

1. add `slack_member_id` to users
2. add Slack market settings
3. build Slack test-send from settings
4. build Support Board ingest state + message table
5. build poller job
6. build message-to-client resolution
7. emit `chat.user_replied` and `chat.user_replied.unassigned`
8. build Slack delivery service
9. build dashboard triage payload
10. build dashboard widget
11. run pilot in Kenya first
12. expand to more markets

---

## 17) Final recommendation

Build this as a **CRM-owned operational inbox plus Slack alerting layer**, not as a Slack-only workflow.

That gives Exotic:

- fast awareness
- market-aware routing
- owner-aware alerting
- cleaner dashboard triage
- less noise
- a scalable model for 52 markets
