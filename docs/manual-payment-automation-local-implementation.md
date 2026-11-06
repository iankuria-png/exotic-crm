# Manual payment automation: local implementation and acceptance boundary

30 October 2026. Starting CRM HEAD: `d639993fc60a7709d0a8de25fb30228233aa0fd4`.
This is a local implementation checkpoint, not a production release or a claim that
the office phones, TZ WordPress target or receiving-account policies have passed acceptance.

## What the local implementation does

- Persists encrypted native SMSGate events and a transactional processing outbox before
  acknowledgment. Verifies exact raw-body HMAC, fresh delivery time, enrolled device and
  current SIM/recipient binding. Retries deduplicate; changed bytes for an existing ID
  produce a durable conflict and HTTP409. Revocation, SIM drift and parser/reversal anomalies
  stop new automatic decisions while retaining receiving evidence.
- Parses the four observed TZ receiving layouts with exact minor-unit amounts, opaque
  10/11-character references and explicit Dar es Salaam provider time. Consolidates paired
  messages under one receiving-account receipt, retaining per-field observation IDs. A
  missing payer gets a durable25-second enrichment wait; late detail re-evaluates the same
  receipt. Conflicting principal, payer, currency or provider times over two minutes stay held.
- Separates reviewed payer/profile relationships from advert contact data. Pins current
  quote or reviewed standing renewal terms, exact ProductPrice/duration, approved discounts,
  destination and profile identity. History is contextual; it never grants automatic authority.
  A public legacy proof submission is not promoted to authenticated customer ownership.
- Checks existing cash claims and finance-reviewed reference aliases before creating a new
  Payment. Ambiguous historical destinations, split roots, sandbox/test, other purposes and
  conflicting claims stay in review. Already-active proof cases use verification-only
  behavior with no extra cash, days or dispatch.
- Reserves allocation and aggregate daily/outstanding exposure in one transaction under
  account and profile ownership. MySQL locking reads avoid stale repeatable-read snapshots.
  Policy revisions do not create another daily allowance. A started accounting day keeps
  its timezone; outstanding risk crosses midnight. Sales review and independent statement
  verification release separate counters once. Corrections reopen original review risk
  without reserving another cash event or replenishing daily gross.
- Uses the existing unique per-payment `PaymentFulfillmentOperation` as the primary owner.
  Pins absolute expiry/tier and before-state before external dispatch. Persists dispatch
  state, then requires remote operation lookup and current profile readback. A lost response
  never retries an add-days call. Known results can be recovered with new-write gates disabled.
- Tracks TZ paid service and explicitly reviewed Tanzania free exposure as separate effects
  under that owner. The dependent effect keeps the original paid interval, preserves longer
  compatible coverage and retries independently. It does not reuse an arbitrary active trial
  or clamp the paid period to90days. There is one positive payment/principal reservation;
  any reporting-compatible subsidiary Payment has zero value.
- Adds the same gated receipt drawer to Payments and Client Detail, scoped arrival polling,
  named review states, exact package choices, negotiated-quote context, correction previews,
  operation progress, history, exports and collector reconciliation totals. Staff/query caches
  are scoped by user identity. Polling pauses in hidden tabs and catches up on focus.
- Supports reassignment before dispatch, active-effect undo/reassignment through a durable
  source→target saga, cash-preserving current attribution, unpaid commission reversal audit
  and traceable unclaimed-pass revocation. It never calls destructive profile Revert or
  provider refund APIs. Stale previews, consumed/paid benefits, untracked or later legitimate
  coverage, unsupported tiers and uncertain outcomes stay held.
- Records statement credits, discrepancies, fees, reversals and independently evidenced
  remittances in a separate account/currency subledger. Those records do not move money or
  create another positive Payment.

## Commands and source boundaries

Use `/usr/local/opt/php@8.2/bin/php` for every command below.

`artisan collections:inspect-history /absolute/path/archive.xml` is read-only. It emits
counts and a source digest, never message bodies, and has no import/apply/activation option.
The supplied XML remains private and was not imported into the application database.

`artisan collections:enroll /private/0600-config.json --actor=STAFF_ID` previews a verified
mapping. A later explicitly authorized `--apply` creates shadow capture only and pins the
server cutover instant and durable inbox cursor. Required private configuration fields are
validated by `EnrollCollectionDevice`; no real configuration or device key is shipped here.
Account ownership evidence, exact receiving identifier, device ID, SIM/ICCID/carrier,
recipient, destination snapshots and pinned APK/version/hash must be supplied from the
actual device/account enrollment. No phone was installed or enrolled during this task.

`artisan collections:work --limit=100` processes one bounded outbox pass.
`artisan collections:work --loop --poll=2` is the intended supervised worker after a later
authorized deployment; SIGTERM/INT stops its loop. It is not installed in production cron
or a supervisor by this task. Queue outages leave durable pending work. Raw capture does
not depend on a synchronous activation succeeding.

The native signing contract was checked against
[SMSGate's official webhook documentation](https://docs.sms-gate.app/features/webhooks/).
This does not replace acceptance of the actual pinned APK's retry, filtering, heartbeat,
reboot, multipart, signature freshness and authenticated catch-up behavior.

Main code is under `app/Services/Collections/`, `app/Models/Collections/`, the additive
30October migration, `config/collection.php`, `routes/collection.php` and the shared
`resources/js/components/collections/CollectionWorkspace.jsx`. Existing provisioning,
catalogue/manual payment, commission, lifecycle lock and WordPress operation APIs are reused.
The primary operation retains its original per-payment uniqueness and immutable purchase
history; correction/dependent effects are children, not competing cash executors.

## Gates deliberately still disabled

Capture and workspace default off. `entitlement_platforms`, `automatic_accounts`,
`verified_writer_platforms` and `verified_correction_platforms` are empty. The correction
duration policy is unset. There is no web endpoint that enables these release gates.

No financial limits were guessed. Automatic account policy requires exact integer single,
daily gross, outstanding unreviewed and phone-unverified caps, a policy version and a saved
maximum receipt age. It additionally needs the approved parser version, device/capture,
historical-reference mapping, settlement and correction acceptance, seven clean observation
days and100positive receiving credits. Current reviewed payer/purchase authority and all
other eligibility checks remain necessary. A standing negotiated price needs separately
enabled recurring authority; prior one-off discounts never become that authority.

Every supplied XML observation is historical-only. Pre-cutover provider transactions and
unknown provider time remain ineligible even when delivered after cutover. That classification
is sticky across later enrichment. Statement-only historical records do not dispatch either.

## WordPress and writer acceptance still required

The existing WordPress v1 operation contract supports exact extensions, and refuses expiry
reductions. This CRM's active compensation path therefore requires a separately accepted
`capabilities.compensate = 2` response plus the correction platform gate. Its request pins
`contract_version:2`, `operation_kind:compensate`, source operation ID, expected fingerprint,
exact target expiry/tier/status and a restricted restore snapshot. The remote operation must
persist/replay its result atomically, serialize all affected entitlement writers, reject
changed request bytes/identity/preconditions, and return a current snapshot. CRM compares the
restored entitlement fields, tier, status, deal and expiry before allowing a target effect.
The synthetic v2 fixture tests the CRM saga; it is not an implemented or accepted production
WordPress v2 endpoint.

No WordPress plugin files were changed here. The plan's warning about missing Local operation
includes and the reviewed prior release manifest still applies. Reconcile the complete exact
TZ/Tanzania plugin source and implement/accept the compensation capability before releasing
any assisted correction. Do not infer acceptance from another market or table presence.

CRM profile holds survive workers and gate changes. Main lifecycle/provisioning writers and
WordPress HTTP entitlement mutations consult them; owned cash mutations are guarded. New
allocation commits also take the existing profile lifecycle locks. Visitor payments and
profile media edits are outside subscription ownership. Legacy manual/approval/import/callback
and direct WordPress fallback routes still need exact-site adversarial all-writer acceptance;
excluded pilot routes must remain visibly blocked. This is not a claim that every legacy
writer has been converted to the new coordinator. Leave financial gates empty until that
integration/exclusion is proven. Saved legacy market billing settings were not changed.

The correction fast path holds later independent paid coverage rather than fabricating an
unproven rebased schedule. Mixed-tier composition, untracked historical effects, consumed
passes/rebates and paid commissions take reviewed recovery. A compatible future implementation
must retain an append-only contribution history and prove the surviving actual WP state.
Ghana capture can be enrolled in shadow after authorization; its receiving parser and automatic
release require Ghana recipient fixtures and account acceptance. TZ data is not that evidence.

## Verification and delivery status

Current results and exact logs live in `output/manual-payment-automation-2026-10-30/` and the
canonical task record. Browser fixtures use a separate Vite port and intercepted synthetic
API data, with no Laravel database or payment-provider access. MySQL checks use a fresh
disposable local instance/socket; SQLite checks use `:memory:`. Neither uses production.

Build output is isolated under that output directory. The pre-existing `public/build/`
changes were preserved. Before a later authorized release, compile an isolated, reviewed
source manifest into the matching production build directory; do not ship the dirty checkout.
The retained-evidence migration deliberately refuses destructive `down()`; rollback disables
gates and retains financial history. Backup/restore and migration acceptance must be proven
on a nonproduction copy before any later real deployment.

No commit, push, deployment, real payment/subscription mutation, outgoing SMS or phone
installation was performed by this implementation checkpoint.


Final local checkpoint evidence: the full MySQL collection run passed36tests/400assertions,
with a subsequent3test/40assertion scope regression after linked-subsidiary privacy was
tightened. The51scoped legacy regressions passed410assertions; one separately recorded
bundle-rollback baseline failure reproduces against pre-task service sources. Browser,
Pint, PHP syntax and the isolated build passed. See the canonical task for exact run
boundaries and retained artifacts; these are local tests, not acceptance of the handsets
or production WordPress endpoint. The source manifest records task inputs, not a clean
release manifest. The [Android preparation guide](manual-payment-android-setup.md) is ready.
