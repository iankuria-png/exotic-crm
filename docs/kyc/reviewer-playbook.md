# KYC reviewer playbook

## What this workflow is for
KYC in v1 is a soft-enforcement identity and age verification workflow. Reviewers are responsible for working the CRM queue. Advertisers can still register, pay, and publish while review is pending.

## Core rules
- No publish gate exists in WordPress.
- Only approved accounts show the public verified badge.
- `verified_source` must explain every verified client:
  - `kyc`
  - `manual_wp`
  - `manual_crm_emergency`
- `manual_crm_emergency` is admin-only and requires an explicit reason.
- Internal notes never leave the CRM.

## Daily reviewer loop
1. Open **KYC queue** in the CRM.
2. Triage oldest `in_review` subjects first.
3. Open the client detail page for full context.
4. Review the uploaded ID and selfie.
5. Choose exactly one path:
   - **Approve** when the ID and selfie are sufficient.
   - **Request info** when the issue is fixable with a better upload.
   - **Reject** when the submission should be replaced entirely.
6. Confirm the status pill, audit trail, and public verified badge state updated as expected.

## Review standards
Approve only when:
- the ID appears government-issued
- the person is clearly of age
- the selfie appears to match the ID
- the images are readable

Request info when:
- the image is blurry
- glare or cropping hides key details
- the selfie is too dark or unclear
- the wrong side of the document was uploaded

Reject when:
- the ID obviously does not belong to the profile owner
- the selfie clearly does not match
- the upload is fraudulent, abusive, or repeatedly unusable

## Special cases
### Verified in WordPress
If a client shows `verified_source=manual_wp`, treat it as a deliberate fallback path. Do not silently overwrite the audit story.

### Emergency CRM verification
This is for rare operational exceptions only. Admin must record a concrete reason.

### Exempt plans
If `kyc_required=false`, the client stays out of the queue and should not receive nudges.

## What to check after each action
- Queue status changed correctly.
- Client timeline reflects the action.
- Document views are auditable.
- Public pending pill does **not** appear anywhere.
- WordPress still shows the public verified badge only after approval or another deliberate verified path.
