# Exotic CRM Notification Strategy

**Date:** 2026-03-20  
**Scope:** Research and recommendation only. No runtime changes are included in this document.  
**Primary goal:** Make sure sales and support teams are reliably informed when a customer needs attention, especially for payments and chat replies.

---

## 1) Executive recommendation

Exotic should use **Slack as the primary staff notification layer** for the CRM and Support Board. **Discord is deferred for now and should not be included in the first implementation.**

That recommendation is driven by four things:

1. **Support Board already has an official Slack app/integration**, which gives you a faster path to real chat notifications.
2. **Slack fits business operations better** for routing, mentions, private escalation, auditability, and admin controls.
3. Exotic operates across **52 African markets**, so you need structured routing by market, role, and urgency. Slack is a stronger fit for that than Discord.
4. The CRM already has enough foundations to build a **real notification engine**, but it currently lacks a general internal event-to-notification pipeline.

This keeps the first rollout simpler:

- one collaboration surface
- one notification adapter family
- one admin model
- one team behavior to train

---

## 2) What the CRM already has

### 2.1 Market-aware routing foundations already exist

The CRM already stores per-market operational metadata on `Platform`, including:

- `country`
- `timezone`
- `currency_code`
- `phone_prefix`
- `support_chat_url`
- `support_board_api_url`
- `support_board_token`
- `support_board_sender_id`

Relevant code:

- `app/Models/Platform.php`
- `app/Services/MarketAuthorizationService.php`

This is a major advantage. A notification engine can route by `platform_id` instead of trying to infer market ownership later.

### 2.2 There is no general notification engine yet

There is an existing `NotificationService`, but it is **SMS-only** and stores only SMS provider runtime config in `integration_settings`.

Relevant code:

- `app/Services/NotificationService.php`
- `docs/sprint-5-issue-verification-implementation-plan.md`

That means the CRM currently has **delivery plumbing for SMS**, but not a general event engine for:

- staff alerts
- in-app notifications
- Slack delivery
- escalation rules
- acknowledgement state

### 2.3 Payments already produce trustworthy business events

The CRM already receives and verifies payment callbacks and webhooks for Paystack, Pesapal, and M-Pesa/KopoKopo. Those verified payment flows are the right place to trigger staff notifications.

Relevant code:

- `app/Services/BillingGatewayService.php`
- `routes/api.php`

This matters because staff should not be notified from unverified raw provider noise. The authoritative events should be emitted **after verification** and **after payment state is committed**.

### 2.4 Support Board integration exists, but staff awareness is still weak

The CRM already integrates with Support Board for:

- matching CRM clients to Support Board users
- fetching conversations
- fetching new conversations
- loading full threads
- replying from the CRM

Relevant code:

- `app/Services/SupportBoardService.php`
- `app/Http/Controllers/CRM/SupportBoardController.php`
- `app/Console/Kernel.php`

However, the current pattern is mostly **sync and pull**, not a true alerting engine. That is why agents can miss customer replies when they are not actively inside the CRM.

### 2.5 There are reusable implementation patterns already in the repo

The CRM already contains patterns that should be reused instead of inventing something entirely new:

- `TimelineEvent` as an event history primitive
- `AuditService` and webhook incident logging for operational visibility
- `PushProviderService` as a provider adapter pattern with config + fallback concepts
- Laravel queue/scheduler infrastructure already in active use

Relevant code:

- `app/Models/TimelineEvent.php`
- `app/Services/PushNotification/PushProviderService.php`
- `app/Console/Kernel.php`

---

## 3) Vendor research summary

## 3.1 Support Board

### What is directly relevant

Support Board documents:

- an official **Slack app**
- **department linking** for Slack routing
- Slack messages for new conversations
- configurable user fields in Slack notifications
- **webhooks** under `Settings > Miscellaneous > Webhooks`

This is important because it means you have **two viable paths**:

1. a fast native Support Board -> Slack setup
2. a more controlled Support Board -> CRM event pipeline -> Slack setup

### Practical implication for Exotic

For chat notifications, Slack can be enabled quickly through Support Board while the CRM notification engine is being built. Discord does not appear in the Support Board docs as a native app in the same way.

Official docs used:

- https://board.support/docs/

---

## 3.2 Slack

### Pros

- Incoming webhooks are simple to set up and can post structured JSON payloads.
- `chat.postMessage` supports richer behavior than incoming webhooks, including threads and direct/private routing.
- Slack supports mentions, user groups, and controlled audience notification.
- Slack has stronger business admin and governance features, including data residency options on higher plans.
- Slack is a better fit for structured operational channels such as finance, market ops, customer success, and escalations.

### Drawbacks

- Incoming webhooks are **channel-bound** and cannot delete posted messages.
- Incoming webhooks also cannot override the default channel, username, or icon at send time.
- If you want flexible routing, DMs, private alerts, or threaded workflows, you should move past bare incoming webhooks to a proper Slack app using Web API methods.
- Slack can become expensive at larger team sizes, especially if you need stronger governance features.

### What matters most for Exotic

Slack is the stronger choice if you want:

- one channel per market
- one escalation group per role or region
- mentions for finance leads, sales leads, or support leads
- threaded operational handling per incident
- mobile notifications that are tied to structured workspaces rather than community-style servers

Official docs used:

- https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/
- https://docs.slack.dev/reference/methods/chat.postMessage/
- https://slack.com/help/articles/205240127-Use-mentions-in-Slack
- https://slack.com/help/articles/212906697-Create-a-user-group
- https://slack.com/help/articles/360035633934-Data-residency-for-Slack

---

## 3.3 Discord

### Pros

- Discord webhooks are easy to use and support embeds, files, threads, and mention controls.
- Discord can work as a low-cost broadcast channel for operational alerts.
- Discord even supports a **Slack-compatible webhook execution mode**, which can simplify some dual-posting strategies.

### Drawbacks

- Discord is weaker than Slack as a primary business operations workspace.
- Notification behavior depends heavily on each user’s server and channel settings, including suppression of role mentions.
- Discord is better at community/server communication than admin-heavy business workflow routing.
- Support Board does not present Discord as a native app in the same way it does Slack.
- Discord has less obvious enterprise-fit alignment for multi-market commercial operations than Slack.

### What matters most for Exotic

Discord can work for:

- mirrored operational alerts
- lower-priority broadcast updates
- team-specific rooms

It is a weaker fit for:

- structured market ownership
- role-based escalation
- business admin controls
- standardized handling across 52 markets

Official docs used:

- https://docs.discord.com/developers/resources/webhook
- https://docs.discord.com/developers/reference
- https://support.discord.com/hc/en-us/articles/215253258-Notifications-Settings-101

---

## 4) Slack vs Discord for Exotic

| Topic | Slack | Discord | Best fit for Exotic |
|---|---|---|---|
| Native Support Board path | Yes | Not found in Support Board docs | Slack |
| Business operations fit | Strong | Moderate | Slack |
| Market-by-market routing | Strong | Possible but less natural | Slack |
| Role-based mentions | Strong via user groups and mentions | Possible via roles, but more server-setting dependent | Slack |
| Simple webhook setup | Good | Good | Tie |
| Rich message formatting | Strong | Strong | Tie |
| Threaded operational handling | Strong | Good | Slack |
| Governance/admin posture | Stronger | Weaker | Slack |
| Cost flexibility | Usually higher | Usually lower | Discord |
| Best use at Exotic | Primary alerting workspace | Optional secondary mirror | Slack primary, Discord secondary |

---

## 5) Recommended operating model

## 5.1 Use Slack as the primary notification destination

Recommended destination structure:

- `#ops-payments-global`
- `#ops-support-global`
- `#market-ke-sales`
- `#market-ng-sales`
- `#market-za-sales`
- `#market-ug-sales`
- `#finance-escalations`
- `#support-unassigned`

Recommended mention structure:

- `@market-ke-leads`
- `@market-ng-leads`
- `@finance-ops`
- `@support-duty`
- `@regional-west-africa`
- `@regional-east-africa`

### Why this model works for 52 markets

It separates:

- **global events** from **market-owned events**
- **awareness alerts** from **action-required alerts**
- **sales ownership** from **finance ownership**

That is critical in a multi-market business, otherwise every event becomes everyone’s problem and the system becomes noise.

### Important clarification: channel membership is not enough on its own

Agents should absolutely join the Slack channels for the markets they own, but Exotic should **not** rely on channel membership alone for urgent alerts.

Why:

- Slack users can mute channels
- Slack users can set channel notifications to mentions only
- Slack users can pause notifications entirely

Recommended policy:

- market channels provide the shared operational room
- market user groups provide the reliable escalation target
- assigned-agent DMs handle personally owned work

In practice:

- low-priority events -> post to market channel only
- urgent market events -> post to market channel + mention market user group
- personally assigned events -> post to market channel + DM assigned owner

## 5.2 Discord status

Discord is **deprecated for now in this plan**.

That means:

- no Discord webhook adapter in phase 1
- no Discord routing rules in the first CRM notification engine version
- no Discord operational dependency for payments or chat replies

If Exotic revisits Discord later, it can be added as a secondary channel after Slack is stable.

---

## 5.3 CRM should own multi-market staffing, not Support Board

Support Board can remain the chat system, but the **CRM should be the source of truth for market ownership and routing**.

That is the correct model for Exotic because:

- the CRM already supports multiple assigned markets per user
- Support Board agent/admin assignment is too rigid for your operating model
- Slack routing is easier to drive from CRM market ownership than from Support Board department setup

### Practical decision

- **CRM owns:** who covers which markets, who gets notified, escalation rules, role-based routing
- **Support Board owns:** customer conversation storage and message transport
- **Slack owns:** operational awareness and action notifications

### What this means operationally

- An agent can belong to multiple CRM markets even if Support Board cannot represent that neatly.
- Support Board admins or shared sender identities can still be used when replying, while CRM determines which market team should be alerted.
- Notifications for customer replies should be routed from CRM market ownership, not limited by Support Board’s agent-department model.

### Current codebase reality

The CRM already supports many-market assignment through user market scope and `assigned_market_ids`.

Relevant code:

- `app/Models/User.php`
- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Services/MarketAuthorizationService.php`

However, the CRM currently stores only one `sb_agent_id` per user, which means a single CRM user still maps to a single Support Board identity today.

Relevant code:

- `app/Models/User.php`
- `app/Http/Controllers/CRM/SupportBoardController.php`

That is acceptable for notification routing, but it is an important limitation if Exotic later wants one human agent to reply using different Support Board identities across multiple markets.

### Recommended planning note

Add this as an explicit decision:

- **The CRM is the authoritative owner of multi-market staff assignment and notification routing. Support Board is not.**

And add this as an implementation follow-up:

- **Slack notifications must support channel posts, market-group mentions, and assigned-agent DMs.**

---

## 5.4 How the Slack integration should work with the CRM

### Source systems

- **Payments:** the CRM itself is the authoritative event source after webhook verification
- **Support chat:** Support Board is the message source, but CRM should own routing and staff assignment

### Recommended flow for payments

1. A payment webhook or callback reaches the CRM.
2. The CRM verifies the payload and updates the payment record.
3. The CRM emits a normalized internal event like `payment.confirmed`.
4. The notification router reads the payment’s `platform_id`.
5. The router finds the matching Slack configuration for that market.
6. A queued job posts the alert to the market Slack channel.
7. If the event is urgent, the same job also:
   - mentions the market group on paid Slack plans, or
   - DMs the assigned owner directly

### Recommended flow for chat replies

1. A customer replies in Support Board.
2. The CRM ingests that reply through a **Support Board ingest layer**.
3. The ingest layer resolves the Support Board user to a CRM client using `sb_user_id`, with phone/email resolution as fallback.
4. The CRM reads the client’s `platform_id` to determine the market.
5. The CRM checks client ownership using `assigned_to` and user market scope using `assigned_market_ids`.
6. The router sends:
   - a channel alert to the market Slack channel
   - a direct mention or DM to the assigned agent if one exists
   - an escalation alert if the conversation is unassigned or overdue

### How the CRM stays up to date on Support Board replies

The CRM should not depend on an agent being inside Support Board or the CRM UI. It should maintain its own ingest loop.

Important note: the existing Support Board sync already present in the CRM is useful for account linking and backfill, but it is too slow for near-real-time notifications. Notification ingest needs its own faster worker.

Recommended model:

1. **Primary path:** use Support Board webhooks if available in your cloud setup.
2. **Safety net:** run a short-interval CRM poller per configured market.
3. **Reconciliation path:** periodically backfill anything missed using message/conversation cursors.

Support Board’s official Web API already supports:

- `get-new-conversations`
- `get-new-messages`
- `get-last-message`

That means the CRM can reliably ask: “What changed since the last message or conversation ID I processed for Kenya?”

### Recommended ingest pattern

- keep one ingest cursor per market/platform
- poll every 30 to 60 seconds during business hours
- use `get-new-conversations` for broad change detection
- use `get-new-messages` when a conversation needs message-level backfill
- store every processed Support Board message with an idempotency key

### Example

If user X replies in Support Board and user X is linked to a Kenya client:

1. Kenya Support Board integration is polled using the Kenya platform token.
2. Support Board returns a conversation whose `last_message_id` is newer than the Kenya cursor.
3. The CRM resolves that Support Board user to the Kenya client.
4. The client record gives the CRM:
   - `platform_id = Kenya`
   - `assigned_to = Agent A` if present
5. The CRM creates one normalized internal event such as `chat.user_replied`.
6. The CRM posts the alert to the Kenya Slack channel.
7. If `assigned_to` exists and that user has a mapped Slack member ID, the CRM mentions that agent in the Kenya channel and can also DM them.

### Why this is safe

- webhooks give speed
- polling gives resilience
- stored cursors prevent gaps
- idempotency prevents duplicate Slack alerts
- market routing stays inside the CRM

### Suggested Slack configuration per market

Each market should eventually store:

- `slack_channel_id`
- `slack_channel_name`
- optional `slack_user_group_handle`
- optional `slack_escalation_channel_id`
- optional `slack_enabled` flag

Those settings can live in `integration_settings` or a dedicated notification configuration table.

Each CRM user who should receive direct mentions should also eventually store:

- `slack_member_id`

That allows the CRM to mention a specific assigned agent in Slack even on the Free plan.

### Why this is a good fit for this CRM

The CRM already knows:

- which market a payment belongs to
- which markets a user owns
- which Support Board platform configuration belongs to that market

So the CRM is already the best place to decide:

- who should be notified
- where the alert should go
- whether to post only, mention, or DM

It is also the best place to decide whether a Support Board reply belongs to:

- Kenya
- the Kenya channel
- and a specific Kenya-assigned sales agent

## 5.5 Will the Slack free plan work?

### Short answer

**It can work for a pilot, but it is not the right long-term plan for Exotic’s target operating model.**

### What the free plan can support

The free plan is good enough if you want a simple first phase with:

- one workspace
- one channel per market
- basic app-based notifications from the CRM
- basic mobile and desktop notifications for members

Slack’s current pricing and help pages indicate the free tier includes:

- 90 days of message history
- up to 10 app installations
- channel-specific notification controls

### Why the free plan is weak for your full use case

Slack’s help center says that on the free plan:

- user groups are removed and cannot be created
- message and file history is limited to 90 days

That matters because the recommended Exotic model depends on:

- market-level group mentions like `@market-ke-sales`
- operational history that managers can review
- consistent admin control over who gets alerted

### Practical recommendation

- **Pilot / proof of concept:** free plan is acceptable
- **Production for Exotic:** move to at least **Pro**

### Why Pro is the better fit

On paid plans, Slack supports:

- user groups
- stronger team coordination features
- better continuity for operational review

For Exotic specifically, the biggest unlock is **user groups**, because they let the CRM mention all owners of a market without hardcoding every person into each alert.

---

## 5.6 Dashboard message triage widget

Slack should be the interrupt layer, but the CRM dashboard should become the **triage layer**.

That means Exotic should add a dedicated dashboard widget for new chat activity so sales can distinguish:

- real customer work already tied to CRM records
- new chat noise or unknown chat leads from the selected market

### Recommended widget goal

Give the sales team one place on the dashboard to answer:

- which known CRM clients just replied?
- which new conversations from this market are not yet in CRM?
- who owns each item?
- what needs action now versus later?

### Recommended widget name

- `Message Triage`

### Recommended layout

Use a **tabbed card** with strong market awareness and compact action-focused rows.

#### Tab 1

- `Known Replies`
- shows only new messages from users already confirmed in the CRM

#### Tab 2

- `New Market Chats`
- shows new conversations for the selected market that are not yet linked to a CRM client

### Why this split is valuable

It separates:

- high-confidence customer work
- unlinked chat traffic
- real follow-up opportunities
- low-signal noise

This is especially important in a multi-market business, because agents should not scan one mixed list to figure out whether something belongs to an existing Kenya client or an unlinked Kenyan conversation.

### Market-aware behavior

The widget should always respect the current dashboard market filter.

If the dashboard is filtered to Kenya:

- Tab 1 shows only Kenya clients with new replies
- Tab 2 shows only Kenya conversations not yet linked to CRM clients

If the dashboard is on “All accessible markets”:

- group rows visually by market
- include strong market badges and country flags
- sort by urgency first, then recency

### Recommended row design

Each row should show:

- customer or conversation name
- market badge
- assigned agent or `Unassigned`
- reply preview
- time since latest customer message
- channel/source badge
- one primary CTA

Recommended CTA behavior:

- known CRM client row -> `Open client chat`
- unknown market chat row -> `Review`, `Match`, or `Create lead`

### Recommended urgency styling

- unread under 5 minutes: neutral
- 5 to 30 minutes: amber
- over SLA threshold: rose/red

Use color sparingly and pair it with text labels so the widget stays readable and accessible.

### Recommended backend shape

Add a dedicated dashboard payload for message triage instead of mixing it into generic recent activity.

Suggested response shape:

- `message_triage.known_replies`
- `message_triage.unknown_market_chats`
- `message_triage.summary`

Suggested item fields:

- `platform_id`
- `platform_name`
- `client_id` if known
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

### Recommended data source logic

The widget should read from the CRM’s own Support Board ingest tables, not directly from Slack.

That means:

- Support Board ingest creates normalized CRM-side message records
- the dashboard reads those normalized records
- the widget stays fast, market-aware, and deduplicated

### UX guidance

- keep the card visible above lower-priority widgets
- make the tab labels show counts
- preserve a compact list with easy scanning
- never mix known-client replies and unknown market chats in the same tab
- allow click-through into the relevant CRM workflow immediately

### Fit with the existing codebase

This is a strong fit because the dashboard already has:

- a market filter
- an auto-refresh query
- a widget toggle system

Relevant code:

- `resources/js/pages/Dashboard.jsx`
- `resources/js/hooks/useDashboardWidgets.js`
- `app/Http/Controllers/CRM/DashboardController.php`

### Recommended planning note

Add this as an explicit product decision:

- **The dashboard should include a message triage widget with two tabs: known CRM replies and unlinked market chats.**

And add this as an implementation follow-up:

- **Message triage should be powered by CRM-ingested Support Board message records, not by Slack data.**

---

## 6) Recommended notification architecture for the CRM

## 6.1 Design principle

The CRM should become the **notification orchestrator**, even when some external systems can send alerts directly.

That means:

- Support Board may notify Slack immediately for fast wins
- but the CRM should still own the long-term logic for rules, routing, dedupe, preference management, and auditability

## 6.2 Event model

Introduce canonical internal events such as:

- `payment.detected`
- `payment.confirmed`
- `payment.failed`
- `payment.needs_review`
- `payment.manual_action_required`
- `chat.user_replied`
- `chat.user_replied.unassigned`
- `chat.sla_breach`
- `lead.created.needs_attention`
- `client.reply_after_hours`

### Important distinction

For payments, do **not** notify staff on every raw provider ping. Notify from the CRM only after:

- signature verification
- provider verification where applicable
- database commit of the normalized payment state

For chat, the event should represent:

- a real customer reply
- the owning market
- the current assignment state
- the elapsed time since the last agent response

## 6.3 Routing model

Each notification should be resolved through rules such as:

- event type
- `platform_id`
- severity
- ownership
- assignment state
- business hours / quiet hours by market timezone

Examples:

- `payment.confirmed` for Kenya -> `#market-ke-sales`
- `payment.confirmed` for any market above high-value threshold -> `#ops-payments-global` + `@finance-ops`
- `chat.user_replied.unassigned` -> `#support-unassigned` + `@support-duty`
- `chat.user_replied` for assigned market owner -> DM owner or private channel + market channel

## 6.4 Delivery channels

Recommended channel adapters:

- Slack webhook adapter
- Slack bot/Web API adapter
- database/in-app adapter
- optional SMS adapter for high-severity fallbacks

### Why both Slack webhook and Slack bot

Use the **webhook adapter** for:

- quick setup
- static channel delivery
- low-complexity alerts

Use the **Slack bot/Web API adapter** for:

- dynamic channel routing
- DMs
- threads
- richer acknowledgements
- market lead mentions and escalations

## 6.5 Persistence model

Recommended new tables:

- `support_board_ingest_states`
- `support_board_messages`
- `notification_events`
- `notification_rules`
- `notification_destinations`
- `notification_deliveries`
- `notification_preferences`

Suggested responsibilities:

### `support_board_ingest_states`

Stores the latest processed Support Board cursor per market/platform.

Suggested fields:

- `platform_id`
- `last_seen_conversation_id`
- `last_seen_message_id`
- `last_polled_at`
- `last_webhook_at`
- `last_successful_sync_at`
- `last_error`

### `support_board_messages`

Stores processed Support Board messages or a normalized subset needed for routing and dedupe.

Suggested fields:

- `platform_id`
- `sb_user_id`
- `sb_conversation_id`
- `sb_message_id`
- `client_id`
- `message_direction`
- `message_body`
- `sent_at`
- `payload`
- unique key on `platform_id + sb_message_id`

### `notification_events`

Stores the normalized event emitted by the CRM.

Suggested fields:

- `id`
- `event_key` for idempotency
- `event_type`
- `platform_id`
- `entity_type`
- `entity_id`
- `severity`
- `payload`
- `occurred_at`

### `notification_rules`

Stores routing logic.

Suggested fields:

- `name`
- `is_active`
- `event_types`
- `platform_ids`
- `severity_filter`
- `conditions_json`
- `destinations_json`
- `throttle_seconds`

### `notification_destinations`

Stores configured endpoints and targets.

Suggested destination types:

- `slack_webhook`
- `slack_bot_channel`
- `slack_bot_dm`
- `discord_webhook`
- `database`
- `sms`

### `notification_deliveries`

Stores each attempt and outcome.

Suggested fields:

- `notification_event_id`
- `destination_type`
- `destination_key`
- `status`
- `provider_message_id`
- `response_payload`
- `attempted_at`
- `delivered_at`
- `failed_at`

### `notification_preferences`

Stores human preferences and ownership.

Suggested scope:

- by user
- by role
- by market
- by event type

## 6.6 Queue and reliability model

Best-practice behavior:

- emit events only **after commit**
- queue delivery work
- make jobs idempotent
- dedupe near-duplicate events
- track failed jobs and expose them in ops UI

This fits Laravel’s official guidance on notifications, queued listeners, custom channels, and unique jobs.

Official docs used:

- https://laravel.com/docs/10.x/notifications
- https://laravel.com/docs/10.x/events
- https://laravel.com/docs/10.x/queues

---

## 7) Best practices that fit this CRM

## 7.1 Emit canonical business events, not ad hoc alerts

Do not call Slack directly from random controllers.

Better pattern:

1. payment or chat logic commits state
2. CRM emits a normalized internal event
3. router resolves rules and destinations
4. queued jobs perform delivery
5. delivery results are stored for audit and retry

This gives you:

- consistency
- idempotency
- delivery tracking
- rule configurability
- easier channel expansion later

## 7.2 Keep in-app notifications as a first-class channel

Even if Slack becomes primary, the CRM should also store notifications in-app.

Why:

- some staff will still work inside the CRM
- managers need an audit trail
- unresolved alerts should remain visible even if a chat app notification is missed

## 7.3 Design for noise control from day one

In 52 markets, a naive notification system will fail quickly.

Required controls:

- per-market routing
- per-event throttling
- digest mode for low-severity events
- severity thresholds
- owner-first notification before broad escalation
- business-hours awareness
- duplicate suppression

## 7.4 Make payment notifications financially safe

Recommended rule:

- `payment.made` may be informational
- `payment.confirmed` should be the action-grade success signal

That prevents false confidence from provider pings that later fail verification.

## 7.5 Make chat notifications assignment-aware

Recommended rule:

- if a conversation is assigned, notify the assigned agent first
- if unassigned, notify market duty queue
- if unanswered past SLA, escalate to market lead

This is far better than broadcasting every customer reply to everyone.

---

## 8) Best-fit implementation for Exotic CRM

## 8.1 Phase 1: Fast wins

### Goal

Start notifying teams quickly without waiting for the full engine.

### Recommended actions

1. Configure one Slack channel per pilot market.
2. Add Slack market settings in the CRM.
3. Add CRM-generated Slack alerts for:
   - `payment.confirmed`
   - `payment.failed`
   - `payment.needs_review`
   - `chat.user_replied`
4. Add `slack_member_id` mapping for pilot agents so assigned users can be mentioned individually.
5. Run a short-interval Support Board ingest poller from the CRM for pilot markets.
6. Keep messages short, structured, and mobile-friendly.

### Why this phase

It solves the biggest practical problem first: missed customer replies and missed payment events, while keeping the CRM as the routing brain from the start.

## 8.2 Phase 2: Build the CRM notification engine

### Goal

Move from point integrations to a reusable internal system.

### Recommended implementation shape

- create `notification_events` and `notification_deliveries`
- add channel adapters
- create rule-driven router service
- add UI under Settings for destinations and rules
- store Slack credentials in `integration_settings`

### Recommended internal services

- `NotificationEventService`
- `NotificationRoutingService`
- `NotificationDeliveryService`
- `NotificationPreferenceService`
- `SlackNotificationChannel`

## 8.3 Phase 3: Add operator controls and escalations

### Goal

Make the system flexible enough for 52 markets.

### Recommended features

- quiet hours by market timezone
- escalation chains
- owner availability fallbacks
- digest scheduling
- acknowledgement / resolve state
- in-app notification inbox
- incident dashboards for failed delivery

---

## 9) Suggested message patterns

## 9.1 Payment confirmed

Recommended content:

- event type
- market
- client name or identifier
- amount + currency
- provider
- payment reference
- assigned owner
- deep link to CRM record

Recommended destinations:

- market sales channel
- finance channel only if threshold or review condition is met

## 9.2 Payment needs review

Recommended content:

- market
- amount
- provider
- failure or review reason
- time waiting
- link to payment queue

Recommended destinations:

- market ops channel
- finance escalation if unresolved beyond SLA

## 9.3 Customer replied in chat

Recommended content:

- market
- customer identifier
- assignment state
- preview of latest message
- minutes since last agent response
- deep link to CRM chat tab

Recommended destinations:

- assigned owner first
- market support queue if unassigned
- lead escalation if SLA breached

---

## 10) Specific recommendation for Exotic

If Exotic wants the best balance of speed, control, and scalability, the recommended path is:

1. **Adopt Slack as the primary notification workspace.**
2. **Turn on Support Board -> Slack for immediate chat awareness.**
3. **Build CRM-owned event routing for payments and later for chat.**
4. **Keep Discord out of scope for the first rollout.**
5. **Use the existing `Platform` market model as the routing backbone.**
6. **Add in-app notification persistence so alerts are not lost in chat noise.**

This is the strongest fit for a company operating across 52 African markets where:

- markets have different owners
- timezones differ
- payment providers differ
- urgency differs by event type
- missed customer replies directly affect conversion and retention

---

## 11) Implementation fit with the current codebase

These files are the best anchors for the eventual implementation:

- `app/Models/Platform.php`
- `app/Services/MarketAuthorizationService.php`
- `app/Services/NotificationService.php`
- `app/Services/SupportBoardService.php`
- `app/Services/BillingGatewayService.php`
- `app/Services/PushNotification/PushProviderService.php`
- `app/Console/Kernel.php`
- `routes/api.php`

### Practical reading of the current state

- Market metadata is ready.
- Queue infrastructure is ready.
- Payment verification paths are ready.
- Support Board integration is ready.
- Provider-adapter pattern already exists.
- The missing piece is the **notification domain model** and **staff delivery channels**.

---

## 12) Final answer in one sentence

**Use Slack as Exotic’s primary staff notification layer, keep Discord out of the first rollout, enable Support Board’s native Slack integration for immediate chat awareness, and build a CRM-owned queued notification engine that routes verified payment and chat events by market, role, severity, and timezone.**

---

## Sources

- https://board.support/docs/
- https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/
- https://docs.slack.dev/reference/methods/chat.postMessage/
- https://slack.com/help/articles/205240127-Use-mentions-in-Slack
- https://slack.com/help/articles/212906697-Create-a-user-group
- https://slack.com/help/articles/360035633934-Data-residency-for-Slack
- https://docs.discord.com/developers/resources/webhook
- https://docs.discord.com/developers/reference
- https://support.discord.com/hc/en-us/articles/215253258-Notifications-Settings-101
- https://laravel.com/docs/10.x/notifications
- https://laravel.com/docs/10.x/events
- https://laravel.com/docs/10.x/queues
