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
