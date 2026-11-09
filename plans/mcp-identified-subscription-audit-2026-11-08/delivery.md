# Named subscription audits — delivery

Implementation approved by Ian after the 8 November plan review. Work continued on 9 November 2026 EAT. Ian authorised shipment on 9 November 2026. This release includes the verified source and matching frontend assets; production deployment and named grant assignment remain pending.

## Result

Management can query `exotic_subscription_audit_profiles` for every active/published cached profile, with complete pagination and retained cash-history findings. Explicit named-audit grants unlock advertiser/staff display names and usable public WordPress profile locators on profile/event/trace reads. A purpose is required; page-by-page confirmation is not. Existing anonymous grants retain their boundary.

Profile status, payment state and current identity have separate timestamps. The report includes no-deal profiles and old activations, distinguishes historic cash from funding a specific action, and identifies recorded trial authorisers without inventing an executor or administrator approver. A prior receipt allocation cannot be reused silently: profile history refines matching draft event rows before publishing the run, so the profile, trace and summary agree. Completed runs remain unchanged.

The profile report exposes unexplained wallet credits, conflicting/duplicate receipts and incomplete approval sources as unknowns. No-retained-cash is not lifetime nonpayment. CRM success remains separate from independent provider/statement verification. Missing direct WordPress/SQL history remains explicit.

## Package and isolation

Base CRM HEAD: `4e75147d`. Source was implemented in the existing main checkout. The isolated release uses committed HEAD plus the task-owned delta; the mixed route file contains only this release's `profiles` route addition. The original checkout retains all unrelated billing changes and its existing build manifest/assets.

The release package includes task-owned runtime files and the matching isolated frontend manifest/assets. It excludes test environments, dependencies, credentials, temporary scaffolding and unrelated work. The adjacent `release-manifest.json` records package SHA-256 values and expected prior committed hashes; `rollback-manifest.json` identifies new paths and the prior versions. The sibling rollback ZIP contains prior committed versions, not a substitute for backing up the actual production files before deployment.

Forward package: `output/mcp-identified-subscription-audit-2026-11-08/crm-mcp-named-audit-2026-11-09.zip`. Rollback package: the sibling `crm-mcp-named-audit-rollback-2026-11-09.zip`, including the prior build manifest and its referenced assets. SHA-256 values are in adjacent `package-hashes.json`. The forward package contains 23 runtime/document paths plus its manifest, verifier and deployment notes. Use the pushed main revision for cPanel deployment; the ZIPs retain matching source and rollback evidence.

## Deployment

Deploy this release by pulling `origin/main` through the normal cPanel Git workflow. A successful push does not establish production deployment. After extracting the package, `php verify-package.php` checks package integrity. `php verify-package.php --before-root="$HOME/crm.exotic-online.com"` checks affected production files against expected prior/target hashes without changing anything; stop on any mismatch. After deployment, `php verify-package.php --deployed-root="$HOME/crm.exotic-online.com"` verifies the actual targets. Before deploying, retain a private backup of the actual files and current build manifest/assets. Preserve any separately maintained live patch if a prior hash differs. Do not upload an entire isolated test directory.

After the matching source and frontend build have reached the production application root, run these complete commands there using production PHP 8.2:

```sh
php -v
php artisan migrate --force --path=database/migrations/2026_11_09_100000_create_subscription_audit_profiles_table.php
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan crm:subscription-audit-build --days=90
```

The original reporting migration must already exist. This new migration adds a derived profile table only. The build changes derived reporting rows, never customer subscriptions, financial source records, trial policy or WordPress. Its transaction publishes only a completed report; errors or budget overruns retain the prior completed snapshot. Do not disable the hourly refresh or delete source evidence to force success. cPanel does not build frontend assets; use the supplied matching build.

In CRM Settings → MCP, enable the profile tool if organisation configuration disables it. For each management connection, select the relevant audit tools and identified-client capability, or reconnect OAuth and explicitly select **Include named subscription audits**. Defaults stay anonymous. No grants or production configuration were changed by implementation. The current chat's existing connection does not establish Julian's access.

## Live acceptance

1. Confirm the actual server files/build match the package and the build command returns a completed run. Record its `run_ref`, `profile_as_of`, source availability and cache freshness.
2. Through the actual management connector, discover the profile tool and ask: “For an authorised subscription audit, list active profiles in Kenya with no successful cash payment found in retained CRM records. Include names, profile URLs, evidenced activation dates, activation staff and exception authorisation. State all coverage gaps.”
3. Read all pages on that same run, reconcile unique profile references and totals, then query `payment_history_unverified` and `current_action_payment_unverified` separately. Those are different questions from historic nonpayment.
4. Trace a paid action, an approved free action and an unsupported action. Compare each against original CRM records and actual WordPress status; the profile/event/summary financial findings must agree. Read any `payment_allocation_scope` caveat.
5. Confirm names/links require explicit named consent, permissions disappear on revocation/refresh, and the audit log records the named reads without generic log disclosure. Verify Julian's connection separately.

Live acceptance and independent provider/statement verification remain pending until those steps occur. Existing cached profile status is not a fresh direct WordPress observation.

## Rollback

Disable the new profile tool and named capabilities through the existing management controls. Restore the backed-up affected source files and matching previous frontend manifest/assets through the normal release process, then clear/cache config and clear routes. Keep the profile reporting table and completed/source evidence; the migration intentionally has no destructive down action. New class files are inert under the old code. Do not reverse customer access or alter payments. Confirm the original four anonymous event tools still work after rollback.

## Validation

- Final isolated PHP acceptance: **119 tests, 1,044 assertions passed**. Covers MCP grants/transports, profile paging/identity, safe audit-log failure, trial provenance, existing financial read-model cases, CRM adapters and legacy payment/STK compatibility.
- Disposable MySQL integration: **39 passed / 403 assertions**, one SQLite-specific audit-log fault-injection test skipped on MySQL and covered by SQLite acceptance. The disposable schema was dropped.
- Fleet-sized disposable MySQL load: **34,200 profiles / 18,000 paid actions / 88,200 source rows**; **102.071 seconds**, **270.5 MB** PHP peak. All profile/event/cash-history counts and exact cash totals reconciled; `sub_profile_scope` used with `ref` access and covering index. The schema was dropped. This is a synthetic benchmark, not production acceptance or a guarantee for a larger all-retained history.
- Build passed; browser Settings journey passed with synthetic/intercepted APIs, actual built UI, 390px/1440px checks and submitted named-grant payload. Mobile screenshot visually reviewed. Real provider, Julian-account and production browser acceptance remain pending.
- All 20 task PHP files pass syntax. **19 PHP files pass Pint**. The mixed route file has pre-existing formatting failures reproduced on committed HEAD; its one-token route change passes whitespace/syntax and preserves all unrelated route changes. No broad route reformat was performed.
- Three independent delivery-audit rounds ended **READY**, with no remaining blockers or should-fix findings. Earlier plan audit also ended READY after two rounds.
- Preservation check: unrelated tracked diffs and index preserved; mixed route original bytes preserved apart from the intended route pattern. No shared build manifest overwrite, production mutation, grant assignment, message send or WordPress change.

Earlier development failures, audit findings and cancelled/overlapping runs are retained in `output/mcp-identified-subscription-audit-2026-11-08/`; they are not counted as final verification.


## Shipment verification — 9 November

The isolated shipping rebuild has identical JavaScript bytes to the accepted build. Tailwind removed only the unused `my-2` utility; this changed the asset names. The rebuilt Settings browser journey is checked before shipment. The release commit stages its isolated route and build manifest blobs while preserving the unrelated working copies.
