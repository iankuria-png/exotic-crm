# TZ M-PESA "Name not supplied" despite a name in the SMS (18 Nov 2026)

## Root cause
`TzMpesaParser::payerDetails()` stripped the account prefix only when the SMS carried a TZ payer phone.
Bank/wallet detail messages without one (`kutoka CRDB Bank, Akaunti ****3367 - FULL NAME`,
`MIXX BY YAS : <11-digit wallet> - NAME`) kept the whole party string as the "name", which failed the
letters-only/no-bank-word check and became null. The UI then showed "Name not supplied in SMS".

Cross-network/bank payments arrive as two SMS per receipt: a switch header `<6-digit code> - TIPS-<network>`
(an institution, 2 distinct values across 501 events) plus the detail message. A naive "take text after the last
` - `" rule turned the header into a name and produced 356 conflicting-name receipts, so the split is limited to
bank/account descriptors and `Network : number` wallets. `tips|selcom|halopesa|mixx` were added to the rejected
institution words.

## Change (pushed to origin main)
- `app/Services/Collections/TzMpesaParser.php` — the `payerDetails` branch for accounts without a phone.
- `tests/Feature/Collections/CollectionReviewContextTest.php` — Swahili/English bank detail, bank holder,
  TIPS headers and wallet cases.
- Display is computed at read time from stored events (`CollectionPayerEvidenceService`), so there is **no
  backfill or migration**: deployed code fixes historical receipts too. Names do not drive automatic matching
  scores. They are used for display, claim-check name suggestions and the standing-agreement `complete` gate.

## Evidence
- Isolated clean-HEAD copy: 31 tests / 304 assertions PASS (Review context, Capture, Wallet balance, Ghana).
  Main checkout has unrelated in-flight `app/Models/Payment.php` edits removing `withoutReceiptDuplicates()`,
  which makes the receipts API 500 there. That is not caused by this change.
- Prod SELECT-only `exotic-ops` check `crm-tz-payer-name-read` (run c6a0, candidate sha 31a79cb1…):
  775 receipts. Deployed parser misses 67, candidate recovers 43 (29 in the last 7 days), 0 new conflicts,
  0 per-event regressions, 0 recovered names with business words.
- 24 remain unnamed with no person name available: 8 merchant `… STORE <n>`, 2 bank-only senders, 14 where only
  the TIPS header SMS was captured (the detail SMS never reached the phone).

## Next
Ian: cPanel pull, then open a TZ bank-transfer receipt (e.g. DKIIP3J6SH) and confirm the payer name shows. No build, migration or cache step beyond the normal pull.
