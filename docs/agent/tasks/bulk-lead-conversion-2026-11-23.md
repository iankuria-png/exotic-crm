# Bulk lead-to-client conversion — 23 November 2026

- Status: SHIPPING AUTHORIZED / IN PROGRESS. Ian explicitly instructed “commit - and ship” after local completion. This covers the scoped commit/push, production preflight, exact release, cache refresh and verification. No advertiser conversion is being performed as a deployment probe.
- Owning repository: `/Users/ian/Projects/exotic-crm`, existing main at `19c67e2e`; preserve the extensive pre-existing dirty tree and staging. WP source untouched.
- Plan: [typed visual plan](../../../plans/bulk-lead-conversion-2026-11-23/plan.html), [source](../../../plans/bulk-lead-conversion-2026-11-23/plan.mdx). Two independent plan-audit rounds: NOT READY → READY; fixed stable per-lead provisioning identity, row-lock boundary, selected duplicate contacts and recovery/rollback contract. User's explicit completion instruction covers implementation; shipment held.

## Delivered behavior

The Leads selection toolbar has **Convert selected to clients**. A native modal reviews up to 100 explicit selected IDs, with per-lead market/contact/owner, ready/existing/attention classifications and optional exclusions/note. Existing matches remain unchanged and available for individual review or the existing Reconcile action. Ambiguous matches, repeated contacts in the selected market, missing/invalid contacts, archived leads, broken links and WP history never receive creation tokens.

Confirming processes eligible leads sequentially. New profiles are private, retain market and owner, and reuse existing WordPress provisioning, CRM transactions, timelines and audits. No plan activation, payment or customer message is added. The UI shows progress, stop-after-current, per-row outcomes, client links and explicit retry of failed/unstarted leads. Network/access loss stops the batch. Changed/expired reviews require a fresh review; incompatible partial attempts require individual recovery.

The server binds encrypted 30-minute review tokens to actor, lead, reviewed state and WordPress destination. It checks access again under a market file-cache lock (600-second lease, immediate busy refusal) and a CRM transaction/lead row lock. Both individual and bulk controllers share the lock and deterministic `lead-client-{id}` WordPress request identity. A WP-only commit can be replayed without a second profile; changing the payload after that commit fails closed.

## Evidence

- Production source-only FleetOps read: `20261123T104452Z-d4792-d9410-read-crm-lead-conversion-source-20261123-33ce`, exit 0. Current production Leads, LeadController, LeadConversionService and WpDirectProvisioningService match the starting local bytes. Routes/build differ. No app bootstrap, DB read/write or login in the check.
- Local evidence: `output/bulk-lead-conversion-2026-11-23/production-summary.json`; source baselines and captured production files remain in that folder, outside task commits.
- Backend: `/usr/local/opt/php@8.2/bin/php artisan test --filter=BulkLeadConversionTest` → **11 passed / 79 assertions** on in-memory SQLite plus an isolated temporary SQLite WordPress schema. Actual provisioning ledger exercised, including WP success → CRM failure → changed payload refusal → original payload retry; individual/bulk identity compatibility verified. No external requests allowed.
- PHP lint passes for the four affected PHP application files. Pint passes for the new controller/service/test and changed conversion service. `git diff --check` passes on changed existing files.
- Browser: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:5187 npm run test:browser -- bulk-lead-conversion.spec.js --workers=1`, using actual Leads, MainLayout and bulk dialog with intercepted APIs. **9 tests PASS (23.1s)**; see delivery report. Checks cover 360/390/1440px, selection, mixed results, safe retry, stop/resume, network loss, preview failure, no-ready state, changed reviews, keyboard containment/focus restoration and reduced motion.
- Build: `npm run build` in an isolated copied source tree with shared node_modules; final build PASS (9.36s), artifact/manifest/source inputs verified; see delivery report. Main checkout public/build was not rebuilt by this task. Pre-existing large-chunk and forecast CSS selector warnings remain outside scope.
- Test setup lesson: building into a Vite fixture root during browser tests reloads public/build/manifest.json and clears component state. Confirmed in Vite logs; serialize the build and browser checks. Early such runs are not accepted evidence. A separate stop/continue stress run passed 4/4.

## Owned files and preservation

Application changes: `app/Http/Controllers/CRM/BulkLeadConversionController.php` (new), `app/Services/BulkLeadConversionService.php` (new), `app/Services/LeadConversionService.php`, one conversion wrapper in `app/Http/Controllers/CRM/LeadController.php`, two lead routes in `routes/api.php`, scoped integration in `resources/js/pages/Leads.jsx`, new `resources/js/components/leads/BulkLeadConversionDialog.jsx` and `bulk-lead-conversion.css`.

Tests: `tests/Feature/BulkLeadConversionTest.php`, `tests/browser/bulk-lead-conversion.spec.js`, and three `tests/browser/fixtures/bulk-lead-conversion*` files. Plan/task/state records belong to this task. Existing Leads scraper navigation and unrelated route edits are preserved. Source patch must be generated against saved task-start baselines, not wholesale `git diff HEAD` on the dirty files.

Ops additions: exact read-only registry key `crm-lead-conversion-source-20261123` and `remote/php/crm-lead-conversion-source-20261123.php`; no other registry changes belong to this task.

## Delivery

[Evidence and review images](../../../output/bulk-lead-conversion-2026-11-23/delivery.md). Task-only patch spans 13 application/test files and passes `git apply --reverse --check` against the working tree. No debug instrumentation remains. Main public/build and existing staging were not changed by this task.

## Shipping and acceptance

Next: report local completion with desktop/mobile preview, then ship only after Ian's instruction. No commits or push so far; after any later commit follow the separate WP changelog workflow.

For shipping, inspect fresh remote/main and production preflight, preserve deployed payment/profile-lock overlays and existing scraper integration, apply only the task patch to the agreed release source and compile matching public/build assets there. The retained local verification build contains the dirty checkout's other source inputs and is **not a production deployment archive**. No new CRM migration or queue worker is needed for bulk conversion.

Verify each intended market's configured WordPress target and existing `exotic_crm_provisions` schema/readiness. Clear/rebuild route caches for the new endpoints through the approved delivery flow. MySQL row-lock concurrency and one controlled real market provisioning acceptance remain shipment checks; SQLite/browser fixtures do not prove those. Production feature behavior is not yet live-verified.

Rollback restores prior source/routes/build and caches while preserving created WordPress/CRM clients, timeline/audit records and provisioning identities. Do not automatically delete converted clients.

## Authorized shipment — 23 November 2026

Fresh production preflight `20261123T111115Z-d4792-d9410-read-crm-preflight-ba27`: clean main `52b1d688`, remote equal, complete manifest, flexible integration guard PASS. Source manifest read `e30b` confirms all 1,811 tracked app/routes/config/resources paths exactly match an origin/main archive; seven runtime-only paths remain preserved. Copied the byte-identical local FlexiblePaymentMethodsService only for isolated test support; it is excluded from the commit. No other runtime-only files are part of the release.

Scoped release is built from `52b1d688` plus the saved task-only patch, in an ordinary isolated source copy. No feature branch/worktree or checkout switch. Main remains at divergent duplicate EWMS `19c67e2e`; use a separate Git index and a commit parented to current origin/main, preserving the shared working tree and index. Only the feature's source/tests/docs and matching new frontend assets/manifest belong to the commit. Existing compiled assets remain retained for active sessions.

Release-source PHP tests PASS 11/79; build PASS. Native production preread `30b3` confirms Kenya's existing WordPress provisioning ledger columns and InnoDB leads table under READ ONLY transactions. Browser release-source verification and exact commit/deployment evidence follow below.

Deployment uses `crm-repair-release` with full expected/base SHAs verified before mutation (the older crm-pull script checks its SHA after pulling). Preview the exact diff, preserve all runtime-only files, then apply the pinned fast-forward and refresh caches through `crm-cache`; no migration, composer or financial/customer state change. Verify health, served assets, route middleware and a native READ ONLY bulk preview/invalid-token refusal. Normal live conversions remain staff-owned. Git base objects plus retained build assets preserve code rollback; never delete created clients during rollback.
