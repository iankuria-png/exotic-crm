# Link an existing temporary-code subscription · 12 November 2026

## Request and authority

Ian asked for a clean CS action after the wrong automatic activation is removed: identify a `/1` temporary-code activation and link its existing subscription to the correct received code for easier reconciliation. This continues the authorized CRM shipping workstream; Ian deploys. No Codex production deployment, live linking, provider action, WordPress mutation or customer message is authorized or performed by this implementation.

Implementation uses the previously authorized `/Users/ian/Projects/exotic-crm-tz-autonomy-20261110` checkout on d5653a16. Shared dirty main and pre-existing state changes remain preserved.

## Current incident evidence

Fleet Ops f8e6/fa2e/2c02/c4b8 used registered `crm-receipt326-correction-read`, native SELECT-only CRM and physical WordPress reads. Live source d5653a16 and receipt326 version6/allocation version3 confirmed. Ian/CS completed the original undo at12Nov11:03EAT: correction977c57f7 is verified; didah's prior inactive/expired metadata and private Tanzania preimage are restored, both source effects superseded and site holds released. The original TZS35,000 payment42594 is unassigned. Sabrina remains active on payment42603/deal37267 using `DKC633H05T /1`, with unchanged19Nov23:59EAT expiry.

Actual legacy facts matter:42603 has source=gateway, null provider key/environment, staff confirmer Joanne18 and deal.origin=manual. It has product187/price256, consistent with its existing deal; it differs from the old automatic package26/143. Its payment user_id/escort_post_id are NULL while its CRM client and native profile are correctly identified. No authenticated provider observation or billing transaction exists. Original42594 has no recovery case;42603 has a closed fulfilled case. No manual proof submission, bundle or reference_sequence exists for either record.

Private originals/reads: `/Users/ian/Projects/exotic-ops/var/wrong-profile-correction-2026-11-12/`. The new flow verifies live state again when CS reviews/confirms; these reads do not authorize a live link by Codex.

## Resulting flow

The warning and disabled assignment control become a compact “Link an existing subscription” card. Show profile, recorder, amount/expiry and recorded/received codes. “Link Sabrina’s subscription” checks the current website and opens one inline review; “Confirm link” applies the reviewed link. No new purchase authority or service activation is requested.

Keep existing manual payment42603 and its deal/proof/commissions/activation event as canonical. Replace its current three reference fields with the received code, fill only missing native-verified WordPress identity fields and mark its reconciliation resolved. Refuse conflicting populated identity fields. Preserve its existing amount, currency, completion time and Joanne’s original activation attribution. Mark unassigned42594 canceled with the distinct audited duplicate_receipt_link disposition, keeping its amount, old references, original operation and historical effects. No row is deleted.

Reuse collection_corrections action=link_existing as persisted preview/audit/request owner. The new service/routes use native WordPress GETs only, exact state pins, account/lifecycle ownership and one short atomic transaction. The allocation moves to the canonical payment and a new local verification-only operation/effect, is reviewed, and releases only review exposure. Statement-verification exposure remains unchanged. Source/selected open recovery cases or disputes, provider settlement evidence, split/shared payments, multiple/truncated related candidates, changed service or another owner hold linking. A future quote alone does not block reconciliation; another profile hold does.

A completed request replays the same verified result. Generic correction execute/resume reject link_existing. Both managed rows are protected by persisted link metadata before a purpose-change bypass; metadata removal plus reopening is refused. The old subsidiary source remains superseded and cannot create more exposure. The presenter suppresses its obsolete summary and uses the preserved activation event/time; linking actor/time are separate.

Statement, daily-review and collection cash matching exclude only duplicate_receipt_link rows. Historical records remain readable/noncash; new statement previews resolve the original code once to the canonical payment. Previously uploaded batches remain historical evidence. No refund, extra days, extra receipt or new grant is created.

## Verification

- Full focused PHP8.2 / isolated MySQL suite:59tests /988assertions PASS, covering new linking, correction/access/manual-sale regressions, statement matching and daily review.
- Final link suite after adding the future-quote/other-owner regression:12tests /305assertions PASS. This includes missing legacy WP IDs, actual manual provenance, native-only readback, preserved deal/expiry/actor, one successful cash row, request replay/conflict, rollback/stale state, source/target recovery holds, metadata/purpose tampering, provider/split/amount refusal and cross-market access.
- Browser CLI:36 linking checks +10 existing correction checks PASS. Desktop1440/mobile390 suggestion, review and completed screenshots retained and visually inspected. Covers lost response after commit with the exact key, no activation POST, persisted reload result, meaningful hold/back state, keyboard and no overflow.
- PHP syntax:13paths PASS. Pint:11 scoped paths PASS. Existing formatting in Payment.php and PaymentReconciliationAuditService.php preserved with minimal changes; both syntax checked.
- Vite production build PASS (existing large-chunk advisory). app-B00nwX5g.js, unchanged app-DUB2ICOD.css, manifest verified; historical tracked bundles restored. No migration, configuration, credential or WordPress plugin change.
- Plan audit round1 NOT READY addressed statement duplicate matching, recovery cases, actual manual provenance and persisted guards; fresh round2 and implementation review READY. Plan/source: plans/link-existing-subscription-2026-11-12/plan.mdx + generated HTML.

Private local test/build/browser logs: output/subscription-link-2026-11-12/; screenshots: output/playwright/subscription-link-*.png. All browser/API evidence is labelled fixture execution; actual live CS confirmation remains pending.

## Delivery and next action

Source/build90c52338701fa9630f6f1c3d88918c243616f8c7 is PUSHED to origin/main and remote SHA verified. Ian deploys; CS confirms the live link. No live linking has been performed by Codex.

After the normal cPanel pull:

```bash
cd ~/crm.exotic-online.com
php artisan optimize:clear
php artisan queue:restart
```

No migrations, seeders or WordPress upload. Reopen receipt326, choose “Link Sabrina’s subscription”, review both codes and the unchanged expiry, then “Confirm link”. Independently verify canonical payment42603 uses the original code,42594 is retained as a canceled duplicate, receipt/queue show Sabrina and the original activator, one current successful payment is counted, native expiry/profile/paired state remain unchanged and fresh reconciliation matches once. Only then mark the live incident reconciled.
