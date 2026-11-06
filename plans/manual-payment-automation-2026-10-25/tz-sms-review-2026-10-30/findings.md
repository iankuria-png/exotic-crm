# TZ SMS dump review and autonomous activation design

30 October 2026 (EAT). Scope: inspect Ian's supplied SMS XML and compare receiving receipt references with live CRM. Read-only evidence analysis; no SMS imported into production, no subscription or payment changed, and no phone app installed. The intended product is **autonomous activation of clear cases followed by sales review and correction**. Shadow evaluation and assisted exceptions are validation and fallback paths, not the final default workflow.

## What the dump establishes

Source: `/Users/ian/Downloads/sms-20261030121225.xml`, SHA256 `c423dd1cdd03a420959bc06cc2d6aa156a9555e02ee3298aaca534b242ec4c57`. The XML declares 62,137 records: **61,544 SMS + 593 MMS**, reconciling exactly. SMS span 4 January 2024 to 30 October 2026, 12:08:12 EAT; export time is 12:12:25 EAT. MMS contents were not used. The dump includes personal/OTP/unrelated material; it stays local/private and is never sent to an external AI, public webhook tester or shareable artifact.

`M-PESA` is the displayed sender for 47,139 SMS. The exploratory parser identifies **46,323 incoming-credit notifications across four wording families**, representing **30,923 distinct full receipt references**. This, together with exact CRM links to TZ #6, supports Vodacom M-PESA as TZ's principal observed receiving rail. It does not independently authenticate the dump/provider or establish the legal owner or current SIM/account binding. Other brand names in the inbox are not proof of additional Exotic collection accounts.

| Incoming family | SMS count | Messages containing a full TZ payer phone | Redacted layout |
|---|---:|---:|---|
| English payment summary | 15,400 | 0 | `[REF] confirmed. You have received a payment of Tsh[AMOUNT] from [ROUTING CODE] - [BANK/CHANNEL] on [DATE] at [TIME]. New M-Pesa balance…` |
| English received | 11,343 | 11,209 | `[REF] Confirmed. You have received Tsh[AMOUNT] from [PAYER] on [DATE] at [TIME]…` |
| English date first | 5,934 | 0 | `[REF] Confirmed. On [DATE], [TIME] Receive Tsh[AMOUNT] from [NAME] and balance…` |
| Swahili received | 13,646 | 12,697 | `[REF] imethibitishwa. Umepokea Tshs [AMOUNT] kutoka [BANK/CHANNEL] : [PHONE] - [NAME] tarehe [DATE] saa [TIME].` |

These are faithful structural paraphrases with private values removed, not ready-made synthetic evidence or guaranteed provider contracts. Currency principal is distinct from account balance. Dates include 12-hour and 24-hour clocks, two/four-digit years and optional seconds. Receipt IDs in this corpus have 10 **or 11** alphanumeric characters, including letter-only codes; they must remain opaque strings. Do not force a ten-character/digits-required rule inherited from Kenya.

All 46,323 selected notifications have extractable receipt/amount/party/time under this exploratory parser. That is **format coverage on this selection**, not independently measured detection recall, a production parser certification or proof of funds. The sender/phrase selection excludes 296 completed-reversal notices, 422 reversal/hold-related notices, debit/airtime, balance/other messages and other senders. Reversal counts are notification counts, not distinct chargebacks. Detect false positives and missed templates on reviewed held-out fixtures before enabling a live parser.

## One receipt often has two complementary messages

There are **15,400 additional credit notifications beyond distinct receipt references**. Most repeated-reference groups pair an English merchant summary with a Swahili detailed credit (13,604 groups), or an English summary with an English detailed credit (1,722 groups); retransmissions also occur. These are generally complementary observations of one receiving transaction, not two cash events. Full payer numbers are available after combining observations for **23,890 of 30,923** references; they remain absent for 7,033 references.

No repeated-reference group disagrees on principal or on the full payer number when present. Provider times differ in precision and sometimes slightly in value. Forty-two historical groups differ by more than two minutes; they require review rather than silently treating all timestamp disagreement as rounding. There are no >2-minute provider-time spreads in the selected September–October window. This is an within-dump consistency check, not cross-account proof that every reference is globally unique.

In the selected recent window, 1,766 receipts have multiple messages. Their full first-to-last message spread has a median of 10.26 seconds and p95 of 20.09 seconds, with a long tail. A first summary can be followed by identifying details. The design should use a **bounded coalescing period of about 20–30 seconds** when identity is missing, then re-evaluate on new evidence. A complete direct credit with a valid intent/relationship can proceed immediately. A second message enriches the existing receipt; it never creates a second activation. Late conflicting evidence opens review rather than moving an already-live subscription silently.

## Comparison with live CRM

Registered SELECT-only CRM read on 30 October at 13:28 EAT: 8,891 payments, 9,006 deals and minimal current identity fields for 2,008 related clients. Platforms explicitly identified as TZ #6 (`exotic-tz.net`) and Tanzania #2 (`exotictanzania.com`). Payment rows are restricted to `2026-08-01 <= created_at < 2026-10-31`; deal extraction includes created/activated rows since August. These are private comparison inputs, not dashboard revenue populations. Production cached app timezone independently confirmed UTC at 13:32 EAT.

Receipt window: **1 September 2026 00:00 EAT through export time 30 October 12:12:25 EAT**, using the parsed provider transaction time. This is a partial period. Comparison matches a complete observed receipt token in `transaction_reference`, `reference_number` or `reference_root`; preserves the original strings; handles punctuation/legacy suffixes without fuzzy matching or arbitrary prefix truncation. A joined reference is still a claim in the CRM, not independent settlement or proven beneficiary intent.

| Mutually exclusive receipt category | Distinct references |
|---|---:|
| One positive, successful, not-explicitly-classified-test TZ payment with exact amount/currency | **3,018** |
| One such TZ payment but differing amount/currency | 135 |
| Multiple such TZ payments | 31 |
| Reference appears in scoped CRM rows, without such a primary TZ payment | 38 |
| No reference found in the scoped extracted CRM rows | 191 |
| **Total** | **3,413** |

The explicit test filter here is `record_classification != test` (or null). **Sandbox `provider_environment` and legacy `payment_data.test_mode` were not extracted or evaluated.** “Not explicitly classified test” therefore does not mean the full CRM `businessVisible()` or reportable/settled cohort, and unresolved manual-review rows can remain. This limitation applies to the reference comparison, phone/package analyses and historical replay below.

Thus 3,222/3,413 (94.4%) have some scoped CRM reference link; 3,018/3,413 (88.4%) have a single exact primary payment link. Neither percentage is autonomous-activation coverage or customer accuracy. Of the 31 multiple-primary groups, six have summed payment amounts equal to the observed receipt principal; they may reflect splits, while others need evidence. There are 25 receipt references with a completed-reversal notice in this recent window; the categories above are not a net-settled cohort and must not be used to grant subscriptions. A missing scoped match is **not proof of missing money**: extraction limits, historic rows, entry mistakes, reference aliases, other purposes and unrelated transfers can explain gaps.

Among the 3,018 exact single-primary links, **2,355 have a full SMS payer phone and a normalizable current advertised contact phone**. They agree on 873 and differ on **1,482 (62.9%)**. This compares the observed payer to the CRM contact at read time; historical contact changes may contribute. It supports a separate payer identity model and disproves treating advertiser phone as a universal payer key. `Payment.phone` itself may contain manually entered/contact data and must not be treated as provider-observed identity.

The same TZS 35,000 cash value has 2,569 exact single-primary links. Linked deals include **2,418 VIP/7-day**, **102 VIP/8-day**, **22 VIP/9-day**, other durations and 17 missing duration-day values; four have no linked deal. None of these facts proves which future duration a customer wants. Current active catalogue lookup finds multiple price rows for 2,569 of all 3,018 exact links, one row for 266, and none for 183. This is explicitly comparison against today's catalogue, not a reconstruction of what was available when each payment arrived. Historical package IDs are not stable semantic package identities: compare actual tier, duration, quoted amount and authority, not only IDs or names.

There are 135 exact-reference single-primary differences: examples include a TZS 30,000 receiving receipt versus a CRM TZS 35,000 payment row. Treat them as **receipt-to-ledger discrepancies** until evidence explains discount, part payment, split, alias or entry error; do not overwrite history or assert fraud/loss. New cash records must keep the actual received principal while a negotiated invoice amount/discount remains a separate authorized fact.

## Historical replay: useful evidence, not a safety percentage

To avoid training on the current outcome, a rolling comparison begins with August receipts and predicts only September–October candidates using **earlier SMS, full-payer-evidence availability and CRM creation/activation timestamps**. It uses only single exact positive CRM-linked outcomes not explicitly classified test or marked reversed/invalid with a known client and full observed payer phone. These are still present-day retained CRM links, not independently adjudicated true intent; revisions, corrections and deletions are not reconstructed as of each event. Completed-reversal references are excluded consistently from both training and evaluation. These exclusions are retrospective for analytical hygiene, not a claim those future reversals could have been known at receipt time.

- Evaluation labels: 2,360 receipts. 1,763 have some prior payer history; 317 have prior history across multiple profiles.
- A deliberately simple rule—at least three earlier payments, all linked to one profile—generates 671 profile candidates. It agrees with the recorded CRM client on 632 and disagrees on **39**. These disagreements may be genuine switching/shared payers, historical misassignment or other data issues. Do not label 632/671 as true accuracy or enable that rule alone.
- Within those simple-rule candidates, 534 have one previously observed same-amount tier/duration/price pattern and a recorded comparison deal; it agrees with the recorded current package semantics on 509. This package comparison is conditional, not a combined customer-and-package success count. Even a repeated amount and package can change, and identity may independently disagree.
- Across eligible observed training links, **189 of 1,356 payer identities** connect to more than one profile. Counts are bounded by the extracted windows and retained links, not the entire Exotic payer population.

This is why a high-confidence autonomous decision must combine a reviewed payer/profile relationship, current intent or standing authorized renewal agreement, eligible package/duration/price, consistent receipt evidence and duplicate/entitlement checks. History can propose those relationships and package agreements for one-time/bulk confirmation. It must not teach itself that every prior CRM assignment or unmarked discount was correct.

## Stored timestamps suggest a meaningful delay, with limits

For 2,928 exact single-primary linked deals with nonnegative stored activation lag under seven days, the median difference between `deals.activated_at` and the first handset SMS receive time is **53.52 minutes**; p90 is 248.89 and p95 479.66 minutes. Eighty-five linked activations precede the SMS, and one exceeds seven days; those are excluded from this conditional summary. Import/recovery timestamps and SMS delay can affect the result. It is **not verified first-live customer latency**, not the whole payment population, and not a causal estimate of automation savings. The pilot should measure provider event time, first SMS, CRM ingest, decision and actual verified WP entitlement separately.

## Updated autonomous experience

1. **Receive and consolidate:** authenticate the registered office device/SIM/account, persist each message and combine same-reference observations. Deduplicate again against existing CRM payment/root/bundle and fulfillment operation records. An XML historical import is learning/reconciliation only.
2. **Identify the profile:** prefer an owned current purchase intent. Otherwise use staff-confirmed payer identities and a current approved recurring purchase agreement, with unique-profile evidence and no conflicting claim. Same-phone or history-only similarity is a candidate signal; shared payer or new profile choice becomes an immediate exception.
3. **Identify the package and final agreed price:** reuse a live valid client-bound deal/quote or an explicit standing renewal agreement. Use recent subscriptions and sales-created prices as contextual candidates; a new discount or changed duration requires authority. An exact amount is not a subscription tier.
4. **Activate autonomously:** when all four dimensions—funds evidence, beneficiary identity, purchase authority and safe entitlement effect—meet the configured policy, create/reuse the paid deal and apply the persistent WordPress operation without waiting for sales. Carry out the linked free Tanzania effect for the same purchased interval, with independently recoverable status. A receipt arriving after an already-live purchase verifies/reconciles; it does not extend again.
5. **Review after activation:** show `Auto-activated · Review needed`, the observed payer, profile, package/duration, price authority, matching reasons and primary/subsidiary outcome. Sales can confirm, change package, undo only this effect or reassign from Payments and Client Detail. Corrections preserve cash, audit and unrelated paid time.
6. **Reconcile exceptions:** ambiguous/missing identity, shared payer, changed amount/package, receipt/CRM mismatch, reversal, exceeded exposure budget, inactive/removed profile or uncertain WordPress outcome goes to a prefilled case. The account mode remains autonomous for eligible cases; an exception does not switch the whole fleet to manual.

This can support first-time explicit-intent payments and repeat renewals; it does not require sales to create a fresh subscription before each autonomous payment. For repeat behavior, staff establish or confirm the payer/standing purchase authority once; later clear receipts use it until changed, expired or revoked. That authority includes market, exact profile, product/tier, duration, negotiated price, currency, permitted stacking and renewal conditions. Newly inferred or altered agreements remain review-required.

## Required implementation changes from this evidence

- Add a canonical receipt observation layer with complementary-message merge, raw identifier preservation, amount/phone/time conflicts and per-field provenance. Payer information learned from the second SMS must be available to the decision engine without another cash event.
- Add a short bounded enrichment wait when needed; do not wait indefinitely or use SMS count as payment count. Persist pending decisions and re-evaluate when details arrive; dispatch once.
- Use deterministic reviewed parser adapters for the four observed layouts; split reversals/holds/airtime/balance/OTP/marketing. Preserve 10/11-character receipts, optional seconds, TZS minor units and the relevant provider account scope. Keep unreviewed unusual formats quarantined.
- Stage historical payer/price relationships for adjudication. Build time-separated shadow evaluation with frozen rules and reviewed intent outcomes; do not train and score on the same receipts, or use future reversals/current catalogue as prospective features.
- Add a **cutover boundary**: all messages in this supplied dump are historical-only, default no entitlement dispatch. Establish live device registration, server cutover time, a durable inbox cursor and a duplicate-safe overlap window. A pre-cutover transaction delivered after cutover stays historical/reconciliation-only unless separately reviewed. Only proven new live events enter automatic decision policy; never bulk activate old unmatched receipts.
- Reuse the newer CRM `PaymentFulfillmentOperation` / `PaymentFulfillmentRecoveryService` / `WpSyncService::subscriptionOperation()` contract where verified; extend it for SMS allocations, autonomous purchase decisions, subsidiary effects and corrections rather than introducing a competing operation ledger. A new correction/dependent child-operation relation must preserve the existing per-payment recovery uniqueness and ownership contract. Do not use the recovery service unchanged for new SMS sales.
- Live CRM metadata confirms the fulfillment table exists and its automatic platform gate is empty. The separate payment-attribution task records incomplete target WP all-writer acceptance. Neither schema presence nor an installed plugin on another market enables TZ. Confirm exact target plugin files, capabilities and all writers; local plugin includes reference operation classes absent from the current main tree, while reviewed classes exist in the prior release artifacts. Reconcile the approved complete manifest before any release. No missing-file repair is performed by this research.

## Checks and reproducibility

Analysis source scripts: `research/manual-payment-automation/analyze_tz_sms.py`, `compare_tz_crm.py` and `check_tz_analysis.py` in the CRM repository. SQL is retained in Ops `remote/php/crm-tz-sms-research-read.php`; read-only registry entry `crm-tz-sms-research-read`. Live reads: `20261030T102814Z-d4792-d9410-read-crm-tz-sms-research-read-ce03` and metadata-only `20261030T103249Z-d4792-d9410-read-crm-tz-sms-research-read-a397`.

Independent XML phrase/first-token counts reproduce 46,323 notifications and 30,923 references; an independently expressed exact-token join reproduces 3,018 single exact primary links. Four synthetic template fixtures and negative sender/direction/reversal cases pass. These are analytical checks, not live ingest, phone-reliability, WordPress activation or customer-intent acceptance tests. Redacted summary/comparison/validation JSONs accompany this report; raw XML and private CRM/customer/receipt rows remain outside the shareable plan folder.

## Independent revision audit

30October round1: NOTREADY/two SHOULD-FIX—exclude completed-reversal references from training as described, and qualify the explicit-test-only cohort because sandbox/test-mode flags were not extracted. Corrected both; regenerated comparisons and dependent counts (prior history1,763; sharedpayers189/1,356; headline671/632/39 unchanged). Also made payer identity availability explicit before using a historical observation. Phase5 wording now extends the existing operation ledger. 30October round2: READY; no BLOCKER or SHOULD-FIX. The client-page manual activation wireframe is explicitly labelled an exception; autonomous clear cases are active awaiting review.
