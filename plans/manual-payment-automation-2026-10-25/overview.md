# Exotic manual-payment automation: recommended direction

Research updated 30 October 2026 with the supplied TZ SMS XML and fresh live CRM comparison. This is a proposed implementation, with read-only production investigation and local planning artifacts. No collector app was installed and no payment, subscription, configuration, deployment or outgoing message was changed.

## The recommendation

Connect the two existing **office Android phones for Ghana and TZ** to a private receiving-event endpoint, then give CRM a receipt ledger and a purchase decision engine. The default target is **autonomous payment→customer/package identification→activation**, followed by a sales review queue. Clear cases do not wait for a sales activation click. Shadow evaluation is a proving stage, and ambiguous cases get prefilled exceptions. Sales can confirm, change the package, move the payment to another profile or undo only that activation from **Payments or Client Detail**.

Use provider feeds where the actual receiving account supports them; SMS capture is the bridge for accounts that currently depend on a handset. Trial a provider-neutral Android bridge before committing to native app development. [SMSGate](https://docs.sms-gate.app/getting-started/) is the recommended trial. [PesaCast](https://github.com/davidamunga/pesacast) now supports direct server webhooks and is a useful Kenya-specific alternative; it does not require a desktop for that path. [SMS Enabler](https://smsenabler.com/) is a modem/computer fallback if the office-phone trial is unreliable.

## What Exotic's evidence says

The native CRM dashboard reports **90.8% of revenue collected manually** for 26 September–25 October: approximately USD 54,462 of USD 59,951. Ghana and TZ account for about **62.8% of manual value**; including Senegal and Kenya brings it to about 78.7%. Some reporting FX metadata is stale, so use these as prioritization figures. The dashboard's 5,039 manual rows are payment records, not established distinct paid transfers; a separate created-date sample shows zero-value/noncash rows.

Live configuration confirms Ghana MTN. It separately records Tanzania #2 Vodacom, Nigeria UBA, Ethiopia Awash and Zimbabwe EcoCash. The supplied TZ #6 dump now identifies M-PESA receiving layouts and thousands of exact primary-site CRM receipt links; verify the current physical SIM/account/ownership mapping during enrollment. Ian confirmed TZ is the paid primary and Tanzania is its free same-period exposure subsidiary: automate one paid economic event with a linked free benefit, preserving both profile identities.

I inspected five actual CRM proof uploads. They include an MTN sender message, an OPay transfer **into UBA**, a CRDB transfer **into Vodacom**, a cropped remittance summary and an EcoCash **USSD pop-up**. The receiving operator's reference can differ from the sender's reference; a screenshot or a USSD response is not necessarily an SMS the phone bridge can read. The message atlas records these distinctions without publishing customer data.

Purchase history confirms the human variation. Sample Ghana profiles have 14-day Premium deals at GHS 220 and 230; TZ histories have 7-day VIP at TZS 30,000 and 35,000. The live TZ catalogue contains a standard 7-day VIP price and sales-created 7-day and 8-day prices at TZS 35,000. Several deal discount fields are empty. These are bounded, unclassified historical samples, not proof of current negotiated-price authority or a representative discount rate.

## New evidence from the TZ phone

The dump has **61,544 SMS + 593 MMS**, reconciling the declared 62,137 records. Four English/Swahili incoming-credit layouts produce **46,323 notifications for 30,923 distinct references**. A merchant summary and a detailed payer message often share a reference; combine them into one receipt and one activation. A brief enrichment wait may recover the payer number without slowing complete messages.

For 1 September through 30 October 12:12 EAT, **3,222/3,413** observed references appear in the scoped CRM extraction; **3,018** have one positive successful TZ payment at the exact amount/currency, excluding only explicit test classification. Of 2,355 exact links with both observed payer and current advert contact phones, **1,482 differ**. TZS35,000 links to 7-,8-,9-day and other VIP deals. A simple three-prior/one-profile rule has **39 disagreements in 671 candidates** against retained CRM assignments. Sandbox/legacy test-mode flags were not evaluated. These are comparison labels, not a full business-visible cohort, independently verified customer intent or an automatic accuracy/coverage estimate.

The [detailed TZ findings](tz-sms-review-2026-10-30/findings.md) contain the formats, crosschecks, denominators and caveats. Every supplied historical XML record is analysis/reconciliation-only; none is eligible for automatic entitlement dispatch. Live cutover and duplicate-safe overlap prevent an old receipt from triggering a new subscription.

## How matching should work

Separate **payer identity**, **advertiser/profile identity**, **intended package** and **funds evidence**. Remember reviewed alternative payer numbers without changing the advert contact. A payer may fund several profiles; never resolve that ambiguity by choosing the first record.

Resolve intent through a current owned client-bound deal/quote or a staff-confirmed payer/profile relationship plus a current standing renewal agreement. The agreement can be established once and used by later clear receipts without a fresh sales-created deal. Sales-created packages and recent purchase/negotiated-price patterns explain candidates; repetition alone does not authorize a future price or profile. If the amount is below catalogue, suggest the likely package and discount from history and obtain any missing price authority inline. Preserve valid existing agreements; a prior one-off discount must not silently become permanent permission. Staff should see why the system chose a customer, duration and price.

After capture, shadow evaluation and safe correction tests, allow capped **activate now, review afterward** for a proven account and unique reviewed payer/purchase pattern. If context is ambiguous, show a prefilled choice immediately. Raw history, nearest price and name/advert-phone matches need corroborating current authority. The supplied data shows why: shared payers and different-duration purchases at the same amount occur.

## Corrections are part of the core product

Reassignment must preserve the original cash receipt and count revenue once. Correct the mistaken operation's entitlement, protect older and later legitimate subscriptions, activate the correct profile, and move the related Tanzania exposure when appropriate. Track partial outcomes and recover without repeating days or grants. Existing “Revert profile” and whole-profile deactivation are unsuitable shortcuts.

The implementation must make both human-triggered and unattended writes safe across CRM and WordPress. Receipt deduplication, persistent operation IDs, exact intended expiry, shared writer guards and explicit recovery belong before live activation/correction. A human clicking a button does not remove the network-failure risk.

## Delivery and effort

Validate capture and decision rules in shadow; establish reviewed payer/standing-purchase relationships and safe corrections; then enable autonomous clear cases with review afterward, while reconciling receipts to provider records daily. These are release gates toward the requested autonomous product, not a permanently assisted workflow. Planning estimate: **24–36 engineering person-days** for the assisted pilot and **12–20 additional days** for unattended decision logic and its policy/acceptance work, subject to reviewed scope. The initial25October plan passed three audit rounds; the30October corpus/autonomous revision passed after two further rounds. The safe WordPress write contract is required for every entitlement write. Existing phones avoid an initial handset/modem purchase. Low exposure caps, correction-duration policy and collector fee/remittance rules need business decisions before those operations go live.

The [visual implementation plan](plan.html) contains five UX states, contracts, six phases, test gates, rollback and the policy form. The [message atlas](message-atlas.md), [collection inventory](collection-inventory.csv), source parity, reference shapes and purchase-pattern evidence accompany it. The TZ recipient corpus is now supplied and analyzed; Ghana recipient examples, independently reviewed intent labels and live target/phone acceptance remain implementation gates.
