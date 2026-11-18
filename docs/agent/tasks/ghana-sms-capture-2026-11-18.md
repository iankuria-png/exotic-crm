# Ghana MoMo SMS capture and matching — 18 November 2026

Status: PUSHED 6fe56479 to origin/main (18 Nov, remote SHA verified) — NOT DEPLOYED; no APK built/installed, no enrollment or production change. Private evidence:
`/Users/ian/Projects/exotic-ops/var/ghana-sms-2026-11-18/` (gitignored; customer names/phones).

## Access
Ian supplied Ghana phone wireless debugging 10.2.0.2:40039 + pairing code (not retained). 10.2.0.2 is
unreachable from the office Mac (protocol fault / port closed), as on the morning reversal task. mDNS
advertised the same pairing port on 192.168.5.56; paired there and connected 192.168.5.56:39297
(SM_A165F, RFGYB33NCNR). Read-only `content query` of content://sms only; no settings/messages changed.

## Phone export
14,495 SMS (Jan 2024 → 18 Nov 09:21 GMT), 13,243 from `MobileMoney`. Raw dump sha256
6fef2fa21ec399d0599f1d4945533fba8414407df18465071eaad3e6cc5f8747. CSVs: all SMS; parsed MoMo receipts
(12,876 credits: 10,846 `Payment received`, 2,030 `Cash In received`; 0 unparsed credit-like; 19 duplicate
transaction IDs = repeat deliveries); CRM-vs-phone exceptions.

Templates (English only, GMT): `Payment received for GHS X from NAME Current Balance… Reference: <payer free text>.
Transaction ID: <11 digits>.` / `Cash In received … from AGENT ENTERPRISE. … Transaction ID: …` / holds
`Y'ello … transfer of GHS X from 233… has been withheld and is pending reversal` / completed `…has been reversed on
… with transaction ID:…` / `reversal of the transfer <id> has been revoked`. Payer phone appears only inside
cross-network references (`NAME ,233…, x from VODAFONE`). Outgoing `Payment made`/airtime must be rejected.

## CRM baseline (mkt read crm-ghana-sms-match-read run …093740Z-…-2b21, SELECT-only)
Ghana platform 3 collects through manual method 1 "Send Money" MTN 233541442622 with
`auto_activate_on_submission=1`: customers type the MoMo transaction ID and activate immediately (CRM source
`gateway`, provider null). Only collection account is TZ (platform 6). Products GHS: VIP 190/7d, 300/21d,
380/14d, 760/30d; Premium 230/14d, 460/30d; VVIP 700/14d, 1400/30d.

Since 1 Aug: 1,748 phone credits; 1,654 (94.6%) have an exact CRM transaction ID. Median SMS→CRM 13.6 min
(p90 3.8 h). Exceptions:
- 29 paid CRM activations (GHS 19,980) with no exact phone receipt: one-digit typos/truncations or payer names
  typed instead of the ID; one-edit-ID / name+amount within 48 h recovers 19 (suggestive, needs review).
  Junk refs include `2580` for GHS 10,000.
- 47 transaction IDs where CRM claims more than the phone received (GHS 9,992 gap): same ID reused for two
  activations, or GHS 20–40 paid for a 380/760 claim. 16 legitimate splits sum exactly.
- 94 phone credits (GHS 27,062) not linked by ID: mostly the typed-name/typo cases, cash-in agents, interest.

## Gaps vs TZ pipeline
TZ-only: `TzMpesaParser` + `CollectionReceiptService` parser routing, `CollectionMessageService`,
`CollectionPayerEvidenceService`, enrollment (`country TZ`, `exotic-tz-v1`, `tzPhone`), automation policy
eligibility, receipt drift regex, APK `PaymentOnlyPolicy` (sender `M-PESA`). Ghana needs a different flow:
receipts VERIFY customer-submitted claims (exists, amount ≥ price, not reused, not reversed) rather than
receipt-first staff matching.

## Decision
Ian, 18 Nov: failed claim verification = **flag only, keep active**. Nothing holds, cancels or changes a subscription.

## Implementation (local, uncommitted)
- `GhMtnMomoParser` (gh-mtn-momo-v1): Payment received / Cash In credits (11-digit Transaction ID, device receive time,
  cross-network payer phone), withheld/reversed holds (no original ref → existing unmatched-reversal flag),
  revoked → `reversal_revoked_flagged` audit, outgoing/promos rejected, unparsed credit-like → drift suspension.
- `CollectionParserRegistry`: provider→parser (vodacom_mpesa_tz, mtn_momo_gh), GH/TZ phone normalisation, filter
  versions. Routed through receipt/message/payer/wallet-balance/SIM identity/enrollment (+ command) and staff search.
- `CollectionClaimCheckService` + `GET crm/collections/claim-checks` (`CollectionClaimCheckController`): self-submitted
  claims since device cutover → verified / pending(30 min grace, `collection.claim_check_grace_minutes`) / no_receipt /
  short_paid / receipt_reused / reversed, with one-digit-ID or typed-payer-name suggestions. Read-only.
- UI `CollectionClaimChecks.jsx` mounted in the Payments workspace (hidden without a Ghana account): counts, problem/
  window filters, CSV export, manager "Link to this receipt" via the existing receipt-alias endpoint.
- Phone: `tools/collection-phone/src/gh/PaymentOnlyPolicy.java` (exotic-gh-v1), `prepare.py --country GH` (Ghana
  number checks, version suffix -gh), build manifest country, Ghana on-device verifier (37 cases).
- Existing TZ automation/entitlement gates unchanged; Ghana is never automatic.

## Verification (18 Nov)
- Shared checkout carries another session's stale overlay (Payment.php drops `withoutReceiptDuplicates`, routes/
  controller drop 90c52338 lines). Tests ran in a clean `git archive HEAD` copy + only this task's changes.
- `GhanaMomoCaptureTest` 5/66 PASS. Collections feature suite 178 pass/10 skip/2 fail; the 2
  (CollectionSubscriptionLinkTest:104 decimal cast, CollectionSalesCorrectionTest:57) also fail on pristine HEAD.
- Pint PASS. Java filters: GH 37 synthetic, TZ 42 unchanged. Real inbox replay (local only): all 12,876 credits +
  73 holds FINANCIAL, 1,435 non-payment REJECT; CRM parser 12,876/12,876 credits, balance on all, name 10,772, phone 3,370.
- Browser (isolated fixture :5182): new `collection-claim-checks.spec.js` 5/5; existing payment-journey +
  phone-health 14/14.

## Next
1. Ian authorizes ship: commit only task-owned files/hunks (controller 3 lines, workspace 2 lines, routes 1 line are
   mixed files); build assets from clean HEAD + these changes, not the dirty tree.
2. After deploy: build/sign Ghana APK (`prepare.py --country GH`), install on SM_A165F, Samsung battery Unrestricted,
   read-only binding + cursor, `collections:enroll` (country GH, mtn_momo_gh, GHS, Africa/Accra, platform 3,
   manual method 1, filter exotic-gh-v1, recipient 233541442622 if confirmed as this SIM) → shadow; webhooks;
   live receipt acceptance; reliability checks as TZ.

## Shipping — 18 Nov
Ian: "Yes, ship it". Commit 6fe56479 built from an isolated index (HEAD + task content only); 29 paths incl. build
`app-DTTHhqh8.js`/`app-5kHwLNGj.css` + manifest built in a clean HEAD copy (pristine HEAD rebuild was byte-identical
to the committed bundle). Mixed files (controller, routes, workspace, state.md, manifest) keep other sessions'
uncommitted edits unstaged; nothing else staged. Pushed origin main 852c2992..6fe56479. WP changelog afb1876f9
(local, Changelog OK). No migration/seeder; config default added `collection.claim_check_grace_minutes`=30.

cPanel: pull main (preserve deployed overlays per verification.md), `php artisan config:cache` + `route:cache`
(new route/config), refresh PHP-FPM/opcache, confirm `/api/crm/collections/claim-checks` returns 200 (empty accounts
until Ghana enrollment). Then phone steps under Next item 2.

## TZ parity — 18 Nov (Ian: install app, Unrestricted battery, NOT shadow — same as TZ: assisted/auto with automation switchable; SIM = 233541442622; world-class matching and CS UX)
Discovery that reshapes the work: Ghana paid activations since 1 Aug are 1,410 CS-recorded deal payments with a typed
transaction ID (Lavender 1,068, Zeddy 142, Daniel 85, Amos 58, Victor 56) and only 59 self-service submissions. Receipt-first
"Match & activate" removes the typing that causes typos/reuse.

Ghana WP site (exoticghana public_html) runs exotic-crm-sync 1.3.21 WITHOUT the receipt contract: live GET
subscription-operation → rest_no_route (`crm-ghana-writer-capability-read` run …114838Z-5a5c). Needs plugin receipt files
(writers/operation/lock/owner + main-file registration) AND child-theme `escortwp_child_receipt_expiry_bridge_version`
(functions.php, 5bf3d9d66) — a WP rollout like TZ's. Until then platform 3 stays OUT of entitlement/writer/correction config;
Ghana account id 5c1b4a9f-b95e-4e64-bbf9-76ccc5c713d6 is pre-listed in automatic_accounts (inert without writer sites).

Local code (uncommitted at this checkpoint): Ghana payer identity = normalized MoMo wallet name (agents excluded) keying
payer links/learning; payer reference shown + below-threshold profile-name hint; `collections:import-history` (preview default,
historical_only receipts, idempotent) to seed 90-day learning; enrollment `account_id`/`initial_mode`/`receipt_learning`/
history recipient; pilot/observation waiver generalized to any registered provider; staff deal-payment guard
`CollectionReferenceGuard` refuses only PROVEN conflicts (reversed, already activated from Payments, already fully used,
short of the subscription) on markets with a receiving phone — covers create/renew/extend paths; full payer-name search.
History check: 469/619 payer names map to one profile; payer reference names the profile on 5.4% of matched receipts.

Phone: Ghana APK 1.78.1-exotic.3-gh sha256 1c0141bf…742fe (same signer 9dedbaf2…) built from pinned source with
`prepare.py --country GH`; installed on SM_A165F; on-device 37/37, no SEND_SMS, non-debuggable. Unrestricted: deviceidle
whitelist, RUN_ANY_IN_BACKGROUND allow, standby bucket 5; SMS/phone/notification permissions granted. SIM slot1/subId1,
MTN (620/01) roaming on Safaricom, carrier reported "Safaricom | MTN". Not yet bound/enrolled/webhooked (needs deploy).
