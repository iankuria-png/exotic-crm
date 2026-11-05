# Daily reconciliation and market free-trial approval

5 November 2026 (EAT). Status: implementation complete and pushed to origin/main as 7e63eea9 (remote exact SHA verified). Deployment remains Ian’s manual cPanel pull/migration. No production business mutation has been performed.

## Request and confirmed decisions

Ian requested a daily CRM payments reconciliation workspace for Head of sales/admin/accounting: multi-select approval, sales correction requests, flags and denial, with polished UI/UX. He then added per-market free-trial controls: admin approval or the configured PIN in selected markets.

Confirmed: denial preserves payment and active service; maker/checker is required except an admin may self-approve. Plan records a reason for the admin exception. Existing active trials remain active; new discretionary CRM trials need authorization before activation. PIN-enabled markets and named reviewer/export grants remain initial rollout choices.

## Artifacts

- [Visual plan](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/plan.html) and [typed source](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/plan.mdx).
- [Independent audit](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/audit.md).
- [Source/evidence manifest](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/source-evidence.json).

## Findings and boundaries

Local HEAD b2800a64; extensive unrelated dirty billing/collection work preserved. Existing Fraud audit reconciles provider files; manual reject can fail/deactivate service, so new daily decisions require an isolated review-only domain. Bundles represent one receipt with multiple allocations. Current CRM has no Head of sales/accounting roles; use explicit scoped grants for existing staff.

Trial entry points include Deal activation/extension/renewal, field-sales trial activation, subsidiary execution/retry and SEO Boost batches. Existing global PIN has no per-market mode. Paid recovery explicitly excludes noncash, so the plan specifies a separate durable trial execution service, one outbound relative mutation per operation, ambiguous outcomes held, and local-only finalization. No paid recovery policy loosening or WordPress deployment proposed. PIN grants bind a non-secret rotation generation.

Fresh registered Ops SELECT-only read, no Laravel boot/provider calls: `20261105T102213Z-d4792-d9410-read-crm-mcp-audit-coverage-read-1a42`, exit 0, 5 November 13:22 EAT. Reconciliation tables present; zero batches created in the assessed 7 August–4 November UTC window. This is bounded aggregate evidence, not a whole-fleet or bank-settlement verification. Raw run output stays in Ops private var; source hashes are retained locally.

## Planning validation (historical; implementation authorized below)

Typed renderer passes; 8 static wireframes and no external HTML load dependencies. Browser file-URL access was blocked; no preview server or visual browser pass claimed. No application tests were run for a planning-only task.

Round 1 NOT READY: fixed SEO Boost coverage, concrete trial adapter and PIN rotation binding. Round 2 found the shared-settings/PIN race, now addressed; round 3 independently verified the revised source contracts and returned READY with no remaining BLOCKER/SHOULD-FIX. Hand Ian the plan for review. Implementation has not started; initial PIN markets and reviewer/export grants still need selection before production configuration. No production payment, trial, PIN or policy changed.


## Authorized implementation — 5 November checkpoint

Ian authorized “implement end to end and ship.” Ship skill read: scoped main commit/push with matching locally built assets; cPanel deployment remains manual. No initial named reviewer or PIN-market assignments inferred: admin review access and admin-only trial policy are defaults, with UI controls for explicit grants/exceptions.

Implementation underway on main b2800a64. Protected before-task tracked/index patches plus 3,716 pre-existing modified/untracked path hashes/copies are in ignored `output/daily-reconciliation-2026-11-05/before/`; initial index empty. Preserve those files and avoid shipping unrelated collection/billing work. New code: additive migration/models, daily review query/ledger/permission service and controller/routes; scoped proof/correction/export/grants; trial policy/request service, durable single-send activation adapter, rotation-safe global PIN writes; old trial URLs intercepted by TrialApprovalMiddleware; field/subsidiary/SEO Boost entry points guarded; isolated React workspace and shared trial authorization/policy controls. No source commit/push/deployment yet.

First checks: DailyPaymentReviewTest 8/83 PASS; TrialAuthorizationTest initially 7/48 PASS. Existing paid FieldSales, LegacyPaymentCompatibility, LegacyStkRouting and provider reconciliation tests pass in broader run; known manual-bundle compensation 500-vs-422 persists. Existing SEO/subsidiary tests being updated for intended admin-only/historical-intent hold behavior, with new approved SEO/subsidiary end-to-end cases. Browser synthetic actual React shell: 390/360px correction + reduced-motion and stale-preview/focus initially pass. Fixed late drawer reopening and exact market label after first two browser failures; rerun evidence in browser-tests-r2.txt.

Vite source server owned session 22347 on 127.0.0.1:5184; intercepted browser fixture spec `tests/browser/daily-reconciliation.spec.js` only contacts local origin. Stop that owned server and restore/remove its public/hot file at completion. Isolated first local build under output passes; existing forecast CSS and large-bundle warnings. Existing public/build untouched so far.

Next: finish integration/regression/browser checks and inspect screenshots; add meaningful source-change/export/proof/config-race coverage, real disposable MySQL checks; self-review trial state/eligibility/concurrency; construct a plain release directory from HEAD plus task delta (no worktree/branch), validate it and build matching release assets, selectively stage task delta/assets, commit/push origin main, follow separate WP changelog commit, update state with exact shipped and deployment status. Use PHP8.2. Preserve unrelated working/staged changes. New runtime migration defaults admin-only trial policy; provide complete cPanel instructions. This checkpoint is not a completion claim.


## Release acceptance — 5 November

Implementation complete. Exact plain archived release from HEAD b2800a64 plus owned delta passed PHP syntax (31), MySQL 8.0.35 (27 tests /253 assertions, including 3 real two-process races), 5 Chromium actual-shell journeys at desktop/390/360px and production build. Broader backend:76 pass/1 fail/625 assertions; existing ManualPaymentBundleCommitTest compensation HTTP500-versus422 reproduced on unchanged base in a separate archive. No new feature check remains failing.

Reviewed desktop/mobile screenshots. Includes review history for reclassified records without inflating cash totals, assignment-revocation proof access, optimistic grant versions, canonical financial correction requirement, stale-source/idempotency/scope protections, rotation-safe PIN config writes, durable SEO batch and subsidiary identity claims, verified trial effect before zero-cash finalization. No real provider call, trial, financial correction, named grant or policy/PIN assignment was made by tests.

[Delivery and cPanel instructions](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/delivery.md). [Tested release manifest](../../../plans/daily-reconciliation-and-trial-controls-2026-11-05/release-manifest.json). Local evidence:release-backend.txt,release-browser.txt,release-build.txt,release-lint.json,baseline-bundle.txt and release/output/daily-reconciliation-2026-11-05/mysql-tests-final.txt. Browser APIs synthetic/intercepted; local tests do not prove live acceptance. Unrelated dirty source/assets are excluded through before-to-now task patches and an isolated build. Next: scoped main commit/push, separate WP changelog commit, record exact SHAs; Ian cPanel pull/migration/config cache, explicit grants/PIN exceptions, live acceptance. Do not mark live from push.


## Shipment

Runtime/source/assets commit `7e63eea9212ba50e6d986e1e6dca8b847ca060a1` pushed to origin/main; git ls-remote returned the exact same SHA. All57committed paths match the isolated tested package; all52pinned runtime/test/asset files match release-manifest.json. Old committed hashed assets retained for in-flight pages. Protected unrelated files have zero before-task hash differences; mixed-file unrelated hunks and the prior local manifest remain unstaged. Owned Vite server stopped and temporary hot files removed. No WP runtime change/push or production deployment/business mutation.

The production next action is Ian’s cPanel pull, workflow migration, config clear/cache, explicit reviewer/export grants/PIN-exception configuration and live acceptance per delivery.md. Local checks do not establish deployment. The separate WP changelog records this release as pushed, waiting for deployment.
