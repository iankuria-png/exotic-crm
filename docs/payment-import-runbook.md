# Payment Import Runbook (All Markets)

## Goal
Move country teams from spreadsheet payment tracking into CRM import + reconciliation workflow.

## Required Columns
- `payment_date`
- `amount`
- At least one identifier: `phone` or `transaction_reference` or `profile_url`

## Recommended Columns
- `currency`
- `status` (`completed`, `pending`, `failed`)
- `subscription_type` (`new`, `renewal`, etc.)
- `notes`

## Supported File Types
- `.csv`
- `.xlsx` (first worksheet is parsed)

## Preview + Commit (CRM API)
1. Preview:
`POST /api/crm/payments/import/preview`
2. Commit:
`POST /api/crm/payments/import/commit`

## CLI Backfill (Recommended for large files)
Preview:
`php artisan crm:import-payments {platform_id} {file_path} --reason="Kenya legacy preview"`

Commit:
`php artisan crm:import-payments {platform_id} {file_path} --commit --reason="Kenya legacy backfill"`

## KPI Endpoint
Use to track import health by market/time window:
`GET /api/crm/payments/import/kpis?platform_id={id}&from=YYYY-MM-DD&to=YYYY-MM-DD`

## Reconciliation Rules
- Low-confidence payments should be put in `manual_review`.
- Subscription creation is blocked unless reconciliation confidence is `high`.
- Use queue actions to resolve review state and confirm matches before creating subscriptions.

## Country Rollout Checklist
1. Kenya: run legacy backfill in preview first, then commit.
2. Tanzania: run backfill and review manual-review queue daily.
3. Pilot one additional market after Kenya/Tanzania KPI stability.
4. Weekly KPI review:
`duplicate_rate_pct`, `auto_high_rate_pct`, `manual_review_open`, unresolved aging buckets.
