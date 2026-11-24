# CRM → EWMS Customer Service payment evidence

Implementation: 24 November 2026, approved narrow CS scope. These are server-to-server read endpoints. CRM supplies facts; EWMS owns effective-dated platform assignments, ambiguity holds and HOD/CEO verification. A successful payment never automatically approves an employee KPI.

## Disabled by default

CRM configuration:

```dotenv
EWMS_KPI_ENABLED=false
EWMS_KPI_ALLOWED_PLATFORM_IDS=
```

The empty platform list denies all requests. Populate it with the explicitly agreed comma-separated CRM platform IDs before enabling a pilot. Invalid configuration also denies all. The plan's sample `[1,2]` is illustrative, not an approved pilot list.

EWMS configuration:

```dotenv
CRM_KPI_CONNECTION_ENABLED=false
CRM_KPI_API_URL=https://crm.exotic-online.com/api/internal/ewms/kpi/payment-evidence
CRM_KPI_SUMMARY_API_URL=https://crm.exotic-online.com/api/internal/ewms/kpi/payment-evidence/summary
CRM_KPI_API_TOKEN=
CRM_KPI_TIMEOUT_SECONDS=10
```

Reuse the existing intended integration account/token after private identity and custody checks. Do not place the token in Git, email, logs, URLs or the browser. EWMS does not receive database credentials. Configure the token privately and retain its connection/scheduler switch off until joint acceptance.

## Authentication and limits

Both routes require a persisted Sanctum bearer token with exactly `ewms-kpi:evidence.read`, no wildcard or additional abilities. Its owner must be an active `ewms_kpi_integration` account. Sanctum enforces token expiry/revocation; cookie-only sessions, ordinary staff tokens and MCP tokens cannot use these routes. EWMS tokens cannot be used on other API routes. All requested platforms must exist and belong to the configured integration allow-list.

The integration middleware enforces 30 requests/minute per token **after** identity checks. The existing global API limiter remains. Throttling lives inside this middleware so Laravel's route-middleware priority cannot accidentally key requests before the token identity is set.

An optional CLI issuer supports an existing verified identity:

```sh
php artisan ewms:kpi-token --help
```

Supply `--user-id` with the privately verified existing integration account ID, `--output` with a new filename ending in `.json`, and optionally `--expires-in` (1–365 days, default 90). It writes a mode-0600 token file under `storage/app/private/ewms/`, prints no secret, refuses an existing filename and preserves all existing tokens. Issuance is explicit, not part of migration/deployment; no account is created or password changed. Remove the private transfer file through the normal secret-handling process after custody is confirmed.

## Records

`POST /api/internal/ewms/kpi/payment-evidence`

Recent evidence request:

```json
{"platform_ids":[1],"from":"2026-11-14","to":"2026-11-21","limit":50}
```

Require 1–100 distinct positive platform IDs, an inclusive 1–93 day period, and limit 1–500 (default 50). Unknown fields, `crm_user_ids`, invalid dates and mixed modes are rejected. Dates are UTC, from midnight inclusive to midnight after `to` exclusive. Sort is `recorded_at DESC, payment_id DESC`. `meta.truncated` explicitly identifies a capped list. Never calculate KPI totals from that list.

Returned record fields, in order:

```text
payment_id, crm_user_id, platform_id, recorded_at, amount, currency,
transaction_reference, status, reconciliation_state, resolution_code,
is_reportable_success
```

Identifiers/counts are integers, `crm_user_id` may be null, timestamps are RFC3339 UTC and amounts are exact decimal strings. Success is integer 0/1. Customer names/phone/email, client/deal IDs, payloads and staff identity details are excluded. Payment IDs/references remain confidential, linkable operational identifiers; do not describe this as irreversible anonymization.

Reviewed-evidence refresh uses the same endpoint:

```json
{"platform_ids":[1],"payment_ids":[123,456]}
```

Require 1–100 distinct positive payment IDs, with no dates or limit. Refresh reads all matching IDs from the authorized view regardless of historical date; it never truncates. `meta.missing_payment_ids` includes requested IDs absent from the authorized projection, without a reason or cross-platform existence information. EWMS batches larger reviewed sets into groups of 100 and holds changed or missing evidence for HOD re-review. This catches a reversal, a changed confirmation date or a record newly excluded as test data even when aggregate totals happen to be unchanged.

## Complete daily summary

`POST /api/internal/ewms/kpi/payment-evidence/summary`

```json
{"platform_ids":[1],"from":"2026-11-14","to":"2026-11-21"}
```

Aggregates the entire matching view population, grouped by platform, UTC confirmation day and native currency label. There is no record limit. Each row contains:

```text
platform_id, recorded_on, currency,
candidate_count, candidate_amount,
reportable_count, reportable_amount,
failed_count, failed_amount
```

`meta.complete=true` accompanies successful summaries. Candidate counts include all eligible statuses. Reportable success is completed/expired, outside manual review and not resolved as reversed/invalid_reference. Failed means status `failed` only. Zero amounts remain valid facts; payment counts are not automatically employee achievements. No payment references or customer fields appear in summaries.

All responses include `contract=cs-payment-evidence-v1`, `population=crm_cs_revenue`, `timezone=UTC`, `date_basis=completed_at_or_created_at` and `generated_at` in metadata. Successful evidence and 503 responses use Cache-Control no-store.

## View semantics and reconciliation

Only `ewms_kpi_payment_evidence` supplies financial rows. The new migration retains eleven columns and applies:

- `COALESCE(payments.completed_at, payments.created_at)` as recorded time.
- Current `Payment::businessVisible()` exclusions, including legacy JSON test mode, non-business bundle states and confirmed collection-sales guards.
- Current `Payment::excludingWalletTopups()` commercial scope: exclude `premium_content_sale`, `send_love`, `monetize_pass`, `tour_pass`, `wallet_topup`, `visitor_contact_unlock`; retain legacy null purpose as the CRM does.
- Native currency uppercased/trimmed, with missing payment currency resolved from non-personal platform metadata. No invented USD, conversion or cross-country pooling. Historical labels such as CFA remain scoped by platform. An unresolved currency or requested record timestamp fails the read clearly.

`crm_user_id` remains exactly `payments.user_id`; it does not establish employee attribution. EWMS uses reviewed effective-dated platform ownership and holds missing/overlapping assignments.

The service opens a separate primary database connection, sets and verifies its session timezone `+00:00`, and makes it read-only. It does not change the shared application connection. Source reads execute in read transactions; request audits and token-use metadata use the normal application connection.

For acceptance, compare record sets/counts/native amounts with `Payment::reportableSuccessful()->excludingWalletTopups()` using the same platform set, confirmation-date interval and resolved currency. The existing dashboard's creation-date window is unchanged by this feature. The 23 November research reproduced a NGN20,000 Nigeria clock bridge and KES22,050 Kenya broader-purpose bridge for 14–21 November UTC. Do not demand equality between differently defined reports.

## Audit and errors

Before returning a successful response, persist an `audit_log` row with actor, token ID, endpoint/action, mode, requested platform/payment IDs or period, contract, returned count, generated time and request ID. No bearer token, payment reference or payment payload is logged. Audit persistence failure returns 503 with no data body.

- 401: absent/invalid/expired/revoked bearer token.
- 403: wrong integration role/ability/status or any forbidden/unknown platform.
- 422: invalid request fields, modes or bounds.
- 429: per-token/global rate limit; integration responses include Retry-After.
- 503: disabled feature, source/view/UTC/currency/timestamp error or audit failure.

`data=[]` is a successful empty result, never a fallback for an unavailable source. Complete summaries and recent examples are different operations.

## Release and recovery

Backend-only: no frontend build or WordPress upload. Preserve unrelated work and the current production payment integrations when assembling a scoped release from the current remote baseline. Do not blindly pull/reset the older dirty local main checkout.

The original `2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php` stays immutable. Apply only the new migration after authorization and preflight:

```sh
php artisan migrate --path=database/migrations/2026_11_24_140000_update_ewms_kpi_payment_evidence_contract.php --force
```

Before running it, independently verify the exact existing view definition, migration ledger, grants/definer, schema dependencies and a private recovery copy of SHOW CREATE VIEW. The migration refuses definition drift. Its pinned schema-independent MariaDB canonical fingerprints were verified on production baseline and isolated MariaDB11.4.13; a different canonical engine definition is a refusal to investigate. MariaDB uses CREATE OR REPLACE while preserving the existing definer/security and grants. SQLite uses transactional replacement for testing. No source financial rows are updated.

A migration `--pretend` is not an effective preflight for this guarded migration: Laravel's pretend mode suppresses the metadata reads required to establish its definition. Use the registered read-only Ops inspection and exact source/definition preview, then the targeted migration above. Capture/inspect actual post-state after a DDL failure; MariaDB view replacement is not protected by an application transaction.

Order: deploy with both integration switches off → verify view/config/UTC/permissions → enable CRM only for the agreed pilot allow-list → joint manual records/summary/ID-refresh/reconciliation/HOD acceptance with EWMS scheduling off → enable EWMS scheduling. A failed pilot disables CRM again.

Rollback disables both integration switches first. Restore only this release's source/config and the previous view definition, preserving audits and existing identity/tokens. A targeted migration rollback of this file restores the frozen original view, with a current-definition guard; never run a blanket rollback over unrelated migrations. Inspect any new token separately rather than silently revoking an existing one.

## Verification

Use repository PHP8.2. `artisan test tests/Feature/Ewms` uses private temporary SQLite files; it never migrates the configured application database. With `EWMS_TEST_MYSQL_PORT` set to an isolated local MariaDB11.4.13 port, it creates/drops only random `ewms_kpi_test_*` databases. Native tests cover UTC isolation, large decimal totals, view grants/source denial and migration roundtrip/drift. Run native correctness with `--exclude-group=ewms-benchmark`; run `--group=ewms-benchmark` separately, with other load tests stopped, for the 45,000-payment/100-platform/93-day measurement. The benchmark retains its 2-second local target; timings under host contention are recorded separately rather than treated as accounting failures. The original view test remains unchanged.

Record local tests, source baseline and deployment status in the owning task. Passing local tests does not establish deployment or successful scheduled EWMS use.
