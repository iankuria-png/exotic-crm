# Subscription audit reporting

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
