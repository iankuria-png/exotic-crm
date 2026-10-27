# Payment attribution and verified recovery — implementation checkpoint

27 October 2026. **Prevention implemented locally; two approved customer recoveries LIVE and verified.** Ian approved the plan and selected **full purchased duration from verified recovery**. Repository push, CRM migration/deployment and WordPress upload have not occurred. Automatic activation defaults to disabled on every market.

## What changed

- Durable per-payment attribution survives profile/deal cleanup. Capture follows both payment-to-deal and deal-to-payment links, including manual CRM activation. Original subjects and proven first-seen dates drive the same queue, export, cohort and CEO customer counts. Archived customers remain financially attributed without recreating their profiles. Conflicting subjects remain disputed.
- The separate **Paid customer recovery** workspace includes linked customers awaiting service, settlement review, and proof/legacy evidence review. Cases retain a market queue, optional staff owner, customer reference, five/fifteen-minute escalation, audited transitions and versioned, idempotent staff updates. Closing requires an existing verified benefit, compensating trial, refund audit or recorded resolution. It does not issue refunds or activate profiles.
- Signed WordPress checkout derives the member and owned profile from the WP session. The server pins purchase terms and preserves them across callbacks. Payer phone changes do not redirect that purchase. Owner-only recovery status uses session/nonce plus the existing signed service channel and works independently of wallet availability. Payment completion copy distinguishes receipt confirmation from recorded subscription benefit and discourages paying again while activation is pending.
- Shared versioned normalization covers local/international/00 forms, recognisable foreign international numbers and optional international trunk zero. Significant zeros are preserved for Côte d’Ivoire and Benin. Benin's current `+229 01…` format follows [ARCEP's numbering notice](https://web.arcep.bj/passage-des-numero-a-10-chiffre/); old/truncated forms require review. This is conservative normalization, not a universal phone-validity claim.
- Authenticated positive receipt observations survive missing provider-attempt records. Duplicated provider account/environment/transaction evidence cannot activate a second receipt. Underpayment, mismatched currency, changed receipt evidence, proofs, sandbox receipts, reversals and disputed subjects remain outside unattended fulfillment. Reviewed account-statement/import evidence needs an existing exact, authorized audit; proof approval alone does not establish cash.
- Delayed fulfillment has a durable CRM operation and pending deal before dispatch. The new WP endpoint persists an operation ID with an immutable exact expiry, checks profile identity and prior state, and commits the benefit and operation record together on transactional tables. Lost responses require operation lookup; they never trigger another duration extension. Existing restrictions and later/compensating benefits hold the case for review. Full duration is added from recovery or existing paid expiry, preserving already purchased time.
- KopoKopo status verification now recovers the original status URL from initiation data when the payment reference has subsequently become an M-Pesa receipt code. The provider service still checks the pinned provider origin before authentication.

## Historical repair tools

All previews are read-only unless the explicit apply option is supplied. Retain and review each printed hash before applying.

```sh
# Linked original subjects, bounded batches; continue with printed next_after.
php artisan crm:backfill-payment-attribution --after=0 --limit=100
php artisan crm:backfill-payment-attribution --after=0 --limit=100 --apply --expected=PRINTED_HASH

# Exact reconstructed original subjects for the current 16; never phone-only matching.
php artisan crm:backfill-payment-attribution --manifest=plans/payment-attribution-recovery-2026-10-27/current-16-backfill.json
# Repeat identical arguments with --apply --expected=PRINTED_HASH only after review.

# Source-verified cache phone repair. Holds missing/ambiguous source identities and collisions.
php artisan crm:reindex-payment-phones --platform=MARKET_ID --after=0 --limit=25
# Repeat identical arguments with --apply --expected=PRINTED_HASH only after review.

# Case maintenance; automatic service writes still require both explicit market gates.
php artisan crm:recover-paid-service --include-history --limit=50 --after=0
```

Both attribution and phone backfills write private, verified backups before mutation, audit every changed row, reject preview drift, and expose a default-preview `--restore=BACKUP_PATH`. Restore refuses newer evidence and never changes receipt cash or subscription time. The historical attribution command preserves original cohort dates as unknown when evidence does not prove them. The 438-row register remains review-only; a same-phone deletion lead is insufficient to apply attribution or service.

## Two approved recoveries — LIVE and independently verified

| Receipt | Verified cash | New paid deal | WP profile | Activated UTC | Service expiry |
|---|---|---|---|---|---|
| 34825 | KES675 | 33298 | 113776 | 27Oct07:52:34 | 3Nov23:59:59 Kenya time |
| 35456 | KES750 | 33300 | 113922 | 27Oct07:55:25 | 3Nov23:59:59 Kenya time |

Both receipts were reverified with KopoKopo as completed/accepted_exact before activation. Purchased duration was seven days of Basic service. CRM and native WordPress independently confirm one activated paid deal per receipt, published profiles, matching expiries, cleared payment holds, and unchanged amount/currency/reference/completion/provider/product fields. Recovery audits617363/617364 and private backup hashes are retained. The established WP activation path rounds to market-local end-of-day, so both received at least seven full days from recovery.

The first activation succeeded, but the initial verifier was too strict about exact7x24-hour expiry. It reported uncertainty because WordPress's established end-of-day expiry was later. No activation retry occurred. Separate receipt/deal/native/backup reconciliation proved the effect and confirmed the private dispatch marker. The second activation and its independent reconciliation succeeded. Both markers now record verified; no replay is permitted.

The initial shared-checkout guard was resolved with a clean **detached Ops release**, containing only the task's registered checks/scripts on the committed existing harness. Shared main/index and unrelated changes remained untouched; the release uses the same host trust, target configuration, locks and ledger. Exact release`e9dc05275435a11c6fc37aeaf71f096827c8c5cb`, preceding activation release`efc72e1ef6dc45a86da121edee70a8b435f00cf7`; both are local, unpushed. The guard was satisfied, not disabled.

Evidence: Ops`var/payment-attribution-2026-10-27/live-recovery-verified.json`. Activation runs`20261027T075230Z-d4792-d9410-apply-crm-paid-case-remedy-ad05` and`20261027T075520Z-d4792-d9410-apply-crm-paid-case-remedy-01ca`; final native run`20261027T075611Z-d4792-exotickenya-read-wp-payment-attribution-cases-read-481b`; reconciliation/marker confirmations`abbb` and`9ad0` at07:55:51/07:56:41UTC. These were data/service repairs through the already deployed activation path, not CRM/WP source deployments. Three other late-cache cases have later benefits and were not reactivated.

## Deployment and acceptance still required

1. Use the clean-HEAD integration in `output/payment-attribution-implementation-2026-10-27/release-review/`: `crm-clean-head.patch`, `clean-crm-source/` and matching `clean-build/`. This excludes pending wallet work and passes62tests/427assertions plus its production build in isolation. The worker uses the existing lifecycle lock; if the optional pending wallet guard is installed, it is respected. Shared-baseline task patches remain available for integration into the current dirty checkout. Do not mix source/build forms.
2. Deliver the additive CRM migration/source/build through the authorized release process and upload the reviewed WP plugin/theme changes separately. The complete `release-review/exotic-crm-sync-1.3.22-review.zip` is based on all55approved1.3.21runtime/documentation file hashes, adds the two operation/owner classes, and passes3tests/17assertions. Four existing ignored includes made a Git-HEAD-only plugin incomplete; the approved manifest resolved provenance without copying a live site. Review manifest `wp-release-manifest.json` pins57candidatefiles and the ZIP. No release permission is inferred from implementation approval. CRM/WP application files remain uncommitted/unpushed; only the exact detached Ops task releases were committed locally.
3. Start with attribution and operational visibility. Run linked-subject backfills, then the reviewed current-16 manifest, and verify native amounts and queue/card/export agreement. Discover the wider historical cases in bounded batches; unresolved cases retain ownership.
4. **DONE:** Both approved seven-day remedies are live and independently verified; retain private backups/audits and never replay them.
5. Verify target-market WP endpoint version/storage and **all subscription writers**, including parent/admin/payment/expiry paths. The new locks cover the CRM activation/lifecycle endpoints; full writer parity is not claimed. Keep both `PAYMENT_RECOVERY_AUTOMATIC_PLATFORMS` and `PAYMENT_RECOVERY_VERIFIED_OPERATION_PLATFORMS` empty until this passes, along with a staging callback-before-cache/duplicate/timeout journey. VVIP placement and mixed-tier recovery remain operator review.
6. Review and deploy separate exact configuration manifests for Tunisia `261 → 216` and Guinea Bissau `235 → 245`; re-fetch affected source phones and preview collisions. Those settings are unchanged. Cross-country properties need explicit country context for local-format numbers.

A lower unattributed card count is not closeout. Close the incident only after production attribution readbacks, the completed service verification for the two recovered customers, and a proven disposition or owned case for the remaining backlog.

## Verification evidence

PHP 8.2, SQLite feature tests, a separate disposable Local MySQL database, WordPress PHPUnit and browser fixtures were used. Evidence files live in `output/payment-attribution-implementation-2026-10-27/`; `verification.md` records final results. No real-money checkout or staging-provider journey was run. Clean-source62tests/427assertions and its separate frontend build also pass without the pending wallet guard. Browser fixtures exercise the actual recovery component with mocked API responses, rather than claiming end-to-end production coverage. Frontend build is isolated from the unrelated modified rebates stylesheet; existing CSS/chunk warnings remain.
