# Onboarding Credential Dispatch Runbook

## Scope
This runbook covers CRM credential dispatch for newly onboarded escorts:
- Methods: `setup_link`, `temporary_password`
- Channels: `email`, `sms`, `both`
- Timing: `send_now`, `manual_send_later`

## Operator Workflow
1. Create the client using **Provision in WordPress** mode.
2. Open **Send credentials** drawer (auto-opens after create, also available in Client Detail).
3. Use recommended defaults:
- Method: `setup_link`
- Channel: `both`
- Timing: `send_now`
4. Validate recipient email/phone.
5. Submit and confirm status in **Recent dispatches**.

## Status Meanings
- `deferred`: Saved for manual send later.
- `sent`: All selected channels delivered.
- `partial`: At least one selected channel failed.
- `failed`: No selected channel delivered.

## Retry Protocol
1. Retry only `deferred`, `partial`, or `failed` records.
2. Correct recipient details first if needed.
3. Retry from drawer history.
4. If `sent` already, resend only with explicit intent (`force=true` in API).

## Provider Setup
The implementation uses Laravel mail transport + existing SMS provider routing.

### Email providers (recommended options)
- Postmark
- Mailgun
- Amazon SES

### Minimum env/config checklist
- `MAIL_MAILER`
- `MAIL_HOST` / provider-specific credentials
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`
- SMS provider config in CRM Settings (active + fallback as needed)

## Failure Triage
1. Check dispatch row `error_message`.
2. Review `provider_results` payload in DB if needed.
3. For setup-link failures, verify platform domain/WP API URL is configured.
4. For temporary-password failures, verify client has valid `wp_user_id` and market DB credentials.

## Security Controls
- Temporary passwords are never persisted in audit logs or dispatch payloads.
- Dispatch requests use idempotency to dedupe accidental double-submit windows.
- Prefer setup-link flow for lower credential exposure risk.

## API Endpoints
- `GET /api/crm/clients/{client}/credentials/dispatches`
- `POST /api/crm/clients/{client}/credentials/dispatch`
- `POST /api/crm/clients/{client}/credentials/dispatches/{dispatch}/retry`

## Verification Checklist
- Create provisioned client returns positive WP IDs.
- Send-now setup-link works for email/sms/both.
- Manual-send-later creates `deferred` dispatch.
- Retry transitions deferred/failed rows to sent/partial/failed with provider detail.
