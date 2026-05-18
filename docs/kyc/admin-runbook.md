# KYC admin runbook

## Rollout posture
KYC ships **disabled by default**. Start with:
- `enabled_platform_ids=[]`
- `active_storage_driver=db`
- default escalation rule `notify_only`

This keeps the system passive until reviewers are trained and the market is ready.

## Admin responsibilities
- enable markets in **Settings → KYC**
- choose storage driver (`db` first, `s3` later if needed)
- define exempt plan keys
- confirm escalation rules per market
- verify the reviewer playbook and launch checklist are complete before enabling a market

## Storage modes
### DB mode
- Uploads land in `kyc_document_blobs`
- Bodies are encrypted with Laravel `Crypt::encryptString()`
- This is the safest pilot default

### S3 mode
- Only new uploads use S3 after the switch
- Existing DB-stored files must remain viewable
- Always run the S3 connectivity probe before saving the switch

## Escalation rules
- `notify_only` is the default and recommended starting point
- `remove_badge` removes the public verified signal without blocking service
- `auto_suspend` is admin-only and must be enabled deliberately per platform
- Rolling back from `auto_suspend` does **not** automatically reactivate profiles

## Recovery / rollback
### Disable a market
Remove the platform from `enabled_platform_ids`. This stops the live KYC workflow for that market without deleting historical subjects or documents.

### Revert storage driver
Switch `active_storage_driver` back to `db`. This affects new uploads only.

### Investigate mismatched verified state
Check:
- `clients.verified`
- `clients.verified_source`
- `audit_log`
- recent WordPress sync activity

If WordPress manually changes a KYC-derived verified state, audit should show `client.verified_conflict`.

## Operational commands
- `crm:kyc-reverify-sweep`
- `crm:kyc-escalate-overdue`
- `crm:kyc-recompute-exemptions`
- `crm:kyc-export-translations {locale}`
- `crm:kyc-import-translations {locale} {file}`
