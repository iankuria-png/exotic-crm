# Internal Push Notifications Research

**Date:** 2026-04-12  
**Scope:** Research and recommendation for internal CRM push notifications for Exotic sales and support teams  
**Goal:** Add internal CRM notifications that can appear in-app immediately, support a notification bell / center, and optionally expand to browser push later

---

## 1) Executive recommendation

Exotic should implement internal CRM push notifications in **three layers**, not one:

1. **Foundation:** Laravel database notifications for durable per-user notifications.
2. **Realtime:** Laravel broadcast notifications for instant in-app updates.
3. **Optional later:** browser web push for alerts when the CRM tab is closed or inactive.

For the **current CRM codebase**, the best first production path is:

- use Laravel's native `database` notification channel first
- add a notification center and unread badge in the CRM
- start with **polling** for the first version if speed matters
- then add **broadcast notifications** for instant delivery
- keep true browser web push as **phase 3**, only for high-priority alerts

This approach is better than jumping straight to browser push because it gives Exotic:

- a reliable internal notification history
- read / unread state
- simpler permissions and rollout
- less browser complexity
- a cleaner fit with the CRM's existing Laravel + React stack

---

## 2) What "internal push notifications" should mean for Exotic

For Exotic, internal push notifications should mean:

- a user-specific notification record inside the CRM
- an unread badge in the top bar
- a notification center panel for recent alerts
- in-app realtime delivery when the user is already logged in
- optional browser-level notifications later for urgent alerts

Examples:

- `payment.confirmed`
- `payment.failed`
- `payment.needs_review`
- `chat.user_replied`
- `chat.user_replied.unassigned`
- `chat.sla_breach`

The notification system should be **recipient-aware**, **market-aware**, and **actionable**:

- recipient-aware: deliver only to the right sales or admin users
- market-aware: respect each user's assigned markets
- actionable: every alert should deep-link to the relevant CRM screen

---

## 3) Official research summary

## 3.1 Laravel database notifications

Laravel's notification system already supports storing notifications in the database. Official docs show:

- notifications can define `toDatabase` or `toArray`
- database payloads are stored as JSON in the `notifications` table
- `databaseType` can customize the stored type
- `Notifiable` gives `notifications`, `unreadNotifications`, and `markAsRead`

Why this matters for Exotic:

- `App\Models\User` already uses `Notifiable`
- this gives us read state, history, and API access without inventing a custom inbox model from scratch
- it is the cleanest base for a notification icon and notification center

## 3.2 Laravel broadcast notifications

Laravel also supports broadcasting notifications in realtime. Official docs show:

- `toBroadcast` can define the realtime payload
- broadcast notifications are queued
- `broadcastType` can customize the event type
- the frontend can listen on a private channel like `App.Models.User.{id}` using `Echo.private(...).notification(...)`

Why this matters for Exotic:

- it is the right way to make the CRM feel instant
- it pairs naturally with the same notification object already stored in the database
- it supports a bell count, live toast, and dropdown refresh without page reload

## 3.3 Laravel Reverb

Laravel Reverb is the current first-party WebSocket server for Laravel broadcasting. Official docs show:

- install via `php artisan install:broadcasting`
- configure credentials and allowed origins
- run with `reverb:start`
- production needs process management and, at scale, Redis-backed horizontal scaling

Important fit note for this CRM:

- the current app is on Laravel `^10.0`
- current Reverb package metadata supports Laravel 10.47+ but requires **PHP 8.2+**
- this repo's `composer.json` still declares PHP `^8.1`

That means Reverb is possible, but only if production runtime and dependency policy are aligned first.

## 3.4 Browser push and service workers

Official MDN docs show:

- Push API requires an active **service worker**
- subscriptions are created through `PushManager.subscribe()`
- notifications are displayed via `ServiceWorkerRegistration.showNotification()`
- secure context is required

This is a different layer from normal in-app notifications.

Why this matters for Exotic:

- browser push is useful when a salesperson is not actively inside the CRM tab
- but it adds permission prompts, service worker lifecycle, subscription storage, and cross-browser behavior management
- it should be treated as an extension of the internal notification system, not the foundation

## 3.5 Laravel web push package

The official `laravel-notification-channels/webpush` package adds a Laravel notification channel for browser push. Official package docs show:

- install with Composer
- add `HasPushSubscriptions` to the notifiable model
- publish migrations and config
- generate VAPID keys with `php artisan webpush:vapid`
- save subscriptions via `updatePushSubscription(...)`
- delete subscriptions via `deletePushSubscription(...)`
- expired subscriptions are cleaned up automatically

Important Safari note from the package docs:

- `VAPID_SUBJECT` must be valid when targeting Safari / iOS web push

---

## 4) Current CRM fit

The current CRM is already close to a clean internal notification implementation:

- [User.php](/Users/ian/Projects/exotic-crm/app/Models/User.php) already uses `Notifiable`
- [BroadcastServiceProvider.php](/Users/ian/Projects/exotic-crm/app/Providers/BroadcastServiceProvider.php) already calls `Broadcast::routes()`
- [routes/channels.php](/Users/ian/Projects/exotic-crm/routes/channels.php) already has a private per-user channel
- [config/broadcasting.php](/Users/ian/Projects/exotic-crm/config/broadcasting.php) already includes broadcaster config stubs
- [resources/js/bootstrap.js](/Users/ian/Projects/exotic-crm/resources/js/bootstrap.js) already contains commented Echo bootstrap code
- [Dashboard.jsx](/Users/ian/Projects/exotic-crm/resources/js/pages/Dashboard.jsx) already has a 30-second refresh pattern and a top action/header area that can host a notification bell
- [useDashboardWidgets.js](/Users/ian/Projects/exotic-crm/resources/js/hooks/useDashboardWidgets.js) already supports dashboard-level configurable widgets

There are also two important boundaries:

1. The existing push-provider stack is **not** the right base for internal CRM notifications.
   - [PushProviderService.php](/Users/ian/Projects/exotic-crm/app/Services/PushNotification/PushProviderService.php)
   - [SendPushNotificationJob.php](/Users/ian/Projects/exotic-crm/app/Jobs/SendPushNotificationJob.php)
   - This stack is built for outbound subscriber / campaign push, not per-user staff notifications.

2. Queue execution is not production-safe by default for realtime notifications.
   - [config/queue.php](/Users/ian/Projects/exotic-crm/config/queue.php) defaults to `sync`
   - [Kernel.php](/Users/ian/Projects/exotic-crm/app/Console/Kernel.php) only runs the worker loop if the queue connection is not `sync`

So the CRM already has the right **shape**, but it still needs the actual internal notification implementation.

---

## 5) Recommended architecture for Exotic

## 5.1 Foundation: database notifications

Implement internal CRM notifications using Laravel's native `notifications` table first.

### Why

- native to Laravel
- read / unread built in
- no extra realtime dependency required for the first release
- easy to expose through API
- durable audit trail for staff-facing alerts

### Recommended notification object

Create one operational notification class family, for example:

- `OperationalAlertNotification`
- `PaymentAlertNotification`
- `ChatAlertNotification`

Each notification payload should include:

- `type`
- `title`
- `body`
- `severity`
- `platform_id`
- `platform_name`
- `client_id` nullable
- `lead_id` nullable
- `conversation_id` nullable
- `payment_id` nullable
- `action_url`
- `source_event_key`
- `occurred_at`

### Delivery rule

For phase 1, every internal notification should always go through:

- `database`

That guarantees:

- the bell badge count works
- users can see missed alerts after refresh or login
- support and sales have one internal source of truth

## 5.2 Realtime layer: broadcast notifications

After the database channel is in place, add Laravel broadcast notifications so the CRM updates instantly.

### Why

- bell counts update instantly
- toast notifications can appear without refresh
- notification panel stays current
- dashboard elements can update without manual polling

### Delivery rule

For phase 2, high-value internal notifications should use:

- `database`
- `broadcast`

The `database` copy remains the durable inbox.  
The `broadcast` copy is the live delivery mechanism.

## 5.3 Optional browser push layer

Browser push should be added only after the internal notification center is working well.

### Good use cases

- urgent unassigned customer replies
- SLA breaches
- payment review items waiting too long
- high-value payment confirmation for assigned owners

### Do not use browser push for

- every low-priority CRM event
- noisy activity streams
- updates that are already visible and non-urgent

Browser push should be an **opt-in urgency layer**, not the default channel.

---

## 6) Recommended rollout path

## Phase 1: internal notification center

Ship the durable in-app system first.

### Build

- create Laravel `notifications` table
- create CRM notification classes
- add notification API endpoints
- add unread badge in the top bar
- add notification panel / center
- add mark-as-read and mark-all-read actions
- start with polling every 15 to 30 seconds if needed

### Why this first

- fastest route to production value
- lowest dependency risk
- no service worker needed
- no browser permission friction

## Phase 2: realtime in-app delivery

Add broadcasting once the inbox model is stable.

### Build

- enable Echo on the frontend
- choose broadcaster:
  - **Option A:** Pusher / Ably for fastest path
  - **Option B:** Reverb for self-hosted first-party realtime
- broadcast notifications on per-user private channels
- show toasts and live unread count updates

### Recommendation

For the current CRM, use this decision rule:

- if Exotic wants the **fastest implementation with fewer infra changes**, use Pusher-compatible broadcasting first
- if Exotic wants **first-party self-hosted realtime** and production is confirmed on PHP 8.2+ with compatible Laravel dependencies, Reverb is the long-term better fit

## Phase 3: browser web push

Add browser push only after internal in-app delivery is trusted.

### Build

- install `laravel-notification-channels/webpush`
- add push subscriptions to `User`
- register a service worker
- add browser permission UX
- store subscriptions per user/device/browser
- use web push only for urgent opted-in alerts

---

## 7) Recommended implementation for the current Exotic CRM

## 7.1 Best first implementation

For **this exact codebase**, the best first implementation is:

1. Laravel database notifications
2. notification center + unread badge
3. polling for the first release
4. broadcast notifications in the second release
5. browser web push later

This is the right choice because:

- the CRM already has `Notifiable`
- the CRM already has broadcast route scaffolding
- the frontend already uses React Query, which makes initial polling straightforward
- the queue stack is not yet guaranteed to be production-ready for live broadcasts
- the current push-provider subsystem serves a different use case

## 7.2 Why not start with Reverb immediately

Do not make Reverb the first milestone unless you first confirm:

- production PHP is 8.2+
- Laravel dependencies are compatible with current Reverb requirements
- deployment can run a long-lived websocket process
- allowed origins and SSL proxying can be configured safely
- queue and monitoring are ready

Reverb is attractive, but it is an **infrastructure step**, not only a feature step.

## 7.3 Why not start with browser push immediately

Do not start with web push because it introduces:

- service worker management
- browser permission UX
- subscription lifecycle handling
- multi-device duplication concerns
- more production debugging complexity

Exotic should first prove:

- who should receive what
- how alerts should be grouped
- how notifications are acknowledged
- how read state affects the workflow

That should happen inside the CRM first.

---

## 8) Proposed internal notification model

## 8.1 Notification kinds

Recommended internal kinds:

- `payment.confirmed`
- `payment.failed`
- `payment.needs_review`
- `chat.client_replied`
- `chat.reply_needs_assignment`
- `chat.sla_breach`
- `system.integration_warning`

## 8.2 Severity

Recommended severity scale:

- `info`
- `warning`
- `critical`

## 8.3 CTA behavior

Every notification should have:

- a primary destination URL
- a compact summary
- enough metadata to render market badge, owner state, and time

Examples:

- `Open payment`
- `Open chat`
- `Review client`
- `Match conversation`

## 8.4 Recipient routing

Internal notifications should be routed by:

- assigned owner
- assigned market access
- role
- alert type
- severity

Examples:

- assigned client reply -> assigned agent
- unassigned Kenya chat -> Kenya sales users with market access
- payment needs review -> payment ops or market leads

---

## 9) UI / UX recommendation

Internal CRM push notifications should appear in **two surfaces**:

1. **Top-bar notification bell**
2. **Dashboard message / notification widgets**

## 9.1 Top-bar notification bell

Recommended behavior:

- unread badge count
- click opens notification center panel
- grouped by `Unread` and `Recent`
- compact rows, not large cards
- market badge visible
- one clear text action per row
- mark-as-read on open or explicit acknowledge, depending on item type

## 9.2 In-app toasts

Use realtime toasts sparingly:

- only for new high-value or urgent notifications
- not for every low-priority alert
- do not block workflow
- clicking the toast should route to the same target as the notification row

## 9.3 Dashboard fit

The dashboard should remain the structured work surface.

Recommended pattern:

- bell = awareness
- notification center = quick triage
- dashboard widgets = deeper operational queues

This matches the earlier Slack and Support Board planning direction.

---

## 10) Data and API design

## 10.1 Minimum backend changes

### Database

- add native Laravel `notifications` table
- optionally add `users.notification_preferences` later or a separate preferences table

### API

Recommended endpoints:

- `GET /api/crm/notifications`
- `GET /api/crm/notifications/unread-count`
- `PATCH /api/crm/notifications/{id}/read`
- `PATCH /api/crm/notifications/read-all`

### Query behavior

- default newest first
- filter by read state
- filter by severity
- filter by market when useful
- pagination for history

## 10.2 Notification payload shape

Recommended normalized payload:

```json
{
  "type": "chat.client_replied",
  "title": "New client reply",
  "body": "Nancy replied in Kenya support chat.",
  "severity": "warning",
  "platform_id": 12,
  "platform_name": "Kenya",
  "client_id": 542,
  "lead_id": null,
  "conversation_id": 9981,
  "payment_id": null,
  "action_url": "/crm/clients/542?tab=chat",
  "source_event_key": "sb:12:9981:442188",
  "occurred_at": "2026-04-12T10:00:00Z"
}
```

---

## 11) Production requirements and risks

## 11.1 Queueing

Internal notifications should not rely on the `sync` queue in production.

### Recommendation

- move production to `database` or `redis` queue
- keep broadcast / notification work off the request thread
- reserve fast queues for time-sensitive internal alerts

This matters because Laravel broadcast notifications are queued, and this CRM currently defaults to `sync`.

## 11.2 Dedupe and idempotency

Push notifications can become noisy very quickly.

Recommended safeguards:

- derive a stable `source_event_key`
- prevent duplicate notifications for the same event + user
- suppress low-value duplicates inside a time window
- handle replayed payment or chat events safely

## 11.3 Read state and action state

Read state is not the same as resolution.

Recommended distinction:

- `read_at` = user has seen it
- resolution state stays on the underlying entity

This avoids turning the notification center into a second workflow database.

## 11.4 Shared-device and privacy risk

If staff share machines or sessions:

- unread counts can leak sensitive market activity
- browser push can appear on locked screens

Mitigations:

- keep browser push opt-in
- allow notification preference control by user
- avoid placing sensitive PII in the browser push body

## 11.5 Browser push risk

Browser push introduces:

- permission denial
- service worker cache / lifecycle bugs
- duplicate device subscriptions
- vendor-specific behavior differences

This is why it should remain phase 3.

---

## 12) Dependencies by phase

## Phase 1 dependencies

Required:

- none beyond native Laravel notifications and a migration

Likely additions:

- notification controller endpoints
- frontend notification panel components

## Phase 2 dependencies

Required:

- backend broadcast driver
- frontend Echo client

Possible packages:

- `laravel-echo`
- `pusher-js`
- `pusher/pusher-php-server`
- optionally `laravel/reverb`

## Phase 3 dependencies

Required:

- `laravel-notification-channels/webpush`
- service worker
- VAPID keys
- subscription endpoints

---

## 13) Recommended final decision

Exotic should implement internal push notifications as:

- **database notifications first**
- **broadcast realtime second**
- **browser web push third**

This gives the CRM a world-class internal notification foundation without overcommitting to browser push too early.

Short version:

- if you want something dependable quickly: **database + polling**
- if you want the CRM to feel instant: **add broadcasting**
- if you want alerts when the CRM is not open: **add browser web push later**

---

## 14) Sources

Primary sources used:

- Laravel Notifications: https://laravel.com/docs/10.x/notifications
- Laravel Broadcasting: https://laravel.com/docs/10.x/broadcasting
- Laravel Reverb: https://laravel.com/docs/13.x/reverb
- Laravel Reverb package metadata: https://github.com/laravel/reverb/blob/main/composer.json
- MDN Notifications API: https://developer.mozilla.org/en-US/docs/Web/API/Notifications_API
- MDN Push API: https://developer.mozilla.org/en-US/docs/Web/API/Push_API
- MDN `showNotification()`: https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerRegistration/showNotification
- Laravel Web Push channel: https://github.com/laravel-notification-channels/webpush

