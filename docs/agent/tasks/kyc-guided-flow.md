# Guided KYC — implemented locally

Updated 27 September 2026. Ian requested implementation of the WordPress plan `plans/kyc-guided-flow-ai-review-2026-09-27/plan.mdx` across WordPress and CRM. Browser QA is now Ian's; no further automated browser passes requested.

## Delivered

- WordPress plugin 0.2.0: guided document choice, camera capture, three timed selfie poses, upload fallback, photo review, consent, resumable photo replacement, retry/offline handling and status polling. Responsive charcoal/crimson interface uses the existing workspace typography. Passport skips the back photo.
- CRM: atomic complete-set submission, versioned documents, private AI observations, deterministic policy, five configurable modes, per-market controls, global pause, daily budget reservations, QA sampling and paced backlog. Review drawer places findings beside documents; filters, feedback, private viewer and keyboard handling included. Assisted uploads are collapsed until needed.
- Authenticated CRM status pushes now update WordPress badge metadata. KYC ordering no longer excludes profiles lacking verified metadata. Applicant routes check actual profile authorship.
- Two additive migrations; existing selfie kind uses sequence 0/1/2. Existing public source remains `kyc`; AI provenance is recorded separately. Existing reviewer service permissions (admin/sub-admin/sales) preserved.
- Shadow findings never populate visible identity fields. Old submissions need fresh consent before AI processing. Refusals and malformed answers go to a person without trying another vendor to evade refusal.

## Verification

- PHP 8.2: `php artisan test tests/Feature/Kyc tests/Unit/Kyc`: **41 passed, 236 assertions** (70 seconds).
- WordPress: `php wp-content/plugins/exotic-kyc/tests/guided-contracts.php`: **11 passed**. All plugin PHP syntax checks and guided JS syntax pass.
- CRM production build passed. Existing unrelated forecast CSS selector and large-bundle warnings remain. Changed PHP formatted with Pint.
- Local browser journeys passed: passport upload fallback, consent validation, offline retry, specific-photo retake preserving selfie; camera national-ID front/back plus three poses; CRM request-info, filters, private document viewing, nested Escape, feedback; mobile overflow checks.
- Synthetic observations through the real job/policy/fanout produced approved CRM status, WordPress approved cache and `verified=1`. The fake camera produced duplicate frames and was correctly held for human review; a distinct synthetic image fixture then exercised approval. This is plumbing verification, **not live model accuracy or biometric calibration**.
- Screenshots/scripts: WP `output/playwright/kyc-guided-2026-09-27/` (ignored). `crm-findings-desktop.png`, `crm-queue-mobile.png`, `wp-camera-mobile.png`, `wp-verified-mobile.png` are useful QA references.
- Plan-audit subagent failed due usage limit; root reviewed contracts. No independent auditor approval claimed.

## Local environment / remaining delivery

- Local enabled for platform 12 with encrypted DB storage. AI remains **Off**; no OpenRouter key is configured locally. No live provider calls or production deployment performed.
- Synthetic draft WP profile 97173 / account 34094 and CRM client 2511 / subject 1 remain for local inspection. Local reviewer email `kyc-review-local@example.invalid`. Session/token files are private under `/private/tmp/kyc-*`, not committed. Synthetic findings are labelled `local/synthetic-fixture`. Never use these records for calibration.
- Plugin activation also ran its existing local site announcement, creating local unverified subjects for existing profiles. No real advertiser documents were used or sent to a model.
- Next: Ian browser QA, then deployment using the delivery note. Configure provider and run consented shadow calibration before relying on automatic decisions. Keep legacy settings and production credentials unchanged until deployment.
- No pushes. WP plugin upload and CRM pull/migration are separate deployment steps. Unrelated working-tree changes, including theme functions.php and CRM contact-unlock work, remain untouched.
