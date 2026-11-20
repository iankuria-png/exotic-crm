# EWMS payment evidence view

The EWMS Customer Service KPI pilot reads `ewms_kpi_payment_evidence` in the CRM database. This is a live SQL view; it does not copy, modify or approve payments.

## Contract

Columns, in order: `payment_id`, `crm_user_id`, `platform_id`, `recorded_at`, `amount`, `currency`, `transaction_reference`, `status`, `reconciliation_state`, `resolution_code`, `is_reportable_success`.

`crm_user_id` is exactly `payments.user_id`, including NULL. No ownership is inferred from a deal, confirmer or name. `recorded_at` is the payment's `created_at`, not its completion time. Amounts remain in their original currency; currency is uppercased and NULL becomes USD. There is no currency conversion or cross-currency total.

All payment statuses can appear. `is_reportable_success` is 1 only for completed/expired payments which are neither in manual review nor resolved as reversed/invalid_reference. This flag is not an EWMS verified result or HOD approval.

The requested portable exclusions remove classification `test`, case-insensitive provider environment `sandbox`, purpose `wallet_topup`, and payments belonging to bundles in `committing`, `compensation_failed` or `voided`. NULL metadata remains eligible; a dangling bundle ID follows the requested NOT EXISTS rule and remains eligible.

This deliberately implements Magonya's supplied contract, not the entire current `Payment::businessVisible()` scope: that scope also checks the legacy `payment_data.test_mode` flag and collection-sales confirmation state. Do not describe the view as full scope parity. No payload, customer, deal or staff identity data is selected. Transaction references are still financial identifiers and should be treated as confidential.

## Deploy

Migration: `database/migrations/2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php`.

After the authorized production pull and preflight/backup, run this specific migration so unrelated pending migrations are not included:

```sh
php artisan migrate --path=database/migrations/2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php --pretend --force
php artisan migrate --path=database/migrations/2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php --force
```

Creation fails if the view already exists, preserving the existing definition. Investigate an unexpected existing view instead of dropping it. No frontend build, seeder, configuration change or WordPress upload is needed.

Verify the migration ledger, exact column list and `SHOW CREATE VIEW ewms_kpi_payment_evidence`, then reconcile counts/success flags against the supplied SQL. Do not export payment rows in the email. Local focused verification uses `php artisan test --filter=EwmsKpiPaymentEvidenceViewTest` with the repository's PHP 8.2 binary. The default tests use a private in-memory SQLite connection; an optional empty disposable MySQL database must be named `ewms_kpi_test_*` and supplied through `EWMS_TEST_MYSQL_DATABASE`.

## Database access

The migration does not create users, grant privileges, open network access or reuse CRM application credentials. Before EWMS connects, a database administrator must configure a dedicated host-restricted account with SELECT on this view only, with no global/schema-wide privileges, no source-table access and no grant option. Use a secure channel for credentials. EWMS source host/account details must be confirmed before provisioning.

With MySQL/MariaDB's default DEFINER view security, the creating principal must retain the underlying SELECT privileges; the EWMS reader should not receive them. Verify `SHOW GRANTS` for the dedicated account and prove: SELECT on the view succeeds; SELECT on `payments`, `clients` and `users` is denied; UPDATE/INSERT/DELETE through the view is denied. The migration alone does not prove this access boundary. The view may be technically updatable for an account with broader grants; read-only access is enforced by permissions.

## Recovery

The migration's `down()` drops only the view and is repeatable; source payment and bundle rows are preserved. Revoke the dedicated account's view access before retiring the integration. Use the registered, reviewed production recovery process; do not use a blanket rollback that might target another release.
