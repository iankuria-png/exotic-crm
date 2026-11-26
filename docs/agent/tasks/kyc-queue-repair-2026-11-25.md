# KYC queue — diagnosis and local repair, 25 November 2026

Status: **RELEASE PREPARED / VERIFIED**, shipping authorized on 26 November; production deployment pending. Ian requested fleet-ops diagnosis of slow initial load, apparently stopped AI, a score grading the AI evaluation, and client navigation. He then explicitly selected **“Diagnose and implement locally.”** The original diagnosis/implementation performed no deployment, settings change, provider call or historical reprocessing. Ian extended authorization to commit and ship on 26 November.

## Confirmed causes and limits

1. **Valid AI findings were hidden by decision-version changes.** `KycSubjectService` increments `review_version` for approvals, rejections and retake requests. `KycAiReviewService::findings()` treated every mismatch as changed photos. All five open retakes (subjects 27780, 27820, 28094, 27764, 28566) had identical reviewed/submitted document IDs and a one-version decision increment. They were therefore incorrectly labelled “Older photos · result not used.” Phelix's latest check completed **24 November, 19:05 EAT**, correctly requesting a clearer photo.
2. **AI had not stopped globally.** Production contained **91 completed reviews** (84 primary, seven fallback), eight `incomplete_response` failures, three historical timeout failures and six cancellations. No queued/running KYC reviews, queued KYC jobs or recent KYC error-log groups were observed; worker/provider configuration was present. Eight incomplete responses are real failures, but their original finish reason/reasoning usage was not recorded, so their underlying cause cannot be reconstructed honestly. A 2,000-token combined output budget is a plausible contributor, not a proven historical cause. [OpenRouter's official reasoning-token reference](https://github.com/OpenRouterTeam/docs/blob/main/guides/best-practices/reasoning-tokens.mdx) explains why reasoning can exhaust this budget.
3. **Initial load performs unrelated work and displays a false state.** `Kyc.jsx` renders “no markets enabled” before settings finish. It also calls the full `/api/platforms` billing directory for market names. Native server probes measured queue **309 ms / 13 SQL queries**, full settings **57 ms / three queries** (blob-size scan 48 ms), and platform decoration **263 ms / 332 queries** across 55 platforms. Decoration timing excludes audit-log insertion and response serialization. These probes did not reproduce a multi-second database stall or measure browser cold-network latency. The global SPA bundle remains about 6 MB uncompressed / 1.48 MB gzip; broad application splitting is outside this fix.
4. **Navigation was absent.** Desktop/mobile client names were paragraphs and the evidence drawer had no client link. The existing `/clients/:id` route already supported navigation.
5. **The requested score did not exist.** Only face-match confidence was recorded. Historical approvals such as Brenda's legitimately have no AI result; neither results nor self-assessment scores should be invented for them.

## Implemented locally

- Findings retain completed observations after a decision when the reviewed submission and surviving current capture/type match. Later uploads, deletion/replacement, changed captures, `in_review` races and expired/re-requested cases remain invalidated. Older retained capture photos (e.g. national-ID back after passport switch) are handled explicitly. Worker execution/version guards are unchanged.
- Queue eager-loads only document metadata once and strips it before serialization; it exposes derived `decision_changed` / `evaluation_confidence` without raw observations or shadow scores.
- `GET /api/crm/kyc/settings?summary=1` returns enabled IDs and market-scoped ID/name choices. Queue no longer calls `/api/platforms`, calculates stored blob sizes or probes S3 during startup. Pending/error/retry states keep available submissions visible and never falsely claim markets are disabled. Full settings response is preserved.
- New strict-schema field `evaluation_confidence`: nullable number 0–1, stored in the existing observations JSON. Missing legacy values normalize to null. New attempts use prompt version `kyc-v2-confidence`. The findings panel shows **AI self-confidence /100**, a restrained meter and an explicit uncalibrated model-self-assessment label; face-match confidence remains separate. Queue rows show a compact score when available. Zero is valid; invalid/stale/shadow/unfinished scores are suppressed. This field never changes approval policy.
- Provider output headroom raised 2,000→4,000 tokens. Operational events record only allowlisted finish reason, token totals and configured limit. `length` becomes `provider_output_truncated`; content filtering is handled as a refusal. Incomplete/refused/malformed responses still do not trigger cross-provider fallback or identity decisions. No live model call or accuracy calibration was performed.
- Client names on desktop/mobile and **Open client ↗** in the drawer open the correct client page in a separate tab, preserving queue position and review context. Keyboard focus and wrapping drawer actions are supported.

[Audited visual plan](../../../plans/kyc-queue-repair-2026-11-25/plan.html), with MDX source alongside it. Two audit rounds: first corrected retained-capture handling and explicit score/privacy/version contracts; second verdict READY.

## Production evidence — read-only

Only registered `MKT_AGENT=codex bin/mkt read d4792/d9410` checks were used. Observed runtime HEAD `d06df89b9ffe56c6bf27d8d0d409ade13a10ccb0`. Ops owns the new `crm-kyc-diagnosis-read-20261125` check and bounded diagnostic script. Raw captures stay in Ops `var/runs`; no document contents, provider responses, tokens or environment secrets were retrieved.

- Log audit: `20261125T184704Z-d4792-d9410-read-crm-log-audit-c3d2`.
- State/source: `20261125T184829Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-acb9`.
- Queue baseline: `20261125T185010Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-37d8`.
- Settings baseline: `20261125T184842Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-b015`.
- Exact case metadata: `20261125T185010Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-b632`.
- Platform decoration: `20261125T185129Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-d3fa`.
- Candidate comparison: `20261125T190008Z-d4792-d9410-read-crm-kyc-diagnosis-read-20261125-084f`.

The candidate was evaluated **only in the diagnostic process's memory**, inside a database-enforced READ ONLY transaction; no application files/settings/customer data were changed. It returned the **same 150 submissions and all operational counts**, corrected all five retakes to `stale=false`, and retained null historical self-confidence. Its summary used **two queries / 12 ms**; queue used **14 queries / 254 ms**. The extra queue query batches document metadata; timings are single observations, not production-browser performance guarantees.

Initial diagnostic request-binding failures were fixed in the harness before valid measurements; failed run artifacts remain in the ledger. They were not user-route production failures.

## Verification and delivery

- PHP8.2 full KYC feature/unit run: **53 passed, one new provider mock test failed** because repeated `Http::fake` calls retained the first simulated response. Fixed the fixture without changing application code. Corrected provider tests plus the additional truncation/no-fallback regression: **3 passed / 21 assertions**. Together these verify **55 unique backend cases**; the full suite was not rerun after this test-only correction. Original and retest logs are retained.
- Frontend presentation/render checks: **12 passed**. The render harness disables unnecessary dependency scanning to avoid teardown-only Vite scan errors.
- Real Chromium fixture journeys through the compiled SPA: **23 passed**, at 1440×1000 and 390×844. Covered delayed settings, failure/retry, queue visibility, separate score labels, historical result absence, correct desktop/mobile/drawer client destinations, keyboard new-tab navigation, original queue retention, Escape, no horizontal overflow, zero JavaScript page errors. Synthetic API fixtures only; not a live authenticated-browser acceptance claim.
- Visually inspected desktop/mobile findings screenshots. [Desktop](../../../output/kyc-diagnosis-2026-11-25/findings-desktop.png), [mobile](../../../output/kyc-diagnosis-2026-11-25/findings-mobile.png), queue screenshots alongside.
- Scoped PHP lint, Pint, diff whitespace and Ops JSON validation pass.
- Production Vite build passed in **30.55 s** from a private isolated snapshot of current frontend resources using existing dependencies. Existing forecast selector and large-bundle warnings remain. The first in-tree build was terminated after scanning unrelated accumulated artifacts; its log is retained. Matching build copied to `output/kyc-diagnosis-2026-11-25/build/`.
- Original canonical `public/build/` (87 files) and Git index fingerprints are unchanged. Existing dirty main checkout and unrelated work are preserved. No migration, WP application edit, commit, push, deployment, log clearing or settings change.

Next: review local UI/source; when delivery is requested, prepare a scoped release against current production/remote source and matching assets using the shipping workflow. The isolated full build includes the current dirty frontend snapshot and is validation evidence, not an authorized clean production release. After deployment, verify authenticated `/kyc` and actual client links/assets, observe a newly consented AI check for a real self-score and finish telemetry, and measure the user's cold browser load. Existing cases will continue to show their recorded face confidence and “Not recorded” for the new score.

## Shipping authorization — 26 November 2026

Ian requested “commit and ship.” Release prepared against origin/main a654cda25428d656b97f2ed6308293d8079c7393 with only the 18 KYC source/test paths, this task/plan and matching frontend manifest/assets. The shared main checkout, real Git index and canonical build are preserved. No migration or configuration change is required. Production deployment remains the documented cPanel pull step; no live apply was performed by this shipping task.

Release verification on the exact a654cda2 export: **55 PHP tests /353 assertions**, **12 frontend checks**, **23 compiled-SPA browser checks**, scoped Pint/PHP lint and **npm run build (24.99 s)** PASS. Matching assets: app-DyTkLxg5.js / app-KD-GLiCq.css. No production apply performed.
