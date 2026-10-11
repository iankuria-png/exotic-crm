# CRM error repairs and AI KYC explanation

## Current checkpoint — 11 October 2026, 05:23 EAT

Ian authorized end-to-end implementation, testing and production rollout, and separately selected SSH deployment for the two WordPress files. Use only `exotic-ops/bin/mkt` for servers. Core repairs and backend followups are LIVE; remaining access-dependent and historical issues are listed below. Earlier notes are dated history, not current deployment or missing-decision claims.

Ian selected the existing support mailbox and confirmed receipt of the one harmless test. Eligible KYC applicants may be automatically approved, retaining consent, evidence/version, eligibility and budget guards. Every case has a short explanation/next step and expandable “How AI reviews this KYC”. Trial29353 compensated payment32953: preserve expiry and grant no extra benefits. Gambia uses Exotic Push Engine; Africa push credentials are explicitly deferred because Ian lacks access.

### Live results

- CRM `84658185b5d05c5ca611a1babcd9cef8bb8f3e81` includes core `a675eaa2`, phone/owner sync `55f78a30`, and FX followup `84658185`. Exact fast-forward release, cache rebuild and health200 verified. Public login HTML references the approved JS/CSS; both assets are200 and match exact release SHA256. Only the lifecycle enum migration was applied; no financial/dependency migrations.
- Payment32953 KES750 is linked to client3343094 and existing paid deal29353 in both directions; zero placeholder32954 detached. Expiry remains2026-10-16 20:59:59UTC, with no extra benefits, activation, commissions or wallet changes. The client cache arrived13seconds after completion; the old separate matcher searched phone only. Shared unique same-market WP identity and bounded post-sync/scheduled association recovery now prevent that gap while retaining fulfillment review.
- Support SMTP uses the encrypted existing support@exotic-africa.com configuration and the same runtime for credentials and tests. SMTP accepted one harmless mail to staff10; Ian confirmed receipt. Audit-only followup sent no second mail.
- KYC reviews10/11 completed through the guarded scheduled worker, queue0 and reserved budget0. Kelly6020: model_declined → human/flagged. EOO8553: document_checks → human/flagged. No approval was forced; five existing staff approvals preserved, obsolete attempts cancelled. Total actual costUSD0.013593. Automatic approval remains enabled for eligible future cases.
- Staff daily stats7–10Oct restored338 rows:87/89/86/76. Null/unscoped audits remain historical records; rollups validate actual staff/market FK identities. Failed first attempt rolled back; corrected audit API used for successful recovery.
- Ghana/Nigeria only: two-file additive WordPress patch from local `c257dee4e` deployed over SSH. Fresh capability/route and anonymous-denial checks passed. Ten affected profiles received their latest manual-payment messages; independent before/after hashes prove financial and unrelated metadata unchanged. Current pending state retained; obsolete rejected/pending notices cleared. Other markets were not patched.
- Legacy phone handling uses the market prefix (including Ghana/Nigeria). Three failed Kenya Kopo checkout numbers had invalid11/13/13-digit lengths, while provider credentials were present; checkout now rejects them before creating payment or provider request. No real charge/SMS was retried.
- Concurrent private-content owner cache insertion recovers only the same-market profile, retaining owner validation. Zambia12/post22287 currently exists; genuine unrelated failures remain503.
- FX failure was a UTC boundary problem: reporting requested11Oct historical data at10Oct23:53/54UTC. Current/future UTC dates now use latest, with the provider’s real as-of date preserved. Current latest GET200, positive KES→USD, as-of10Oct23:59:59UTC.
- Gambia50 now selects Exotic Push Engine, matching its existing website plugin. Changed only active_provider plus actor/timestamp; all credentials, global/other-market settings unchanged, audited and privately backed up. CRM site ID/site key/API token are absent, so connection is still pending. No notification sent.

### Remaining work and practical limits

1. Africa30 push authentication: explicitly deferred by Ian; reconnect from the existing push account when access returns. Gambia50 connection needs its own site ID/site key/API token saved securely in CRM Settings; clarification is pending. Never borrow another market’s credentials or paste secrets in chat.
2. External legacy SendGrid key rotation remains outstanding after the diagnostic exposure. Diagnostic redaction was corrected; never repeat the key.
3. Historical Africa profile IDs69077/69079 are not current advertiser profiles:69077 absent,69079 an attachment dated7Oct; no cached clients remain. Live SSH and CRM direct DB both identify exoticafrica_dbase_v3/exc_ and the same siteurl/state, so current DB routing is not the mismatch. Historical deletion/restore/ID reuse remains unproved. Do not replace attachment69079 or recreate customers without identifying the original records.
4. South Sudan15/post4078 now returns the correct profile; missing containment command is now installed. Invalid-token probes confirm the frontend client-session handler for1/3/5/7, including7’s canonical redirect; admin-post is redirected, existing frontend fallback works. No real session token/customer login was created or consumed.
5. Image proxy timeouts are intermittent historic observations; existing bounded failure handling retained, no ongoing outage established. Paid-entitlement and media-validation guards are protective and remain enabled.
6. Latest multichannel audit still contains WordPress route/auth failures, mostly concurrent fleet-story diagnostic requests, plus historical missing profiles. Those per-market plugin/auth gaps are tracked separately; this repair is not a fleet-wide plugin upgrade or a claim that every ERROR is closed. Historic38 initiated receipts were not marked paid/failed or rerouted without provider proof. Pinned original context, sandbox separation and age-based retry spacing are live; long-term retry behavior needs observation.
7. Ian owns signed-in browser QA. Backend/presenter/React render checks and public release assets were verified; no automated production browser QA claim.

### Verification and durable evidence

Scoped tests cover identity/association safety, provider context/retries, lifecycle enum/transaction rollback, SMTP runtime, KYC state/process rendering, phone validation, owner races and FX UTC boundaries.10 frontend state/React render checks passed; real MySQL reconciliation5tests35assertions, lifecycle4checks, WP route contract12checks, payment fulfillment13checks passed. Followups18tests80assertions,9tests37assertions,FX6tests35assertions passed. This is scoped verification, not a claim of a clean complete repository suite. PHP syntax/Pint scoped checks and isolated frontend build passed.

Original private production dump1,217,350,844bytes/258tables was successfully restored in network-isolated MariaDB11.4.9. Proof: `output/crm-log-audit-2026-10-11/backup-restore-proof.json`; original SHA0f0e976e9bd63a6cdb9b149a592b3e99269581e4689fff7ce389e21d67f33f77. Scratch container removed after proof; private original dump retained. All production repairs have exact previews, private backups and audited/independent verification; no customer credentials, charges or pushes sent.

Ops ledger stores full run IDs and script fingerprints; raw records are private `var/runs/`. Important successful applies/reads:

| Operation | Run ID |
|---|---|
| Payment fulfillment | 20261011T010402Z-d4792-d9410-apply-crm-payment-fulfillment-ce4b |
| Original DB backup | 20261011T011017Z-d4792-d9410-read-crm-database-backup-67b6 |
| Support configuration | 20261011T013110Z-d4792-d9410-apply-crm-support-mail-configure-9f85 |
| KYC fresh recovery | 20261011T013306Z-d4792-d9410-apply-crm-kyc-recover-2e3f |
| Lifecycle migration | 20261011T014028Z-d4792-d9410-apply-crm-repair-migrate-e748 |
| Single delivery canary | 20261011T014108Z-d4792-d9410-apply-crm-mail-canary-0725 |
| KYC worker enable | 20261011T014607Z-d4792-d9410-apply-crm-kyc-worker-8c74 |
| Canary audit only | 20261011T014608Z-d4792-d9410-apply-crm-mail-canary-62a7 |
| Stats recovery | 20261011T014632Z-d4792-d9410-apply-crm-stats-recover-d2dc |
| GH patch | 20261011T015143Z-d4792-exoticghanacom-apply-wp-manual-payment-patch-3ddc |
| NG patch | 20261011T015144Z-d4792-exoticnigeria-apply-wp-manual-payment-patch-d986 |
| Latest10 messages | 20261011T015637Z-d4792-d9410-apply-crm-manual-payment-replay-5970 |
| GH/NG metadata proof | 20261011T020754Z-d4792-exoticghanacom-read-wp-manual-payment-messages-7aff / exoticnigeria cdbb |
| Backend release | 20261011T021111Z-d4792-d9410-apply-crm-repair-release-dba4 |
| Release caches | 20261011T021130Z-d4792-d9410-apply-crm-cache-cd16 |
| Exact live health | 20261011T021346Z-d4792-d9410-read-crm-health-ed79 |
| Latest audit | 20261011T021410Z-d4792-d9410-read-crm-log-audit-38f3 |
| Login/assets/KYC/stat proof | 20261011T021911Z-d4792-d9410-read-crm-repair-followup-bccd |
| Gambia provider | 20261011T022039Z-d4792-d9410-apply-crm-gambia-push-provider-2794 |
| Provider independent verification | 20261011T022053Z-d4792-d9410-read-crm-gambia-push-provider-d53e |
| Current transports/provider/FX | 20261011T022053Z-d4792-d9410-read-crm-smaller-followup-0cfa |

Gambia private backup `/home/d9410/mkt-backups/crm-gambia-push-provider/20261011T022040Z-f4475c48.json`, SHA2c0daf2da87fb18f7b86af3f4f684bbaa656000a3ed4e6fc41f92da5f20fec00, audit581789. Other backup paths/hashes are in the matching successful ledger output; they remain private and verified.

Delivery: CRM app commits pushed and exact release verified LIVE; WP local commit and only GH/NG SSH patch LIVE; ops tools committed locally, no ops/WP push or Netlify deploy. Documentation/checkpoint/changelog stay local. Next: obtain Gambia push connection credentials when available, rotate legacy SendGrid key, perform Ian’s KYC UI QA, and observe scheduled retries; historical profile recovery needs original identities before a customer repair.

## Historical investigation and early checkpoints (superseded)

- Updated/status: 11 October 2026 EAT; repairs requested, payment client link applied/verified. Audited plan approved for end-to-end implementation, testing and rollout; KYC policy answer pending.
- Goal/scope: inspect CRM errors via SSH, resolve the identified issues, and ask about consequential decisions as work progresses. Use only ops bin/mkt for servers; preserve unrelated CRM/WP/ops work.
- Authorization: Ian selected the existing support mailbox, then explicitly requested linking payment32953 to its client and explaining/improving matching. Client-link scope is performed; deal association/reclassification remains undecided. Latest user instruction “okay then lets resolve end to end” approves the audited repair scope through implementation/testing/production rollout. Retain exact previews/backups/verification; do not repeat permissions covered by this grant. Named mail canary recipient, KYC policy and paid-deal classification remain missing decisions.
- Current production: d4792/d9410, latest observed HEAD9b072968; initial audit HEADe5fa78c6 is historical. Local CRM HEAD299926da; unrelated dirty Settings/commission/staff files preserved.
- Evidence/report: [report](../../../output/crm-log-audit-2026-10-11/report.md), redacted evidence JSON beside it. Final ops run 20261011T000646Z-d4792-d9410-read-crm-log-audit-8bb1.
- Findings: 114 ERROR/7913 WARNING entries over72h;7828 reconciliation warnings across38 old payments (36 Ghana pawaPay incomplete config,2 Paystack verification); Kenya payment32953 completed/unlinked, Senegal32895 now linked; CRM SMTP credentials absent/3 email failures;2 AI KYC jobs queued>25h with no scheduled consumer;21 manual-payment WP422 unsupported-field responses;25 private-content lifecycle enum errors;29 URL-search missing-table warnings;12 expiry failures one missing Kenya WP profile;2 staff-stat FK failures. Other issues/limits in report. Scheduled push work is not a proven backlog;scanner ready/failed/outbox0.
- Payment32953 repair: completed KES750, same-market WP user36696 uniquely identifies client3343094/post113275. Set client_id only; preserve active free-trial deal29353 backed by payment32954/KES0, activation/expiry/benefits and all other payment columns. Apply run20261011T003353Z-d4792-d9410-apply-crm-payment-client-link-500e; audit581650. Independent read20261011T003421Z-d4792-d9410-read-crm-error-repair-read-60c3 confirms client3343094 and deal_idNULL. Paid-deal association is still open, not silently treated as fully fulfilled.
- Backup/restore: private /home/d9410/mkt-backups/crm-payment-client-link/20261011T003355Z-0adf6f9e.json, SHA9e8c32b3a4cc8a998bbb3125509f0babf54a9b939f827eddfab74d2f31928a6c. Restore preview through registered crm-payment-client-link --restore=<path>; apply identical restore/expected args against that saved preview after specific restore authorization. Refuses post-state drift; never reactivates subscriptions.
- Matching cause: cache client was created13s after payment completion. Completion checks WP identity once, but the separate PaymentMatchingService write/dry-run paths only match phone. Current normalized payment phone has0matching clients; WP user has1. No scheduled automatic retry of completed/unlinked association was found in Console/Jobs. Improve shared unique market/WP-identity resolution, ambiguity/conflict handling and bounded post-sync/catch-up association retry, with fulfillment review retained.
- Additional live evidence: two historic Paystack records are already classified test;36pawaPay records pin inactive Ghana profile2 with configured key, while current-surface credentials are absent. Null-platform audit rows confirmed and daily-stat persistence missing. Stale Kenya client3296130 is absent now. Two readykyc-ai jobs/seven queued review rows; effective auto_approve; older jobs cancel because created_at is not today, so policy-controlled fresh review recovery is required.
- Mail: existing support@exotic-africa.com/Gmail STARTTLS587 selected. CRM encrypted stored SMTP is disabled/incomplete and credential delivery uses a different mailer than Settings test. Registered SMTP auth read20261011T003743Z-d4792-exotickenya-read-smtp-918c succeeds235 over verified TLS (one attempt, no mail). This verifies the existing source mailbox, not the still-unconfigured CRM mailer. No secret transferred/config changed or email sent. Diagnostic leaked a legacy SendGrid key from a username field; options/source-field redaction fixed and fresh remote read verified; key rotation remains an external-account action.
- Plan: [reviewable HTML](../../../plans/crm-error-repairs-2026-10-11/plan.html), source beside it. Independent audits1/2 found and corrected pinned KopoKopo context, selector, identity, fulfillment, KYC freshness and metadata ordering issues; round3 READY; added identity-recovery suite to the test filter. Ian approved end-to-end implementation. visual-plan/plan-audit require plan approval before broader application edits. Existing specific payment-link authorization completed separately.
- Verification:16disposable Local MySQL repair checks (preview, identity/market ambiguity, stale pin, transaction rollback, exact one-column change, backup permissions, repeat no-op, restore/drift) pass; synthetic SMTP redaction pass;3changed PHP lints pass; Changelog OK. Diagnostic original6redaction/multiline checks retained. No CRM application suite/build yet because application code is unmodified.
- Delivery: ops tool commit0c3ac99 local only; no push, CRM/WP application commit/deploy/migration or mail. Local plan/checkpoint/report update. Next: finish independent plan audit/review, record KYC answer, implement/test safe code fixes, prepare scoped configuration/data/deployment previews; verify every remaining log family before closure.

## End-to-end decisions, 11 October

Ian confirmed automatic approval for eligible KYC applicants; retain all existing eligibility, consent, current-version and budget guards. Ian confirmed trial deal29353 compensated for payment32953: associate/reclassify existing deal as paid with current expiry, no new activation or benefits. Canary authorized to Ian staff account email (resolve server-side, no customer credentials). Initial source auth succeeds; CRM transport is still pending configuration. Application implementation/tests in progress, not deployed. Production preflight20261011T005652Z-d4792-d9410-read-crm-preflight-89bd: HEAD9b072968, tracked tree clean, remote main299926da, no missing manifest files.
