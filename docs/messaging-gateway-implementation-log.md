# Messaging Gateway Implementation Log

## Phase 1 - Shared messaging primitives

Status: complete

Canonical phone format: digits-only international format, such as `254748612016`. The existing `PhoneNormalizer` returns digits only, so Phase 1 keeps that as the shared internal/database format. The `messaging_suppressions.phone_e164` column name follows the plan, but values are stored without a leading plus sign.

Files changed:

- `app/Models/MessagingSuppression.php`
- `app/Models/MessagingWebhookEvent.php`
- `app/Services/Messaging/SuppressionService.php`
- `app/Services/NotificationService.php`
- `app/Support/CrmAuditAction.php`
- `database/migrations/2026_05_24_000001_create_messaging_suppressions_table.php`
- `database/migrations/2026_05_24_000002_create_messaging_webhook_events_table.php`
- `database/migrations/2026_05_24_000003_extend_messaging_phase_one_enums.php`
- `tests/Feature/MessagingSuppressionServiceTest.php`
- `tests/Unit/Support/PhoneNormalizerTest.php`
- `docs/messaging-gateway-implementation-log.md`

Tests added/updated:

- Added `MessagingSuppressionServiceTest` for active suppression lookup, channel `all`, active idempotency, revoke behavior, and repeat suppression history.
- Updated `PhoneNormalizerTest` for Kenyan default prefix, short local number prefixing, and `00` international prefix handling.

Commands run:

- `php artisan test tests/Unit/Support/PhoneNormalizerTest.php tests/Feature/MessagingSuppressionServiceTest.php` - passed, 11 tests / 20 assertions.
- `php artisan test tests/Feature/PaymentFailureSmsAlertsTest.php --filter='dispatch|sms|notification|alert'` - passed, 7 tests / 34 assertions.
- `php artisan test tests/Feature/CrmStreamFourAuthorizationTest.php --filter='sms_provider_test_dispatch'` - passed, 1 test / 7 assertions.
- `php -l` on all added/modified PHP files - passed.
- `php artisan test` - did not complete. The run showed unrelated existing failures in `PaymentLinkServiceTest`, `BillingConfigBootstrapTest`, and `ClientSyncExclusionTest`, then exhausted PHP's 128 MB memory limit in `CrmPushCampaignTest::test_paste_upload_endpoint_queues_large_non_dry_run_payloads`.

Known deferrals:

- No user-facing WhatsApp sending UI.
- No conversation, campaign, payment-link, or credential WhatsApp dispatch.
- No Baileys runtime behavior.
- No AI workflow.
- Request validators remain closed to producer-side `whatsapp` values until their planned phases.
- No frontend assets changed, so `npm run build` was not run.

Plan mismatches:

- None identified in the Phase 1 scope.

## Phase 2 - Meta Cloud API engine, profile storage, dispatcher, and admin UI

Status: complete

Files changed:

- `app/Http/Controllers/CRM/MessagingController.php`
- `app/Http/Controllers/CRM/SettingsController.php`
- `app/Models/WhatsAppMessage.php`
- `app/Models/WhatsAppProviderProfile.php`
- `app/Models/WhatsAppRoutingRule.php`
- `app/Models/WhatsAppSender.php`
- `app/Services/Messaging/DispatchResult.php`
- `app/Services/Messaging/Engines/MetaCloudApiEngine.php`
- `app/Services/Messaging/MessageRecipient.php`
- `app/Services/Messaging/MessagingDispatcher.php`
- `app/Services/Messaging/NormalizedInbound.php`
- `app/Services/Messaging/SendRequest.php`
- `app/Services/Messaging/SendResult.php`
- `app/Services/Messaging/WhatsAppEngineInterface.php`
- `app/Services/Messaging/WhatsAppGatewayService.php`
- `config/services.php`
- `database/migrations/2026_05_24_000003_extend_messaging_phase_one_enums.php`
- `database/migrations/2026_05_24_000004_create_whatsapp_phase_two_tables.php`
- `resources/js/components/settings/messaging/*`
- `resources/js/pages/Settings.jsx`
- `routes/api.php`
- `tests/Feature/MessagingPhaseTwoTest.php`
- Vite build assets in `public/build`

Tests added/updated:

- Added `MessagingPhaseTwoTest` for profile secret encryption/masking, WhatsApp template authoring, Meta text dispatch payloads, audit/timeline writes, kill-switch blocking, suppression-before-HTTP ordering, idempotency-key dedupe, and an env-gated live Meta sandbox send.

Commands run:

- `php artisan test tests/Feature/MessagingPhaseTwoTest.php tests/Feature/MessagingSuppressionServiceTest.php tests/Unit/Support/PhoneNormalizerTest.php` - passed, 17 tests / 49 assertions, 1 skipped because Meta sandbox env vars are not configured.
- `php artisan test tests/Feature/CrmStreamFourAuthorizationTest.php --filter='templates|sms_provider_test_dispatch'` - passed, 2 tests / 8 assertions.
- `npm run build` - passed.
- `php -l` on the new/modified Phase 2 PHP entry points - passed.

Known deferrals:

- Conversation, renewal, campaign, payment-link, and credential producers remain unwired.
- Producer validators remain closed except `SettingsController` template authoring, which now accepts `channel=whatsapp` as planned for Phase 2.
- No Baileys runtime behavior or sidecar code was added.
- No AI workflow was added.
- WhatsApp fallback is only SMS fallback through `MessagingDispatcher`; no alternate WhatsApp engine fallback chain is exposed.

Plan mismatches:

- The plan's `MessageRecipient` DTO included `paymentId`, but the consolidated `whatsapp_messages` table omitted `payment_id`. Added nullable `payment_id` to `whatsapp_messages` so planned Phase 4 payment-link sends have a real audit join.
- The Phase 1 enum migration needed to preserve the repository's existing credential template categories and rebuild SQLite enum checks for tests. Corrected the migration while keeping MySQL/Postgres behavior additive.
