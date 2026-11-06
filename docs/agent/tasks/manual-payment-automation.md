# Manual payment capture, matching, activation and reconciliation

Current status,6November2026(EAT): shadow capture/workspace LIVE with genuine signed phone delivery, merge, retry, stale-alert/recovery and catch-up acceptance.4canonical receipts/9parsed messages,3pre-binding notices quarantined,0allocations/effects. Code/schema/worker25384127;49MySQL/521 +3browser/build +42installed-APK filter checks pass. Final filtered-app reboot emitted initial startup/check-in events, then stalled; manual open/start confirmation is pending. All entitlement/automatic/writer/correction gates remain off. Fresh exact2site WordPress baselines are active1.3.21/102files and lack operation/owner contracts. No real cash/subscription mutation.

## User requirements and decisions

- Most collection is manual; customers pay country till/wallet/bank/collector and send proof or reference. Reduce payment-to-live delay.
- Ghana and TZ each have an Android receiving phone physically in the office (Ian confirmed).
- 6 November: Ian identified the Tanzania handset as **TECNO SPARK 30C** and confirmed personal SMS/OTPs also arrive on it. [Handset prep](../../manual-payment-android-setup.md) now records verified KL5/Android14/HiOS14.5.0; SIM/account mapping remains open. Direct unfiltered SMS forwarding is held pending an on-phone filter/dedicated receiver.
- 6 November office connection: authorized wireless ADB pairing and screen control verified; scrcpy5.0.1 and secure SMSGate1.78.1 checksum verified/installed. Local signed heartbeat4/4 over74seconds screen-off,2failed event IDs recovered after503. HiOS App Launch Prohibited→Allowed and app power saving Intelligent→No Restrictions; final repeated reboot starts services but API/heartbeat stalls until foregrounding app. Unattended startup NOT accepted. All temporary hooks/ping/tunnels/receiver removed; local/cloud/autostart off, app stopped, SMS/phone permissions denied. Mac pairing retained. Next: payment-only on-device bridge fallback plus boot/reconnect/negative-OTP/retry/catch-up acceptance. No SMS webhook/cloud registration/CRM enrollment/production change; see handset prep.
- TZ #6 is paid primary; Tanzania #2 receives free same-period exposure. One paid receipt, separately traceable dependent benefit.
- Smart hybrid matching: alternate payer numbers, multiple advertiser profiles, payer history, recent subscriptions, pending sales deals, noncatalogue negotiated amounts and sales-created ProductPrice/duration rows.
- Clear repeat cases should activate awaiting review; ambiguous cases show prefilled choices. Payments AND Client Detail need shared review, undo and reassign UI; ordinary corrections must preserve one cash event and prior/later legitimate benefits.

## Canonical artifacts

- [Visual plan](../../../plans/manual-payment-automation-2026-10-25/plan.html), [typed source](../../../plans/manual-payment-automation-2026-10-25/plan.mdx), [business overview](../../../plans/manual-payment-automation-2026-10-25/overview.md).
- [Message atlas](../../../plans/manual-payment-automation-2026-10-25/message-atlas.md), sanitized collection inventory JSON/CSV, revenue snapshot, reference-shapes.json, purchase-patterns.json, source-parity.json and empty sample-register-template.csv in the same folder.
- Original five proof images/live source snapshots stay private in Ops ignored `var/manual-collection-research-2026-10-25/`; no raw personal financial images in plan artifacts.

## Findings

Native CRM window 26 September–25 October: manual USD54,462.47/90.8% of revenue; manual5,039 rows are not established distinct paid transfers; a separate created-date sample shows zero/noncash records. Ghana+TZ~62.8% of manual value. Several FX metadata entries stale; reporting estimates are not settlement balances.

Current CRM33 manual method rows/11properties/10enabled;9enabled activation-on-proof. GhanaMTN, Tanzania2Vodacom, NigeriaUBA, EthiopiaAwash, ZimbabweEcoCash verified as configured labels. TZ6 receiver provider remains unverified; code shape does not identify it. Five approved sender proof samples examined: MTN message, OPay→UBA receipt, CRDB→Vodacom processing receipt, cropped Ethiopia USD/ETB summary, ZimbabweUSSD. Sender and receiver references may differ.

Live catalogue proves TZ35,000 price collision: standard7d price143, sales8d price242, sales7d price256. Bounded unclassified deal history shows GH220/230 for14dPremium, TZ30,000/35,000 for7dVIP; null discount fields and tiny positive records make blind history learning unsafe. Exact amounts/product names alone do not establish intent, live-paid status or discount authority. Payment.phone may be customer-entered/contact data, not observed payer.

Deployed ManualPaymentSubmissionService/DealPaymentService/ManualPaymentBundleService hashes match Local; PaymentQueueController/SubscriptionProvisioningService/PaymentCompletionService/lifecycle lock/routes differ. Additional SubsidiaryTrialService/deactivation/identity services match; PaymentMatchingService differs. Do not ship the dirty checkout or assume pending wallet work is deployed.

## Proposed implementation

Provider-neutral private Android capture trial; prefer an official receiving-account feed when actually entitled. Versioned deterministic parsers, raw-event/outbox durability, canonical account-scoped receipt identity, reviewed many-to-many payer relationships, purchase/negotiated-price evidence, and a shared Payments/Client Detail drawer. One economic event, durable primary/dependent benefit effects, correction saga, collector subledger and statement reconciliation.

Phase2 capture/suggestions can deliver independently. Before ANY phase3 entitlement write: shared writer guards, persistent WordPress operation ID/exact-target expiry/tier/replay, uncertainty recovery and safe corrections. Same-tier fast path; mixed-tier nonrepresentable overlaps held without downgrading prior coverage. Auto phase adds reviewed payer/purchase inference and atomic per-account/currency daily/outstanding exposure budgets. New discount/recurring-authority policies default off; no worker-held PIN or invented discount.

Estimate24–36engineeringperson-days assisted;12–20additional unattended logic/policy/rollout;5–8calendarweeks assisted subject to evidence. Two phones already exist. Scope is substantial; these are estimates, not quotes.

## Evidence and verification

Ops registered read-only check `crm-manual-collection-research-read`: runs d9ad/3ba1/705c/16e8/2f2e on25Oct (full IDs in plan). No Laravel boot/provider requests/application writes. Private bounded proof/source sampling and pseudonymous deal histories; current route/provider labels and aggregate counts only in shareable artifacts.

Visual-plan schema/render currently passes28blocks. PHP diagnostic syntax and registry JSON checks pass. No application tests, live phone capture, provider transaction/canary or browser implementation QA claimed. Plan-audit round1NOTREADY→writer/subsidiary/UX fixes;round2NOTREADY→phase3WPcontract/tiercomposition/atomicexposure fixes. Round3READY/noBLOCKERorSHOULD-FIX; two wording nits corrected. Three independent rounds complete; audit.md retains results.

## Next action and deployment

Independent plan audit complete READY after3rounds; hand Ian reviewable files. After plan review: verify recipient inbox/SIM/account mappings on the two office phones, collect paired receiving-side fixtures and evaluate capture bridge. Business gates: account/currency automatic exposure caps, correction-duration policy, collector ownership/fees/remittance; receiver corpus is not available from current sender proofs. No implementation/deployment authority inferred from research request. Preserve unrelated work.

## 30 October — TZ recipient XML and autonomous-default revision

Ian supplied `/Users/ian/Downloads/sms-20261030121225.xml` and reiterated autonomous arrival→customer/package identification→activation, then sales review/correction. This is research/plan continuation, not permission to activate historical receipts or deploy a collector.

The SMS archive contains61,544SMS+593MMS, exact62,137declared total; SHA c423dd1cdd03a420959bc06cc2d6aa156a9555e02ee3298aaca534b242ec4c57. Four M-PESA credit families produce46,323notifications/30,923distinctrefs; complementary merchant-summary/payer-detail messages often share a receipt. TZ receiving rail is now observed; verify current physicalSIM/account ownership/binding during enrollment. Other-provider notices do not prove other collecting accounts.

Fresh SELECT-only CRMread8,891payments/9,006deals/minimal2,008clientidentities, Aug1–Oct30 extract for platforms2/6. Sep1–exportpartialOct30 receipts3,413;3,222someCRMreflink/3,018singleexactprimaryamount+currency links. Of2,355exactlinks with observedpayer+normalizablecurrentadvertphone,1,482differ. Reference-link/parse coverage is not autonomous accuracy. Existing labels are not independently adjudicated intent.

Canonical updatedplan.mdx/HTML now31blocks; full findings `plans/manual-payment-automation-2026-10-25/tz-sms-review-2026-10-30/findings.md` + sanitizedJSONs. Researchscripts under `research/manual-payment-automation/`; rawparsed/customer/receipt data privateOpsvar/tz-sms-research-2026-10-30. Independent count/tokenjoin reproduces3018exactlinks;4syntheticlayout+negativecasespass. Current30Octrevision audit READY after2freshrounds; retain25Octaudit as dated history.

New contracts: paired-message merge/per-fieldprovenance/20–30senrichmentwait; historicalXML no-entitlement cutoff; current ownedintent or reviewedpayer+standingrenewalagreement for autonomouscase; nofreshrepdealneededforclearrepeat; scopedexceptions/reviewafteractivation. Reuse existingPaymentFulfillmentOperation/recoverycontract from27Oct rather than competingexecutor; live tablepresent/automaticgateempty/UTCconfirmed. ExactTZWPwriter/capabilityacceptance remains required; Localmaininclude references2absentoperationclasses while approvedpriorreleasecopiesexist—do not shipincompleteLocaltree. No repairperformed.

Opsreadce03(30Oct10:28UTC) + metadataa397(10:32UTC), fullIDs/methods in findings. No production mutation, payment/subscription/currency change, phoneinstallation, send, commit, push or deployment. Revisionaudit completeREADYafter2rounds: retrospective reversal training/evaluation aligned, explicit-test-only/sandboxflaglimit stated, payeravailability enforced and exceptionwireframe labelled. Next: Ian review ofupdatedplan/findings; subsequent authorized livephone/target/corpusadjudicationpilot.


## 30 October — authorized local implementation, checkpoint 1

Ian authorized implementation in CRM, incrementally tested, with production gates disabled and no push, deployment or real financial mutation. Existing unrelated work is preserved; starting HEAD `d639993fc60a7709d0a8de25fb30228233aa0fd4`. A pre-task tracked diff and untracked manifest are in `/tmp/exotic-crm-before-manual-payment-automation*` (local preservation aids, not release input).

Implemented locally so far: additive encrypted collection account/device/event/receipt/outbox ledger; native SMSGate raw-body HMAC; reviewed four-family TZ parser and complementary merge/provenance; sticky historical cutover; reviewed payer/pinned purchase authority; aggregate exposure reservation; existing PaymentFulfillmentOperation owner integration, exact-target readback and uncertainty recovery; explicit dependent TZ→Tanzania interval; writer holds; shared gated Payments/Client Detail drawer and arrival feed; correction saga held unless exact site advertises compensation v2; statement/remittance domain. No source XML imported into the DB. No real accounts enrolled or gates enabled.

Verification at checkpoint: PHP8.2.30, `CollectionCaptureTest` + existing `PaymentFulfillmentRecoveryTest`:10pass/104assertions; `CollectionAutomationTest`:8pass/69assertions (before fixture extraction and later review/correction additions). Read-only `collections:inspect-history` reproduces46,323credits/30,923references and sourceSHA, explicitly0DBwrites/0dispatches. Isolated Vite build under `output/manual-payment-automation-2026-10-30/build` succeeds; existing forecast CSS and large bundle warnings remain. Existing `public/build` has not been overwritten.

Work in progress: correction/review/API regressions, browser behavior, broader billing regressions, real-MySQL lock/constraint checks, formatting and self-review. Source changes after the recorded runs require fresh verification. Exact-site WP compensation v2, complete plugin manifest and all-writer acceptance remain deployment blockers; existing v1 refuses expiry reductions and is intentionally not treated as correction-capable. Do not enable production flags. No commit/push/deployment performed.


## 30 October — local verification checkpoint 2 and Android preparation

Local CRM implementation is now reviewable; this is not a production-ready rollout claim. See [implementation and acceptance boundary](../../manual-payment-automation-local-implementation.md) and [office Android setup guide](../../manual-payment-android-setup.md). The guide separates phone preparation from later authorized shadow enrollment. Official SMSGate candidate release resolved to v1.78.0; no APK was installed, no handset filtering/reliability acceptance is claimed, and no production endpoint/key was provisioned.

Implemented beyond checkpoint1: pre-dispatch reassignment with allocation/version rechecks; active undo/reassign saga with exact-site compensation capability gate; stale preview/idempotency recovery; independent sales/statement exposure releases and correction review-risk reopening; receipt aliases with finance evidence; statement-only historical/discrepancy handling, fees/reversal/remittance entries; shadow-only enrollment command with private-file/actor validation; supervised-capable outbox loop; query caches scoped to staff; shared-account AND subsidiary-market privacy. Verified remote subscription snapshots now update only the matching local subscription cache fields, avoiding a stale Client Detail activation/undo state. Historical/late conflicts never silently relocate or re-grant.

Verification (PHP8.2.30; source HEAD unchanged d639993f with this task's uncommitted files and pre-existing dirty inputs):

- Final disposable MySQL8.0.35 run `mysql-acceptance.txt`:36tests/400assertions PASS, including actual two-process duplicate/budget races, primary/subsidiary/crash recovery, verification-only proof, correction/undo and settlement/enrollment. Fresh scope follow-up after tightening linked-market privacy:3tests/40assertions PASS in `mysql-scope-final.txt`.
- Final scoped legacy suite:51tests/410assertions PASS in `legacy-final.txt`. One known `ManualPaymentBundleCommitTest::test_commit_rolls_back_created_rows_when_later_child_activation_fails_and_compensation_succeeds` remains500 vs expected422. Reconstructed pre-task relevant classes reproduce it (`baseline-bundle-check.txt`), so it is retained as a baseline failure, not silently fixed or included as a pass. Earlier broader run86pass/1baselinefailure/2SQLite-only skips is in `backend-regressions.txt`.
- SQLite collection sequence:29pass/350assertions with2MySQL-only skips; focused automatic gate/flow10pass/99assertions and settlement/enrollment4pass/23assertions also pass. MySQL provides the locking evidence; do not count SQLite skips as coverage.
- Browser real React components on isolated Vite port5182 with intercepted synthetic APIs:desktop/mobile, modal focus/Shift-Tab/Escape and return, stale409 retry, filter preservation, shared Client Detail drawer, correction hold and payer masking PASS. `browser-verification.txt` and three PNGs retain evidence. Expected synthetic409 is exercised; no payment-provider traffic. A backward-Tab issue was found and fixed with an explicit focus boundary.
- Isolated final production Vite build PASS under output; existing forecast reduced-motion CSS optimizer warning and >500KB bundle warning remain. Existing `public/build` untouched. Scoped Pint and PHP syntax pass; source manifest, exact counts/logs and preservation result retained in `output/manual-payment-automation-2026-10-30/`.
- Read-only native PHP archive parser reproduces46,323creditnotifications/30,923references, sourceSHA unchanged;0DBwrites/0dispatches. `archive-parser-check.json` retains aggregate-only output. No historical XML was imported into application tables.
- Preservation audit reconstructs the before-task tracked patch and confirms34unrelated changed/deleted paths byte-identical; differences list empty; index/staging remains empty. Own mixed-file additions preserve the pre-existing billing/lifecycle edits. No commit or push.

Release holds / next action: review the local source and complete the exact TZ/Tanzania WordPress manifest and compensation-v2 implementation/acceptance, then prove or exclude every alternate writer. Existing v1 rejects expiry reductions; synthetic v2 is only CRM saga coverage. Later legitimate paid coverage and consumed/paid benefits remain held for reviewed recovery. Public legacy proof submission is not promoted to owned customer authority. No Ghana receiving parser/corpus acceptance, phone filtering/reboot/retry/catch-up proof, real-account ownership/policy/caps,7clean observation days/100credits or real canary is claimed. Migration backup/restore and clean build/release packaging remain future deployment work. All production gates and recurring-negotiated/correction policies remain disabled/unset. Ian's no-push/no-deploy/no-real-financial-change constraint still applies.


## 6 November — authorized phone release checkpoint

Ian explicitly requested full shipment and authorized phone configuration. Personal SMS/OTPs
require filtering before logs/storage/transport. The receiver is **not a till**: Ian supplied
Amani Mollel's payment image/number and confirmed an **Exotic-controlled business wallet**.
Store the number in private enrollment evidence. Configurable caps are explicitly Unlimited
for now; correction grants the full purchased period from correction. Reboot recovery may
require staff to unlock/open the app; CRM must show actionable outage/recovery status.

Implemented: scoped capture health/recovery API and UI, global attention badge, freshness/
filter/SIM-aware automatic gate; manager-only optimistic/versioned four-cap policy with
explicit null Unlimited approval and audit; full_purchased correction policy. Explicit
Android Subscription ID identity mode preserves legacy ICCID strictness, checks each active
binding and suspends accounts on live mismatch/drift. The phone reports slot1/Subscription ID1,
carrier Safaricom — VodaCom Tanzania, no measured number/ICCID. Recipient provenance remains
user-confirmed enrollment. Source-filter fork has no general inbox/send/export/cloud mode;
loopback-only administration, durable native signed outbox, explicit cursor initialization.

Installed release1.78.1-exotic.3/APKde6f5bd4, signer9dedbaf2;42actual-APK synthetic checks PASS,
release non-debuggable and SEND_SMS absent. Binding/cursor saved from ID-only inbox query;
zero hooks, no historical body export, no actual incoming capture yet. Separate package's
final HiOS/background/reboot/retry and production signed health checks remain open.

Isolated intended72-file CRM snapshot excludes unrelated wallet/lifecycle/routes edits;
optional WalletLifecycleGuard coordination remains when that separate feature is present.
MySQL46tests/508assertions PASS, including actual concurrency and new SIM coverage. Three
browser desktop/mobile journeys PASS; intended frontend build PASS (existing large-bundle
warning). Legacy62tests/488assertions:61pass/1previously reproduced baseline bundle rollback
500-vs422 failure. Scoped Pint/syntax and42JVM filter checks pass. Evidence under
output/manual-payment-phone-release-2026-11-06; no raw SMS or keys belong in Git.

Fresh production backup6e04f995 (2,089,103,024bytes/281tables) downloaded through registered
read. Initial isolated MySQL restore refused MariaDB's explicit generated-column data;
matching-engine restore verification is pending, so migration is held. Production tracked
scanner-test edits and untracked files must be preserved by a pinned/disjoint release.
No production writes/deploy, commit or push at this checkpoint. Extension plan and two-round
READY audit plus Android identity READY audit are in the phone-release plan directory.

Continue: finish matched-engine restore, exact isolated release/ship/changelog, scoped
migration and shadow enrollment/capture+alerts verification. Then complete real WordPress
compensation-v2/all-writer acceptance. All entitlement/automatic/correction gates remain
empty until genuine observation/reconciliation/device/site acceptance; do not manufacture
seven days/100credits or treat shipping permission as a real-money canary authorization.


## 6 November — deployed code/schema, shadow enrollment; connectivity hold

User's full-shipment request authorized scoped CRM push/deploy and office-phone setup;
no new approval is required for remaining work in this scope. Do not claim the feature
fully usable: capture/workspace remain off and automatic/site acceptance is incomplete.

- Core commit a13985df pushed and deployed through the committed isolated Ops runner
  `var/mp6`, preserving three tracked scanner-test edits and27untracked server files.
  Preview8046→apply8529, actual FPM8.2.35 verifies all gates off, exact source/generated
  cache invalidation and route; served JS/CSS hashes match the tested isolated build.
  Independent143path/hash readf193 and health0dd7 pass. Two release-runner audits fixed
  cache/gate proof, metadata writes, failed-swap recovery and maintenance ownership;
  final audit READY. Private code backup ends `collection-code-20261106-101209-2564f7`.
- Full backup6e04f995 restored successfully on isolated network-none MariaDB11.4.13,
  matching production:281tables/9views,39,946payments/34,613deals. Scheduler events OFF;
  no application bootstrap/provider calls. Exact64DDL statements create20new collection
  tables on the restored copy. Initial MySQL generated-column refusal is retained.
  Scoped production migration previewd6d0→applye7b7:20empty tables, no existing record
  change; private preview evidence ends `collection-migration-20261106-101421-512ff6`.
- Dedicated stable fingerprint key provisioned privately (e3b4); only one env line appended,
  private original/readback and actual FPM cache proof, all gates off. No key value in Git.
  First native enrollment attempt refused missing key and rolled back; a console-output
  parsing attempt also rolled back. Wrappers now check persisted identity and nonzero
  refusal status instead of treating console/exit-zero output as acceptance.
- Native shadow enrollment6b30: company-owned wallet, registered Amani Mollel, primary
  platform6/exotic-tz.net; source/provenance from Ian's payment image and business-control
  confirmation. Slot1/Subscription ID1, exact observed carrier, private receiving number,
  signed APKde6f5bd4 and saved cursor. Server cutover6Nov10:25:42UTC. Native manager policy
  saves all four caps as approved null Unlimited/version1. Independent READ ONLY2177
  verifies account/device/SIM/policy/cutover;1account/1device/1binding/2audit rows,
  zero events/receipts/allocations/effects and health awaiting_check_in. No SMS hook.
- Bounded worker follow-up25384127 is pushed: scheduler runs capture-enabled45-second
  background windows with overlap/server guards; monotonic budget stops further task
  claims and an interrupted run can resume next tick.3new lifecycle tests/13assertions
  PASS; full intended74source snapshot49tests/521assertions PASS. Initial isolated
  harness lacked a fixture APP_KEY; invalid harness run retained and corrected before
  acceptance. No frontend source/build change in this follow-up.

Actual phone connection dropped during HiOS setup. ADB discovery briefly showed a changed
private IP, then no connected device; the last advertised port refused connection. Pending
question asks Ian to unlock/enable Wireless debugging and supply current IP:port. Never
repeat/store the temporary screen passcode. The filtered app's SIM/cursor remains configured,
but its final HiOS background settings/reboot/retry/live signed delivery remain unverified.
Stock SMSGate stays stopped. No historical message was exported or XML imported.

Office Internet now identifies as41.139.154.203/KE, WARPoff; normal anonymous `/api/ping`
and disabled collection route both return empty403. This is a different public IP from
5Nov105.163.2.178; do not claim the old address was unblocked or that the phone's current
route was measured. Registered server health/root/billing200. Use hosting investigation or
verified mobile-data/working-network fallback; no support message sent or firewall changed.

Worker release preview1fca→apply3e6d is LIVE25384127. Independent final145source/build hashesb4ef, read-only enrollment9ef7 and health6ecd PASS; actual FPM gates off, root/billing200. Private worker backup ends `collection-code-20261106-102835-8ea3c4`. No further runtime source change is pending at this checkpoint.

Next: regain phone/network,
complete per-package HiOS/settings and real signed heartbeat/negative/retry/catch-up proof,
then enable capture/workspace only and observe actual incoming credits in shadow. Complete
exact TZ/Tanzania WordPress compensation-v2/complete-manifest/all-writer acceptance and
reviewed payer/purchase/statement evidence before entitlement enablement. Genuine7days/
100credits remain required; no synthetic acceptance, retrospective activation or real-money
canary is inferred. Existing Local WP tracked deletions of operation/owner files are preserved.
Private configs and run logs remain Opsvar/AndroidTools; no Ops/WP push or Netlify deploy.


## 6November — actual filtered-phone shadow pilot LIVE

User screenshot supplied the changed debug port; direct ADB reconnect reused the existing
pairing. Phone network reaches public CRM200 (native Android HTTPS probe), while laptop
route403 remains a separate access issue. Actual installed health reflection verifies SMS
permission/filterexotic-tz-v1/SIMtrue/driftfalse. App Launch Allowed verified; Battery Lab
Intelligent→No Restrictions selected and re-opened/radio-verified for the new package.
Start-on-boot verifiedtrue, localservertrue/cloudfalse. Automatic UTC clock differs from
Mac by~1second, timezoneAfrica/Nairobi, charging/battery rising. No passcode retained.

Scoped config preview305e→applyf325 enables only COLLECTION_CAPTURE_ENABLED and
COLLECTION_WORKSPACE_ENABLED, with exact env preimage/private original/readback/actual
FPM verification. All4entitlement arrays remain empty; company wallet stays shadow.
New env digest6b299a7c; guarded disable job retained separately. Old release/rollback env
pins intentionally refuse drift and must be refreshed/repreviewed before later recovery.

Native phone signing key matches enrolled device. Three direct-HTTPS hooks only: heartbeat,
app-start and receivingSMS. Metadata handshake first succeeded6895; then SMS hook enabled
with saved cursor intact. Native phonescreen-off source0f1d shows fresh ready check-ins;
three current credits parsed initially and3pre-binding messages quarantined. Temporary
loopback health-only503test:3failed event IDs all recover204with identical raw hashes/
valid signatures,0signature failures. Test hook/receiver/reverse tunnel removed; real
financial hooks never pointed at that target. Aggregate retry proof retained in output.

Actual outage test: app force-stopped11:50:57UTC; native reada876 at11:56shows
no_recent_contact/attention1/capture_readyfalse after300seconds. Opening app restores
ready/attention0; native73df at12:00:36 shows no outbox backlog/errors. Independent
READONLY349d proves9parsed events→4canonical receipts,1distinct complementary-body merge,
4duplicate-body events coalesced,2distinct messages received during the stop and ingested
on reopen.0financial effects. All4receipts need review. No XML import/historical activation,
provider transaction, outgoing SMS, commission/pass grant or real subscription mutation.

Final filtered-app reboot issued around12:03UTC with autostart/local true/cloud false;
pending first unlock and actual startup verification. Android encrypted app storage requires
first unlock. Do not assume unattended startup from settings/service presence; if needed,
manual open/start recovery is already accepted by Ian and verified by the actual stale test.
No extra permission is needed for remaining authorized setup. Pending question asks Ian to
unlock/restore debugging while leaving the app closed initially. Avoid repeating/storing PIN.

Fresh exact WordPress source snapshots: primarya3ef /subsidiary80bd, both active sync1.3.21
with102runtime files and no class-subscription-operation or recovery-owner file. Primary
root/site/database pin checked; secondary fresh discover98c3 and inventory discovery pin
checked. Initial root syntax refusal before valid reads retained. Source snapshots are
private Opsvar/manual-payment-release-2026-11-06/wordpress-{primary,secondary}-baseline;
summary alongside them. No WP bootstrap/source/DB changes. The77-line operationv1 in WP
HEAD is a Local source baseline, not deployed capability. Preserve Local tracked deletions.

Next: complete final reboot/unlock check and retain final healthy pilot; refresh live/source
notes. Continue exact WP compensation-v2 and all-writer reconciliation from verified live
baselines, not the mixed Local checkout. Genuine observation/reconciliation/reviewed payer
and purchase authority remain mandatory before automatic flags; no fabricated7days/100credits.


Final boot follow-up: signed app-start events rose3→5 and one new ping arrived12:04:48UTC,
but the regular30second series did not continue. ADB/debug discovery did not return. Do
not accept unattended startup from the initial event or service setting. Pending question
now asks Ian to open Exotic Payment Capture/START SERVICE; this is the already-approved
rare-reboot recovery step, not new setup permission. Verify fresh ongoing server check-ins
before declaring the phone back healthy. Android ordinary credential-encrypted storage
requires first unlock, but no physical unlock action is claimed without Ian confirmation.
