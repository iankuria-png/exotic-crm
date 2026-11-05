# Daily reconciliation and trial approvals — delivery

5 November 2026. Release validated locally for the authorized main commit/push. A push is not a production deployment. Ian deploys the CRM through cPanel; no WordPress upload, npm command or dependency update is needed on the server.

## Included behavior

- Payments → Reconciliations: daily receipts, bundles, filters, native-currency totals, bulk preview/approve, correction requests, flags, denial, history, scoped private evidence and CSV export. Reporting day is Africa/Nairobi. Reconciliation denial preserves the payment and active service.
- Separate maker/checker review, with a recorded reason for admin self-approval. Administrators grant named staff review and export access per market through Review access; export is a separate grant.
- New discretionary trials require admin approval by default. An admin can explicitly enable the existing global PIN in a selected market, recording a reason and review date. Policies do not switch automatically with revenue. Existing active trials stay active.
- Activation, extension, renewal, field trials, optional subsidiary trials and SEO Boost use the same durable authorization controls. Uncertain outbound results are held for readback; they are not automatically resent.

## cPanel deployment

Keep the normal private backup and preserve any existing server edits. Run this from the CRM terminal; a failed pull must be resolved without a hard reset or overwriting local server changes. The final shipped commit is recorded in the canonical task and delivery message.

```bash
cd /home/d9410/crm.exotic-online.com
git status --short
git pull --ff-only origin main
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --force
/opt/cpanel/ea-php82/root/usr/bin/php artisan optimize:clear
/opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate:status
```

Apply migration `2026_11_05_140000_create_daily_review_and_trial_approval_tables`. It adds the review ledger, correction/evidence/access tables and market trial authorization/operation tables. It defaults every existing market to `admin_only` and records the launch day. No seeders, new workers or scheduler entries are required. The included frontend manifest and hashed assets must deploy with the source. Keep `storage/app` private and writable for uploaded proofs.

As an admin, open `/payments/reconciliations`, assign the actual Head of sales/accounting reviewers under Review access, then grant export only where needed. Under Settings select each intended PIN-exception market, confirm that the global free-trial PIN is configured, choose Admin or configured PIN, and save the reason and review date. No staff assignments or exception markets were guessed in the release.

## Acceptance after deployment

1. Confirm migration status, the new navigation item, daily manual receipts and native-currency totals. Inspect a receipt/bundle with the intended reviewer; check every allocation and proof.
2. Exercise approval and a correction round trip using an authorized test record. Confirm self-approval requires an admin reason, revoking a grant blocks access, and a denied reconciliation leaves the customer service active. Do not make production financial corrections merely to test the UI.
3. Submit an authorized trial request in an admin-only market: no service change occurs while pending. Approve it and verify the WordPress effect plus the CRM audit/zero-cash trial record. Check PIN access only in a market deliberately configured for it. Existing trials must remain unchanged.
4. Check the affected log window for errors. Record actual deployment commit and observed acceptance before marking the release live. Browser fixtures and local integration tests do not establish production acceptance.

## Validation and known baseline issue

Tests ran against a plain source archive of base `b2800a64` plus only this task's delta; matching frontend assets were built from that source. No branch or worktree was created. Unrelated billing/collection source and local assets were preserved.

- PHP 8.2 syntax: 31 changed PHP files, no failures. Scoped Pint check passed.
- MySQL 8.0.35: 27 tests / 253 assertions passed in a private disposable local database, which was dropped. Includes real two-process review/PIN/activation races.
- Broader backend: 76 passed, 1 failed, 625 assertions. The manual-bundle compensation test expects HTTP 422 and receives 500. The exact failure was reproduced on unchanged base `b2800a64`; it remains outside this feature's change.
- Chromium: 5 actual React-shell journeys passed, including 390/360 px correction flows, reduced motion, focus recovery, review/export and trial controls. API/provider data were synthetic and intercepted. Screenshots were visually reviewed; the fixture's brand image is absent from Vite's static test origin.
- Production build passed. Existing forecast CSS/reduced-motion and bundle-size warnings remain.

Private logs and screenshots are retained in local `output/daily-reconciliation-2026-11-05/`. `release-manifest.json` pins the tested source and manifest/assets; no secrets or production customer data are included.

## Recovery

The migration deliberately refuses destructive down/rollback so audit records cannot be erased. Keep the additive tables and private evidence. If the review workspace must be held, `DAILY_RECONCILIATION_ENABLED=false` fails review endpoints closed. `TRIAL_APPROVAL_WORKSPACE_ENABLED=false` holds discretionary trial requests; it does not restore uncontrolled activation. Rebuild the config cache after an environment change. Prefer a forward correction; do not roll back to the old trial code after trials have entered this ledger without a separate reviewed recovery plan.
