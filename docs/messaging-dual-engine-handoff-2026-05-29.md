# Messaging Dual-Engine Handoff - 2026-05-29

## Current State

Repository: `/Users/ian/Projects/exotic-crm`  
Branch: `main`  
Status: implementation checkpoint, intended for deploy after review.

This handoff captures the WhatsApp dual-engine work and Messaging Cockpit UI upgrade so another developer can continue without relying on chat context.

## Goal

Complete Exotic CRM's controlled dual-engine messaging gateway:

- Meta Cloud API as the first stable WhatsApp path.
- Baileys sidecar as an isolated second WhatsApp engine.
- SMS fallback only when routing rules explicitly allow it.
- Sender-pool controls for pairing, repair, warmup, quarantine, and retirement.
- HMAC-protected Laravel-to-sidecar and sidecar-to-Laravel contracts.
- A professional Messaging Cockpit UI inside Settings.

## Important Guardrails

- Do not make Baileys required for normal CRM messaging.
- Do not bypass Meta as the first execution path.
- Do not fallback to SMS on every WhatsApp failure. Fallback must be driven by the routing rule.
- Do not reintroduce global `whatsapp_senders.phone_e164` uniqueness; retired senders must release the active phone marker.
- Do not store Baileys auth blobs on disk in the sidecar.
- Do not expose auth blobs through session-authenticated admin routes. Sidecar routes are HMAC-only.
- Do not implement AI workflows as part of this initiative.

## Files Changed So Far

Current `git status --short` showed:

- Modified:
  - `app/Http/Controllers/CRM/MessagingController.php`
  - `app/Http/Kernel.php`
  - `app/Models/WhatsAppMessage.php`
  - `app/Models/WhatsAppSender.php`
  - `app/Services/Messaging/DispatchResult.php`
  - `app/Services/Messaging/MessagingDispatcher.php`
  - `app/Services/Messaging/SendRequest.php`
  - `app/Services/Messaging/SendResult.php`
  - `app/Services/Messaging/WhatsAppGatewayService.php`
  - `app/Support/CrmAuditAction.php`
  - `config/services.php`
  - `resources/js/components/settings/messaging/KillSwitchToggle.jsx`
  - `resources/js/components/settings/messaging/MessagingArea.jsx`
  - `resources/js/components/settings/messaging/WhatsAppProfilesTable.jsx`
  - `routes/api.php`
  - `public/build/manifest.json`
- Added:
  - `app/Http/Controllers/CRM/MessagingSidecarController.php`
  - `app/Http/Middleware/VerifyWhatsAppSidecarHmac.php`
  - `app/Models/WhatsAppMessageAttempt.php`
  - `app/Services/Messaging/BaileysSenderPool.php`
  - `app/Services/Messaging/Engines/BaileysEngine.php`
  - `app/Services/Messaging/Sidecar/HmacSigner.php`
  - `app/Services/Messaging/Sidecar/RestoreTokenService.php`
  - `database/migrations/2026_05_29_000001_extend_whatsapp_senders_for_baileys.php`
  - `database/migrations/2026_05_29_000002_create_whatsapp_message_attempts_table.php`
  - new Vite build assets under `public/build/assets/`
- Deleted by build regeneration:
  - old Vite build assets under `public/build/assets/`

## Implemented Pieces

### Schema And Models

Added sender lifecycle fields to `whatsapp_senders`:

- `display_name`
- `auth_state_encrypted` as `LONGTEXT`
- `connection_status`
- `warmup_phase`
- `warmup_started_at`
- `daily_limit`
- `daily_sent_count`
- `daily_sent_resets_at`
- `quarantine_until`
- `last_message_at`
- `last_disconnect_reason`
- `consecutive_failures`
- `retired_at`
- `retired_reason`

The migration drops the old global `uniq_whatsapp_sender_phone` index and adds an active-only marker:

- MySQL: `active_phone_marker` generated virtual column.
- Non-MySQL: normal nullable column maintained by the model for tests.

Added `whatsapp_message_attempts` with:

- `whatsapp_message_id`
- `attempt_number`
- `engine`
- `provider_profile_id`
- `sender_id`
- `attempt_uuid`
- `status`
- `error_code`
- `error_message`
- `latency_ms`
- `started_at`
- `finished_at`

Added `WhatsAppMessageAttempt` model and `WhatsAppMessage::attempts()`.

Expanded `WhatsAppSender` with statuses, warmup phases, casts, active marker maintenance, and helpers:

- `isConnected()`
- `isQuarantined()`
- `hasDailyCapacity()`
- `canSend()`
- `markConnected()`
- `markDisconnected()`
- `markBanned()`
- `quarantine()`
- `limitForWarmupPhase()`

### HMAC And Restore Flow

Added `HmacSigner`:

- `sign($body)`
- `verify($body, $header, $secrets, $skewSeconds)`
- Header format: `t=<timestamp>,v1=<hash>`
- Clock skew default: 300 seconds.
- Supports current and previous secrets.

Added `VerifyWhatsAppSidecarHmac` middleware and alias:

- `whatsapp.sidecar.hmac`

Added `RestoreTokenService`:

- Issues one-shot restore tokens per sender.
- TTL defaults to 120 seconds.
- Uses cache keys for token hash and sender token rotation.
- `consume()` uses `Cache::pull()` to consume the token.

Added HMAC-protected API routes:

- `POST /api/crm/messaging/sidecar/restore-sessions`
- `GET /api/crm/messaging/sidecar/senders/{sender}/auth-blob`
- `POST /api/crm/messaging/webhook/baileys`

These routes are intentionally outside admin/session auth and protected by HMAC.

### Sidecar Controller

Added `MessagingSidecarController` with:

- `restoreSessions()`
  - Returns active non-retired Baileys senders with stored auth state.
  - Response shape: `{ senders: [{ sender_id, restore_token, expires_at }] }`
- `authBlob()`
  - Requires restore token plus HMAC.
  - Rate-limited per sender/IP.
  - Audits successful and failed fetches.
  - Returns decrypted auth state over TLS/HMAC.
- `baileysWebhook()`
  - Dedupes with `messaging_webhook_events`.
  - Handles:
    - `message.status`
    - `message.received`
    - `session.creds.update`
    - `session.disconnected`
    - `session.banned`
    - `pairing_code.update`

Watch this file carefully during tests. It may need adjustments against the actual audit service signature and inbound message expectations.

### Engine And Gateway

Added `BaileysSenderPool`:

- Picks connected, active, unquarantined senders with daily capacity.
- Orders by `last_message_at`, then `id`.
- Increments daily sent count on acceptance.
- Quarantines after repeated failures.

Added `BaileysEngine`:

- Uses selected sender.
- Requires a gateway-provided `attempt_uuid`.
- Signs sidecar `/messages` requests.
- Sends `Idempotency-Key` equal to the attempt UUID.
- Returns `SendResult` with `senderId`, `attemptUuid`, and `costMicros = 0`.

Updated `SendRequest`:

- Added `withContext()`.

Updated `SendResult`:

- Added `senderId`, `attemptUuid`, and `costMicros`.

Updated `DispatchResult`:

- Added `shouldFallbackToSms`.

Updated `MessagingDispatcher`:

- `whatsapp_with_sms_fallback` now sends SMS only when WhatsApp returns `shouldFallbackToSms = true`.

Refactored `WhatsAppGatewayService`:

- Injects both Meta and Baileys engines.
- Resolves primary + fallback profiles.
- Creates one parent `whatsapp_messages` row.
- Creates per-profile `whatsapp_message_attempts`.
- Skips/continues when profile kill switch or engine unavailable.
- Returns `shouldFallbackToSms` from the routing rule after all WhatsApp attempts fail.

The gateway cascade is covered by `tests/Feature/MessagingDualEngineTest.php`.

### Messaging Cockpit UI

The Settings Messaging area was redesigned substantially:

- Cockpit-style overview.
- Gateway readiness checklist.
- Route matrix with fallback visibility.
- Profiles section with Meta/Baileys labels.
- Test console/route preview concept.
- Suppressions, diagnostics, and activity panels.
- Better empty/error/loading states.

The UI work is in:

- `resources/js/components/settings/messaging/MessagingArea.jsx`
- `resources/js/components/settings/messaging/WhatsAppProfilesTable.jsx`
- `resources/js/components/settings/messaging/KillSwitchToggle.jsx`

`npm run build` had been run before the latest backend additions, generating new Vite assets. Re-run it after finishing UI/API changes.

The Sender Pool panel now calls the Baileys sender APIs for listing, pairing, repair, logout, and retirement.

## Partially Done Or Not Done

The following are intentionally still not complete:

- Baileys actual socket lifecycle.
- QR/pairing-code flow.
- Warmup phase progression.
- Route simulator backend output.
- Sidecar Redis-backed idempotency; the scaffold currently uses an in-memory store.
- Production Baileys auth-state adapter; the scaffold documents the required memory-only boundary.
- Full sidecar boot restore loop; Laravel restore/auth-blob endpoints exist, but the Node scaffold does not yet fetch and hydrate live Baileys sessions.

## Known Blocker From This Session

The local full Laravel suite did not finish cleanly in this environment:

- `php artisan test` hit the default PHP 128MB memory ceiling inside the existing `CrmPushCampaignTest` XLSX generation path.
- `php -d memory_limit=512M artisan test` still reported the same effective 128MB fatal in that path.
- Two unrelated existing/config-sensitive tests failed before the memory fatal:
  - `Tests\Feature\BillingConfigBootstrapTest::billing feature flags boot disabled by default`
  - `Tests\Feature\ClientSyncExclusionTest::delta sync uses wordpress modified watermark with overlap`

Messaging-focused suites pass.

## Verification Already Run

Syntax checks passed for:

```bash
php -l app/Services/Messaging/WhatsAppGatewayService.php
php -l app/Services/Messaging/Engines/BaileysEngine.php
php -l app/Services/Messaging/SendRequest.php
```

Current verification passed:

```bash
php artisan test tests/Feature/MessagingPhaseTwoTest.php tests/Feature/MessagingPhaseThreeTest.php tests/Feature/MessagingPhaseFourTest.php tests/Feature/MessagingSuppressionServiceTest.php
php artisan test tests/Feature/MessagingDualEngineTest.php
npm test --prefix services/whatsapp-sidecar
npm run build
```

Also passed PHP syntax checks for the changed messaging controller, sidecar controller, reset command, and gateway service.

## Immediate Next Steps

1. Replace the Node sidecar local send stub with a real Baileys socket adapter.
2. Add Redis persistence for sidecar idempotency before production traffic.
3. Add QR/pair-code lifecycle endpoints and surface live pairing codes in the cockpit.
4. Add warmup phase progression rules after observing sender behavior.
5. Re-run full Laravel tests in an environment where billing flags and client-sync fixtures match expected baseline and PHP has enough memory for the XLSX test.

## Tests To Add

Laravel:

- `it_does_not_sms_fallback_when_route_disables_sms`
- `it_records_audit_on_every_auth_blob_fetch`
- `it_resets_daily_sender_limits`
- `it_treats_session_banned_as_terminal_retirement`

Sidecar:

- Per-sender concurrency is `1`.
- Restore window stays open until all senders connect or terminally fail.
- Banned send drains queue and emits terminal failed statuses.
- Auth state stays memory-only.
- Metrics include connection status, queue depth, in-flight, daily sent/cap.

Frontend:

- Messaging cockpit renders empty Meta-only state.
- Route matrix shows fallback profile and SMS fallback.
- Sender pool renders connected/pairing/disconnected states.
- Disabled/danger actions require confirmation.
- Mobile sender pairing controls remain usable.

## Specific Implementation Notes

### Gateway Cascade

Keep this model:

- One `whatsapp_messages` parent row per logical message.
- One `whatsapp_message_attempts` row per WhatsApp profile attempt.
- `attempt_number` counts WhatsApp attempts only.
- SMS fallback is handled by `MessagingDispatcher`, not by `whatsapp_message_attempts`.

If primary and fallback profiles are both null but `fallback_to_sms = true`, the gateway should reject with `no_route` and `shouldFallbackToSms = true`.

### Provider Message IDs

Keep `whatsapp_messages.provider_message_id` for provider/server-side IDs.

For Baileys, the sidecar attempt UUID belongs in `whatsapp_message_attempts.attempt_uuid`. Do not overwrite `provider_message_id` with a temporary sidecar UUID.

MySQL unique nullable `provider_message_id` allows multiple `NULL` rows. That is intentional.

### Idempotency Scopes

There are two different idempotency layers:

- Laravel logical message idempotency: `whatsapp_messages.idempotency_key`.
- Sidecar send-attempt idempotency: `/messages` `Idempotency-Key`, using the attempt UUID.

Do not collapse them into one key.

### Auth Blob Restore

Target behavior:

- Sidecar boots.
- Sidecar calls Laravel `POST /sidecar/restore-sessions`.
- Laravel returns `{ senders: [{ sender_id, restore_token, expires_at }] }`.
- Sidecar fetches each auth blob with HMAC plus restore token.
- Restore token is one-shot and expires after 120 seconds.
- Sidecar keeps auth state in memory.
- Restore window ends when all returned senders connect or hit terminal failure after backoff budget.

### Banned Senders

`session.banned` should be terminal:

- Sidecar drains per-sender queue.
- Sidecar emits failed statuses with `error_code = sender_banned`.
- Sidecar emits `session.banned`.
- Laravel marks sender retired.
- No automatic re-cascade from drained callbacks.

Manual resend is a later operator action.

## Suggested Commit Split

1. `Add Baileys sender schema and message attempt records`
2. `Add sidecar HMAC and auth restore endpoints`
3. `Implement WhatsApp gateway cascade and Baileys engine`
4. `Add Baileys sender pool admin APIs`
5. `Add WhatsApp sidecar service scaffold`
6. `Wire Messaging Cockpit sender pool and diagnostics`
7. `Add dual-engine tests and regenerate build assets`

## Final Caution

This checkpoint is deployable for Laravel-side routing/cockpit preparation and Meta-to-sidecar cascade contracts. Do not point production Baileys routes at live traffic until the Node sidecar's local send stub is replaced by the real Baileys socket adapter and Redis idempotency.
