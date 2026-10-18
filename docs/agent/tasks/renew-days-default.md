# Renew dialog 30-day default

## Root cause (18 Oct, production read-only evidence)
TZ Cherry (client 478743): weekly VIP TZS 35,000 renewed 6 Oct showed expiry 5 Nov. The Renew
dialog in `ClientDetail.jsx` and `Deals.jsx` defaulted "Additional days" to 30 for every plan;
`DealController::renewUnderLifecycleLock` copies the old deal's duration/amount, sets
`expires_at = now()+additional_days`, sends the same days to WP `activateClient`, and does not
record WP's returned expiry. Evidence: deal 28893 timeline `deal_renewed` additional_days 30.

## Fleet audit (ops check `crm-renew-duration-audit`, ops f016b5c)
236 renewals; 48 match the default-30 signature (30 days on a ≤14-day plan): 23 paid manual,
25 free trial (20 South Africa batch 29 Sep). Paid by agent: Daniel Kimani 14 (CI/SN/Togo,
Jul–Aug), Amos Mageto 6 (TZ, 2–6 Oct, 4 still active), Lydia Wahito 2 (ZW), Lavender Aoko 1 (NG).
Rows: `output/renew-days-2026-10-18/default-30-renewals.csv`. 32 other mismatches are typed
custom values (6/15/31 days), not the default. Extensions are inconclusive without payment amounts.

## Renew dialog default fix (shipped to origin/main 18 Oct; cPanel pull pending)
`resources/js/utils/dealDuration.js` `renewDaysForDeal`: duration_days → legacy name → previous
period → 30. Wired into both Renew openers; `RenewalService` overview rows now expose
`duration_days`/`activated_at`. Verified: 5 node cases, 2 Playwright fixture widths, 78 backend
tests/518 assertions.

## Renew records WordPress expiry (shipped to origin/main 18 Oct; cPanel pull pending)
`renewUnderLifecycleLock` now saves `WpSubscriptionExpiry::resolve()` of the `/activate` response
(fallback: local `now()+days`) and stores `duration_days` on the new deal. Test
`test_renewal_records_the_expiry_wordpress_reports_and_the_granted_days`; 79+7 related tests pass.

## Production correction (LIVE 18 Oct 11:15 UTC)
Ian's decisions: leave live unreplaced TZ 28018/28047 (WP really granted to 1 Nov); exclude 25 free
trials; correct the rest. Ops `crm-renew-default30-repair` (commit 28735cc, clean detached runner
`exotic-ops/var/renew-default30-2026-10-18/runner`): 19 deals → paid end (local activation day + plan
days, end of day), `duration_days` set; 28893 (Cherry) and 28076 active→`renewed` (each has a later
active sub). CRM rows only: no WordPress call, escort_expire/payments/amounts untouched.
Guards: no reconciler unprotection, no future renewal-campaign window, rows edited since renewal skipped.
Disposable-DB harness 17/17 (preview/fingerprint refusal/apply/audit/backup/idempotent/restore).
Apply run `20261018T111507Z-...-cd6f`, audits 597475–597493, backup
`/home/d9410/mkt-backups/crm-renew-default30-repair/20261018T111508Z-df23880d.json`
(sha aa1fd8c3…). Post-check: re-preview 0 to correct; Cherry WP 22 Oct/publish unchanged.
Restore: from the runner, preview `--restore=<backup>` then apply with its `--expected`.

## Open
Server-side days-vs-plan guard; Extend default 7; cPanel pull of the renew fixes (no migration);
signed-in UI check after pull; 28018/28047 lapse naturally 1 Nov.
