# Subscription audit reporting

## Named profile audit extension — release prepared 9 November 2026

The named-profile extension is included in this CRM release; deployment and management-account acceptance must be recorded separately. It adds `exotic_subscription_audit_profiles`, named event/trace enrichment, and explicit named-audit consent. The previous release notes below describe the original four anonymous event tools.

An active administrator connection needs the explicit tool permission and `mcp:subscription-audit`. Named reads additionally require `mcp:identified-clients`, `identified=true` and a purpose of 3–160 characters. Select **Include names and public profile links** when minting a token, edit an existing grant to select the profile/event/trace tools and identified-client capability, or reconnect through OAuth and select **Include named subscription audits**. These choices are unchecked by default. Named audits require no additional confirmation on each page; the existing client queue still uses its confirmation flag. Revoked capabilities remain revoked through OAuth refresh. Settings preview uses one eligible owned grant rather than combining separate tokens.

The catalog reports this connection's effective audit capabilities. It does not establish another manager's permissions. Named responses are private/no-store, carry zero result-cache TTL, and require a successful dedicated audit-log write. Generic tool logs retain only keyed argument digests and counts. Contact details, arbitrary reasons, raw payment references and internal CRM links remain excluded.

Management prompts:

1. “For an authorised subscription audit, show active profiles in Kenya with no successful cash payment found in retained CRM records. Include names, public profile URLs, the latest evidenced activation date, activation staff, payment evidence and exception authorisation. State missing evidence and snapshot freshness.”
2. “Using that same run, list every remaining page and reconcile unique profile references with the total.”
3. “Show profiles whose payment history remains unverified, including wallet-funding and conflicting-payment evidence.”
4. “Show profiles with unverified payment for their latest subscription action, including those that have paid previously.”
5. “Trace this event from the same run with identified=true for management review. Separate the executor, assigned owner, payment confirmer and authoriser.”

Profile and trace findings share retained payment allocation and approval-candidate logic. During a new build, profile history refines matching event rows in that same unpublished transaction; completed runs are never revised. Each event exposes its payment allocation scope. Historical events for closed/deleted profiles without a profile projection retain an explicit event-window-only scope.

The profile tool accepts `purpose`, `run_ref`, `platform_id`, `country`, `identified`, `profile_scope=active|published`, `payment_filter`, `page_size` (1–100) and `cursor`. Supported payment filters: `all`, `no_successful_cash_payment_in_retained_crm`, `payment_history_unverified`, `current_action_payment_unverified`, `authorised_free`, `approval_unverified`. It deliberately does not accept event date/population filters. Active means the established CRM business-active definition; published also includes SEO-retained inactive profiles. `profile_as_of` identifies the current cached population/payment status separately from the possibly historical event window. Every non-closed cached profile is represented, including no-deal profiles and older activations. Per-profile cache/expiry discrepancies stay visible.

No-retained-cash means no qualifying positive cash subscription/top-up record and no unresolved candidate that could establish cash. It never proves lifetime nonpayment. Wallet subscription debits do not count as cash receipts; cash top-ups establish cash history but do not prove allocation to a specific purchase. Test, reversed, disputed, conflicting, duplicate-receipt and incomplete collection/bundle evidence cannot silently establish successful cash. Other valid cash does not erase unresolved-candidate review flags. Currency totals count allocated records separately by currency and do not count wallet debits again.

Names and the `/?p=…` public WordPress locator are current identity labels. The URL is not asserted to be the canonical slug. Both profile rows and event traces check the same frozen platform/client/WP-post/WP-user bindings; changed or unavailable bindings produce `identity_unavailable`. Old runs without a profile projection cannot resolve an unchecked new URL. Deleted staff remain former/unavailable. Latest activation requires a recorded effect; a mutable deal timestamp alone is an observation. Outside-window action evidence retains opaque provenance and an explicit unavailable trace state.

Durable trial request/operation/event records supply exact approval provenance. A PIN-authorised staff member is an authoriser; they are not presented as a named administrator approver or proven executor. Both known trial dispatch/activation actor fields are synthesized from the authoriser and cannot independently prove execution. Unrecorded execution remains unknown/system-mediated. Trial source availability appears in coverage/caveats.

Deployment adds only `database/migrations/2026_11_09_100000_create_subscription_audit_profiles_table.php` and rebuilds reporting snapshots. See `plans/mcp-identified-subscription-audit-2026-11-08/delivery.md` for the exact package, validation and commands. The build reserves the existing CLI memory allowance, uses 500-profile chunks, caps the profile population at 300,000 and rejects a run exceeding its 120-second build budget. Event-source bounds remain in effect. Failure rolls back the derived run and keeps the prior completed report; six-hour retention and 15-minute cursors remain. Rollback retains the derived table. No WordPress upload, payment write, activation or policy change is part of this release.

This release delivers the subscription action report, payment exceptions, staff/approval trace, per-currency potential exposure and read-only access through both ChatGPT and Claude. Authenticated CRM report endpoints consume the same service. It uses retained CRM evidence; independent provider/statement verification, new WordPress event capture and EWMS-specific bindings are separate work.

## Deploy after the authorised CRM push

Ian deploys through the existing cPanel Git pull process. A successful push is not deployment. Follow the normal preflight/backup procedure and confirm PHP 8.2 before these commands, from the CRM application root:

```sh
php artisan migrate --force --path=database/migrations/2026_11_05_100000_create_subscription_audit_reporting_tables.php
php artisan config:cache
php artisan route:clear
php artisan crm:subscription-audit-build --days=90
```

The migration adds reporting-only runs and events. The initial build reads existing CRM sources and writes only these reporting tables; it does not activate subscriptions, modify payments, send messages or call payment/FX providers. A completed run covers up to 90 days. The CLI build reserves a per-process 384 MB ceiling, bounds each source and the canonical event set to 30,000 rows, and refuses oversized runs while preserving the previous completed report. The scheduler refreshes at minute 13 hourly in the background, with overlap protection and the existing heavy-work load gate. A report exposes its snapshot time and labels stale/partial coverage; a skipped or failed refresh must not look current.

The build retains six hours of reporting snapshots plus the newest completed run. It prunes reporting rows only. Source deals, payments, audit logs and timeline records remain intact. Cursors expire after 15 minutes; an expired or pruned run requires starting a new report. Do not manually delete source evidence to resolve an exception.

No WordPress upload or frontend build is required for this backend release. Existing source/build work in the Local checkout is excluded from the release.

## Connect management access

Four tools are added:

- `exotic_subscription_audit_summary`
- `exotic_subscription_audit_events`
- `exotic_subscription_audit_trace`
- `exotic_subscription_audit_coverage`

Access requires an active administrator, an explicit audit-tool permission and the `mcp:subscription-audit` capability. Existing tokens gain no new access automatically. Using existing Settings controls, create a normal token, then Edit its grant and explicitly select the audit tools. This grants the capability; removing all audit tools removes it. Broad/default token creation does not acquire audit access. Programmatic token creation can explicitly supply `subscription_audit: true`. Settings preview requires a currently active eligible audit grant owned by its signed-in administrator.

For OAuth, reconnect the ChatGPT or Claude connector to the existing CRM MCP endpoint and explicitly select the subscription-audit option on the server-hosted approval page. Audit consent uses a CSRF-protected POST. Old GET approval links cannot grant audit access. Removing permissions or deleting the backing grant cannot be undone by refreshing an old token.

The JSON-RPC endpoint remains `/api/mcp`. All existing retained protocol adapters expose the same four tools; no MCP App rendering is required. Both clients receive readable JSON/text and the existing structured result envelope where their adapter supports it.

## Acceptance prompts for both clients

Use these after the actual deployed migration/build and connector permission update:

1. “For financial-control review, show subscription audit coverage for the last 90 days. State which websites and evidence sources are incomplete and when the snapshot was built.”
2. “For financial-control review, summarise subscription activations, renewals and extensions in that run. Group exceptions by website and staff. Show potential exposure separately for each currency and count unknown amounts.”
3. “For financial-control review, list the exceptions from that same run, paginate through every result and reconcile the total with the summary.”
4. “For financial-control review, trace this event reference. Show the actual actor, approval evidence, old/new expiry and the payment evidence for this particular action.”

Keep the `run_ref` returned by the first report when comparing subsequent results. Use managed staff aliases/handles in MCP; customer names, contacts, raw payment references and notes are not exposed. Missing staff aliases remain stable handles rather than invented employee names. A missing actor or approval is a coverage finding, not proof of misconduct.

The trace must distinguish the payment used for an extension from an earlier payment still attached to its deal. A completed CRM payment is labelled CRM-recorded evidence. Failed, pending, cancelled, reversed/refunded, wrong-site/client/currency and unsupported allocation evidence remains visible. Test/sandbox payments cannot pay for live access. Unpriced extensions and ambiguous allocations are counted separately instead of receiving an invented value.

## CRM reporting interface

Existing authenticated administrator sessions can make GET requests to:

```text
/api/crm/reports/subscription-audit/summary
/api/crm/reports/subscription-audit/events
/api/crm/reports/subscription-audit/trace
/api/crm/reports/subscription-audit/coverage
```

Supply `purpose` on every request. Supported query filters include `run_ref`, `from`, `to`, `platform_id`, `country`, `staff_handle`, `event_type`, `population`, `exceptions_only`, `page_size`, `cursor` and `event_ref` as applicable. Use explicit UTC periods; responses state the exact source window and snapshot time. GET booleans use `0`/`1`. Page size is at most 100. The response includes explicit partial/stale caveats and no-store caching.

An authorised CRM trace may request `include_identity=1` to resolve its advertiser and staff identities inside CRM. The service first checks the event's current market scope and logs the identity read. This option is never exposed as an MCP argument and returns no customer contact details. MCP credentials cannot use the CRM endpoints.

## Verification and rollback

Local fixture/transport verification and exact shipped-tree checks are recorded in `plans/mcp-subscription-audit-2026-11-05/delivery.md`. They do not establish live acceptance on either external client. After deployment, record the completed production run, actual connector tool discovery and the four prompts above for both clients. Independently compare a paid activation, a trial and an extension with original CRM records; summary totals must equal all pages from the same run.

Disable the four audit tools and remove their grants to stop access. Stop the reporting refresh if necessary. Preserve completed reporting and source evidence; reporting rollback requires no reversal of customer entitlements or payment changes. Existing Ads API, payment callbacks and STK auto-activation are outside this release's write paths.
