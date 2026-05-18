# KYC launch checklist

## Before enabling the first market
- [ ] Plugin deployed to WordPress
- [ ] CRM backend migrations applied
- [ ] CRM frontend built and deployed
- [ ] KYC remains disabled globally by default
- [ ] Reviewer playbook reviewed with the market lead
- [ ] Admin runbook reviewed
- [ ] Sales-team training session completed
- [ ] Translation artifact prepared for the market locale, or English explicitly accepted
- [ ] DB storage path tested end to end
- [ ] Document viewer tested in CRM
- [ ] Queue badge visible in sidebar

## Per-market go-live
- [ ] Platform added to `enabled_platform_ids`
- [ ] Required document kinds confirmed
- [ ] Exempt plan keys confirmed
- [ ] Escalation rule confirmed (`notify_only` unless explicitly approved otherwise)
- [ ] Search boost behavior verified on the site
- [ ] Status sync confirmed on staging
- [ ] At least one sample subject walked through full approval flow
- [ ] At least one request-info flow tested
- [ ] At least one rejection flow tested

## Staging verification
- [ ] Unverified user can still register, pay, and publish
- [ ] No gate classes exist
- [ ] No `exotic_kyc_*` user meta is stored in WordPress
- [ ] KYC approval stamps `verified_source=kyc`
- [ ] WP manual flip stamps `verified_source=manual_wp`
- [ ] Emergency verify stamps `verified_source=manual_crm_emergency`
- [ ] DB ciphertext is stored without plaintext leakage
- [ ] S3 mode works after driver switch
- [ ] Existing DB documents remain readable after the S3 switch
- [ ] No public pending pill appears
- [ ] Discovery boost affects the bespoke SQL path

## After launch
- [ ] Queue volume reviewed daily during the pilot
- [ ] Conflict audit events monitored
- [ ] First re-verification sweep dry-run completed
- [ ] Reviewer feedback gathered for copy and mobile ergonomics
