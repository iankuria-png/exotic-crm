# Uganda MTN MoMoPay SMS capture — discovery, 25 November 2026

Status: DISCOVERY COMPLETE / nothing built or changed. Phone paired read-only; production read SELECT-only.
Goal: connect the Uganda office phone to CRM Incoming office payments the same way as TZ
(`tz-sms-reliability-2026-11-09.md`) and Ghana (`ghana-sms-capture-2026-11-18.md`).
Private evidence (customer names/phones): `/Users/ian/Projects/exotic-ops/var/uganda-sms-2026-11-25/` (gitignored).

## Phone access
Ian supplied wireless debugging 192.168.5.10 (connect 32857, pairing 40979 + code, not retained). Paired and
connected from the office Mac. Device: Chinoe A70 Pro, Android 11 (SDK 30), two MTN Uganda SIMs roaming on
Safaricom. No capture app installed yet. Battery 23% and unplugged at check time (needs permanent charging).
Read-only `content query content://sms`: 15,876 SMS, 19 Sep 2023 → 25 Nov 2026; sha256 2101c98f…08477b.

## SMS findings
- Sender `MTNMobMoney` (14,246). All business receipts are on SIM slot/sub 1. Account name
  "EXOTIC ADVERTISING SMC LTD", MoMoPay **Till 632555** (only till seen).
- Every Till payment produces **two SMS with the same 11-digit Transaction ID** (407/407 paired in last 120 days).
  Merge them by ID; never count twice.
  - Wallet view: `You have received UGX 50000 from NAME at 2026-11-24 17:04:16.  Fee: 500. Message: Till:632555.
    New balance is: UGX 900680. Transaction ID: 45331931063. …` (time, fee, no phone)
  - MoMoPay view: `You have received UGX 50,000 from (NAME) 2567XXXXXXXX. Your new MoMoPay balance: 900680.46.
    Transaction ID: 45331931063. …` (payer phone, no time)
  - Before 6 Aug 2025 the same pair used older wording (`MoMoPayAccount balance … ID:` and
    `on your mobile money account at … Financial Transaction Id:`); history import must accept both.
- Person-to-person credits on the same SIM: `You have received UGX X from NAME, 2567… on <time>. fee:0. Reason: …
  New balance: UGX … ID: …` — a separate personal wallet balance; 110 of 112 since 1 Aug are not used by CRM.
- Reversals: `The transaction from NAME (2567…) with X UGX has been reversed at … Financial Transaction Id: …`
  (8 ever). Also `NAME, 2567… has initiated a reversal of X wrongly sent to you. Please approve…` requests.
- Must reject: sent money, airtime, withdrawals, RELWORX/QUIDEX approvals, failed-send notices, promos.

## CRM baseline (Ops read `crm-uganda-sms-match-read`, run 20261125T081003Z-…-9991, SELECT-only)
- Platforms: 5 Uganda `exoticuganda.com` (nearly all money), 16 UG `exoticug.com` (10 small payments).
- No Uganda manual payment method, no manual submissions, no collection account/device. Collection accounts today:
  TZ (auto), Ghana (auto, automatic activation off), Kenya KopoKopo till (assisted, no phone).
- Since 1 Aug: 410 completed staff-recorded payments on platform 5 (88 are UGX 0 FREE-TRIAL/SEO-BOOST);
  of 322 paid, **301 (93%) carry an exact Till transaction ID found on the phone**. 21 paid without an exact
  receipt (3 look like one-digit typos), 8 short-paid, 2 IDs used twice. 99 Till receipts on the phone are not
  linked to any CRM payment (mostly UGX 50k/60k). Main recorders: Florence 211, Zeddy 75, Victor 38.
  PawaPay separately completes ~247 payments (web/self checkout) — unaffected.
- UGX prices include 50k/60k/70k/96k/190k/192k tiers.

## What Uganda needs (mirrors Ghana)
1. CRM: `UgMtnMomoPayParser` (sender `MTNMobMoney`, provider e.g. `mtn_momopay_ug`, UGX, Africa/Kampala),
   pair-merge by transaction ID, old+new templates, reversal/reversal-request handling, drift suspension;
   register in `CollectionParserRegistry`, Uganda phone normalisation (256…), default gates config + tests.
2. Phone: `PaymentOnlyPolicy` for UG (`prepare.py --country UG`), build/sign APK, install, battery Unrestricted.
3. WP: check exoticuganda.com for the receipt contract (Ghana needed a rollout) before Match & activate writes expiry.
4. Enroll (automation OFF), import 90-day Till history, signed webhooks, live receipt + CS acceptance.

## Open decisions for Ian
- Platform 5 only, or 5 + 16?
- Till receipts only (recommended), or also the personal-wallet person-to-person credits?

## Ops changes (local, uncommitted)
`remote/php/crm-uganda-sms-match-read.php` + `remote/checks.json` entry `crm-uganda-sms-match-read`.

## Build — 25 Nov (Ian: "Yes, build it" = platform 5, Till receipts only)
CRM (local, uncommitted):
- New `app/Services/Collections/UgMtnMomoPayParser.php` (`ug-mtn-momopay-v1`, sender `MTNMobMoney`, provider
  `mtn_momopay_ug`, UGX, Africa/Kampala). Reads both paired views + the pre-Aug-2025 pair; personal-wallet credits/
  deposits → explicit `personal_wallet_*` (never drift); completed reversals/reversal requests → unattached hold flag.
  Uganda numbers `2567x`/`25639x`.
- `CollectionParserRegistry` (provider, sender, UG phone, `anyPhone` 256), `CollectionReceiptService` (UG drift check;
  MoMoPay view's receive time decides live vs historical only — without it a view arriving first, ~25% of payments,
  would have made a live receipt permanently historical), `EnrollCollectionDevice` (UG / `exotic-ug-v1`),
  `config/collection.php` parser version. Uganda NOT added to entitlement/writer/correction/automatic gates.
- Test `tests/Feature/Collections/UgandaMomoPayCaptureTest.php` (5).
- Known edge: a payment whose wallet-view SMS never arrives has no provider time (3 in the last year, none recent);
  it shows as a receipt but cannot pass the automatic gate.

Phone (local, uncommitted): `tools/collection-phone/src/ug/PaymentOnlyPolicy.java` (`exotic-ug-v1`), `tests/ug/*`
(41 synthetic), `prepare.py --country UG`, `verify_on_device.py` UG case count. Reversal requests contain "enter your
PIN" and are rejected on the phone by the secret rule (completed reversals are forwarded).

Verification:
- Real inbox replay (local, private): PHP parser 13,912/13,912 Till credits, 6,893 paired IDs with 0 amount mismatches,
  every pair has phone + provider time, 0 drift. Java filter agrees on all 15,876 messages (credits FINANCIAL, personal/
  outgoing REJECT, 0 DRIFT).
- Clean `git archive HEAD` + these files only: 55 tests / 500 assertions PASS (Uganda, Ghana, capture, reversal flag,
  epoch, durability, market scope, settlement enrollment, review context, wallet balance, default gates). Shared
  checkout's Ghana claim/guard tests fail on the known stale `Payment.php` overlay, not on this change. Pint PASS.
- JVM synthetic: UG 41/41, GH 37/37 unchanged.

SIM: Till receipts arrive on Android subscription 1 (slot index 1); the number comes from its user-set display name
(private `sim-private.json`) and needs Ian's confirmation. Battery was 19% and unplugged.
Ops: `remote/sh/crm-uganda-collection-enroll.sh` prepared (not registered/run); Uganda account id reserved in private
`account-id.txt`.

APK: `1.78.1-exotic.3-ug` (country UG) sha256 427374c21c8077615587084d378e8e5fab3bdb02c67068c6274228f0e27eafeb, same
office signer as TZ/Ghana, built from pinned source in `~/Library/Application Support/Exotic/AndroidTools/
filtered-build-20261125-ug/`. Installed on the Chinoe A70 Pro; on-device 41/41 installed-filter cases, no SEND_SMS,
non-debuggable, no SMS read or injected (`on-device.json`).

Checkpoint 25 Nov ~11:45 EAT: wireless debugging then closed (phone still on Wi-Fi, port refused). App is installed but
NOT yet granted permissions, battery-exempted, bound or cursor-initialized; no webhooks. Next on phone access: grant
SMS/phone permissions, deviceidle whitelist + background allow, enable local API, read credentials privately, bind
subscription 1 + confirmed recipient + carrier + cursor (`binding_configured`, `cursor_initialized`).
After Ian authorizes shipping and pulls: preview/apply `crm-uganda-collection-enroll` (automation OFF), import 90-day
history, three signed hooks, live receipt check. Later phase: exoticuganda.com WP receipt contract + platform 5 gates.

### Phone bound — 25 Nov ~12:00 EAT (Ian supplied wireless debugging 192.168.5.10:36805)
- Granted RECEIVE/READ_SMS, READ_PHONE_STATE, READ_PHONE_NUMBERS; deviceidle whitelist, RUN_ANY_IN_BACKGROUND allow,
  standby bucket 5. App UI: Local server ON, Cloud OFF, Start on boot ON, service started. Local API credentials saved
  owner-only (`local-credentials-private.json`); the password was shown once in tool output (phone-local API only).
- `/device`: Chinoe/A70_Pro, device id matches; Till SIM = subscriptionId 1, app simNumber 2, slotIndex 1, carrier
  "Safaricom" (roaming; a move to a Ugandan network changes the carrier and needs re-enrollment). Other SIM: sub 2, No service.
- Binding PATCHed: subscription 1, recipient from SIM label (…84, Ian to confirm), carrier Safaricom, sim 2, cursor 16834.
  Readback: binding_configured, cursor_initialized, exotic-ug-v1, no drift, 0 hooks (`binding-private.json`).
- ADB forward removed. Phone charging (7% at bind time), app running.
Next: Ian ship authorization → push → cPanel pull + config:cache → refresh history export → enroll preview/apply
(automation OFF, sim_number 2, android_subscription_id 1, filter exotic-ug-v1, carrier Safaricom) → three signed hooks.
