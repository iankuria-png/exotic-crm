# Payment correction recovery · 12 November 2026

## Request and authority

Ian reported a wrong automatic activation, followed by Joanne's manual activation of the intended profile while the wrong service remained active. He requested Fleet Ops investigation and a simpler customer-service correction flow. He then instructed: “we should work to get the simpler correct ui shipped - i will deploy on the crm”. This authorizes the scoped CRM commit/push; Ian owns deployment. No live business-data mutation, payment reconciliation, customer message or WordPress upload is part of this release.

Use the previously authorized `/Users/ian/Projects/exotic-crm-tz-autonomy-20261110` checkout (detached e1112734 base). Its application files matched current production; dirty shared main and pre-existing state edits remain preserved.

## Verified incident

Receipt326, DKC633H05T, TZS35,000, paid12Nov07:30EAT. Automatic payment42594/deal37260 activated didah (CRM2421856/platform6/WP89204/user17774) at07:31EAT; its independent Tanzania exposure (CRM4448225/platform2/WP71944/user13734/deal37261) succeeded07:32EAT. Joanne Yengo manually activated Sabrina (CRM3699561/platform6/WP95774/user18660/payment42603/deal37267) at09:15EAT with `DKC633H05T /1`. Two completed positive payment rows refer to this apparent single cash receipt; separate reviewed reconciliation remains required.

Joanne started undo977c57f7-89c4-413a-822c-6b64859e6a82 at09:18EAT. Primary compensation7f3d52e0-cc0e-45bc-b408-61fef44c0186 is effect_unknown, subsequent retry says operation_result_unavailable; linked compensationca253688-4eaf-4bd0-b1a5-bf43c2e01b38 remains prepared. Allocationde10e116-4d4d-4fe7-b79e-d211652fdd71 is correcting. The later blocked preview is not the reserved correction.

Root cause: CollectionCorrectionService::sourceStep normalizes the original missing/empty expiry string to `"0"`, changing the immutable restore snapshot. WordPress v2 validate_compensation correctly rejects the mismatch. The shared test fake previously accepted such mismatches, hiding the defect.

Fleet Ops SELECT-only reads: e631 financial/staff evidence; cf01 native WordPress state and saved-operation evidence. Live CRM HEAD e1112734 and eight runtime hashes match the authorized checkout, superseding yesterday's stale NOT LIVE task note. Actual native sites confirm didah, its paired profile and Sabrina active; both compensation operations absent; original v2 operations and exact preimages present. Six proof checks for each actual malformed payload pass at cf01's recorded time. No HTTP mutation or business write occurred.

Private evidence: `/Users/ian/Projects/exotic-ops/var/wrong-profile-correction-2026-11-12/`; registered check `crm-receipt326-correction-read`. Initial8957 refused on an absent diagnostic column; corrected read e631/cf01 succeeded. Local sanitized proof assessment and test/browser/build logs: checkout `output/payment-correction-recovery-2026-11-12/`.

## Change

- Preserve the exact immutable restoration preimage, including empty strings and metadata presence.
- Recover only the provably invalid old-normalization source-compensation payload, after checking native absent compensation result, immutable v2 source tuple/preimage, unchanged current snapshot and existing correction ownership. Only restore_snapshot changes; the same effect operation ID and saved correction request key remain. Valid uncertain operations retain readback/review and are never blindly replayed. Existing owner/gate/lock/audit protections remain.
- Show the reserved correction above normal actions with one “Check saved correction” action. Use its actual undo/reassignment mode; retain the same request key. Show paid and paired step progress in plain language. Polling updates mounted forms and completion remains visible; manual-sale corrections also retain completion feedback.
- Show related successful payment records, including exact-reference extras and numeric `/n` copies, within the receipt account's accessible markets. Bounded/truncated evidence holds assignment; no reference normalization, merge or cash mutation. Guard preview, new execute and resumed new-target dispatch. Already committed targets retain readback/finalization. Undo can complete without altering Sabrina's separate service or payment.
- Keep historical tracked assets so existing open browser tabs can still load them. Include the new matching JS/CSS and manifest.

Plan: `plans/payment-correction-recovery-2026-11-12/plan.mdx` and generated HTML. Independent plan audit round1 NOT READY fixed resumed-dispatch bypass, truncated coverage and duplicate-aware completion. Fresh round2 READY; final implementation check READY. Ian's explicit ship instruction carries through implementation without a new permission gate.

## Verification and delivery

PHP8.2 focused MySQL suite PASS:33tests/616assertions (correction review, direct form/access, manual sales and paired automation). The WordPress fake now enforces immutable compensation preimages and saved-operation payloads. Browser CLI PASS:10 existing correction checks +25 recovery/polling/duplicate/mobile/keyboard checks. Retained desktop and390px screenshots visually inspected. PHP syntax, Pint, diff checks and production Vite build PASS. The existing large-chunk advisory remains; no build failure. Production assets: app-CQaFIekW.js and app-DUB2ICOD.css. No schema, configuration, credential, callback, public URL or WordPress plugin change. Existing Ads/STK behavior remains untouched.

Delivery status: source/build97662a1cfec9a1506795a26eeb58cfd34e402ada PUSHED to origin/main and remote SHA verified. This handoff records the result; Ian deploys. Production remains unchanged by this session. No customer-data repair has been applied.

## Ian deployment

Use the normal CRM cPanel Git pull from origin/main, or run:

```bash
cd ~/crm.exotic-online.com
git pull --ff-only origin main
php artisan optimize:clear
php artisan queue:restart
```

No migrations, seeders or WordPress upload are needed. Use the normal serving-PHP/FPM refresh mechanism if cached code remains; a CLI cache probe does not prove web runtime refresh. Do not replay yesterday's failed maintenance/deployment adapter.

After deployment, reopen `/payments?workspace=collections&receipt=326`. The drawer should show the saved removal and Sabrina's related record. “Check saved correction” resumes the original undo. Success must confirm both original services removed and leave funds awaiting reconciliation, while Sabrina's existing expiry/service remains unchanged. The two positive payment records need a separate reviewed reconciliation; the code release does not silently merge them. Independently verify native WordPress, current drawer state and actual served assets before marking this incident resolved.
