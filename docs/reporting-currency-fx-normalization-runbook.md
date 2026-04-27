# Reporting Currency and FX Normalization Runbook

Date: 2026-04-26

Last hardened: 2026-04-27

## Decision

Use USD as the default reporting currency. KES remains a market/billing currency where Kenya payments are charged and reconciled, but USD is better for platform-wide reporting because it is recognizable across markets and easier for management, finance, and investors to compare.

This feature is read-side reporting normalization. It does not change payment initiation, provider callbacks, payment matching, subscription creation, wallet billing, or stored native payment amounts.

## Current Hardening

The reporting layer canonicalizes currency codes before asking the FX provider for a rate.

- `CFA` and `FCFA` are never sent directly to CurrencyAPI because they are ambiguous and can trigger provider validation errors.
- If platform or country context proves a West African CFA market, `CFA` resolves to `XOF` for rate lookup while the API response still preserves native `CFA` in `source_breakdown`.
- If platform or country context proves a Central African CFA market, `CFA` resolves to `XAF`.
- If context cannot prove the zone, the result is `partial=true` with `missing_currencies=["CFA"]`; no provider request is made.
- Provider 4xx/5xx responses, outages, and zero/invalid rates return partial metadata instead of breaking Dashboard, Reports, Payments, or Team.

## Production Shape

```text
Payments table          Reporting FX rates          Admin setting
native amount/currency  source -> USD by date       target=USD
created/completed date  cached snapshots            provider=currencyapi
        |                       |                         |
        +-----------+-----------+-------------------------+
                    |
          ReportingCurrencyService
          - native breakdown stays intact
          - event-date conversion
          - stale/partial metadata
                    |
      +-------------+-------------+-------------+-------------+
      |                           |             |             |
  Dashboard                    Reports       Payments        Team
  KPIs/charts                  CSV + tables   summaries      leaderboard
```

## Stakeholder UX

Admin:
- Go to `Settings -> Dashboard -> Reporting Currency`.
- Set target currency, normally `USD`.
- Keep page override enabled if managers should switch between `Flat` and `Native`.
- Watch provider health and stale-day policy before relying on normalized totals.

Managers:
- Dashboard, Reports, Payments, and Team show a `Flat / Native` control.
- `Flat` shows converted USD as the primary value and keeps native amounts as secondary context.
- `Native` shows source currencies only, useful for reconciliation and market operations.

Finance:
- Exports include native rows and normalized rows where normalized values exist.
- Every normalized API field includes metadata for stale, partial, missing rates, provider, policy, and as-of date.
- `Partial FX` in the UI means the native values are still authoritative but the flat USD comparison is incomplete.

Sales and operators:
- Payment table rows remain native-first.
- Payment matching and subscription workflows are unchanged.
- Team revenue is now collected-payment revenue, not activated-deal value.

## Regression Boundaries

Must not change:
- Payment status transitions.
- Provider callbacks, STK retry, payment links, import commit, manual proof review, or auto-match.
- Subscription provisioning and deal creation from matched payments.
- Stored `payments.amount`, `payments.currency`, `deals.amount`, wallet balances, or billing default currency.

Allowed read-side changes:
- Add normalized fields beside existing native fields.
- Sort management comparisons by normalized totals when `currency_mode=flat`.
- Return `partial=true` instead of inventing a bad converted total when rates are missing.
- Canonicalize display aliases for rate lookup only; never rewrite payment, deal, wallet, or platform currency fields from reporting code.

## API Contract

Shared request parameters:

```text
currency_mode=native|flat
reporting_currency=USD
```

Shared response fields:

```text
source_breakdown
source_currency_count
source_scalar_amount
normalized_total
normalized_currency
normalized_display
normalization_meta.provider
normalization_meta.rate_policy
normalization_meta.stale
normalization_meta.partial
normalization_meta.missing_rate_count
normalization_meta.missing_currencies
normalization_meta.missing_currency_reasons
normalization_meta.currency_aliases
normalization_meta.rows
normalization_meta.as_of
```

## Rollout Checklist

1. Run migrations.
2. Seed or backfill historical FX snapshots for active payment currencies into `reporting_fx_rates`.
3. In Settings, confirm target currency is `USD`.
4. Verify Dashboard all-market collected revenue shows USD with native secondary values.
5. Verify Reports CSV includes native and normalized values.
6. Verify Payments summary cards show USD in flat mode while payment rows stay native.
7. Verify Team leaderboard ranks by collected-payment normalized revenue in flat mode.
8. Keep `Native` available during finance reconciliation.
9. Check all active platform currencies for provider-safe ISO codes. Replace ambiguous market config such as `CFA` with `XOF` or `XAF` when the business has confirmed the zone.

## Verification Commands

```bash
php artisan test tests/Feature/ReportingCurrencyServiceTest.php
php artisan test tests/Feature/ReportingCurrencySurfacesTest.php
php artisan test tests/Feature/TeamLeaderboardAggregationTest.php tests/Feature/ComputeDailyStatsCommandTest.php
php artisan test tests/Feature/WalletSettingsPhaseFourTest.php tests/Feature/PaymentQueueSandboxVisibilityTest.php --filter='dashboard|reports|payment|leaderboard|daily|sandbox|wallet|successful|mixed|country|exclude|visibility'
php artisan test tests/Feature/LegacyPaymentCompatibilityTest.php tests/Feature/PaymentsWorkspaceLegacyOperationsTest.php tests/Feature/PaymentReconciliationConfidenceTest.php tests/Feature/PaymentImportFlowTest.php tests/Feature/SubscriptionProvisioningConvergenceTest.php tests/Feature/CheckExpiredSubscriptionsCommandTest.php tests/Feature/Billing/CanonicalPaymentStateIntegrationTest.php tests/Feature/Billing/BillingWorkspaceEndpointsTest.php
npm run build
```

Known verification note: `tests/Feature/ManualPaymentBundleCommitTest.php` currently posts legacy `deal_id` bundle items while the controller validates `client_id` and `product_id`. That failure is outside reporting FX and should be repaired separately before using that file as a release gate.

## Rollback

If FX reporting is wrong, switch affected pages to `Native` or disable user override in Settings while keeping billing live. The code path is additive; rollback does not require payment data repair because no billing or payment rows are rewritten.
