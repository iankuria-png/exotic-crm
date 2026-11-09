# Audit command overlap and cleanup reporting — 9 November 2026

Ian supplied CLI SQLSTATEHY000/driver1205 after deploying cfb96d99 and refreshing configuration. This is a database lock wait timeout. Read-only production inspection83f9 confirms the600second configuration, deployed source hashes, no currently running audit CLI and two completed new-rule reports:35745259 at14:10:07UTC and01883b81 at14:13:06UTC. The database lock graph is unavailable to this database account (permission1227); no blocking session was killed and the exact historical statement/lock owner is unverified.

The command previously wrapped both build and post-commit pruning in the same catch, so a pruning timeout could falsely report that the build failed after the snapshot was already published. The scheduler's overlap guard does not cover manualCLI invocations. The completed-run timings are consistent with a manual/scheduled overlap; they do not conclusively attribute the screenshot's failure to pruning.

## Corrected command behaviour

Use a database-specific MySQL/MariaDB session advisory lock with zero wait across both CLI build and pruning. A competing scheduled/manual command exits successfully with an explicit skipped/already-running message. Release the lock in finally after success, build failure or cleanup failure; connection closure also releases the lock. SQLite remains a local test path without this server advisory lock.

Print the completed run immediately after the builder returns. Retention cleanup failure produces a separate safe warning and does not relabel the completed snapshot as a failed build. Cleanup retries on the next successful run. Real build failure still returns failure and preserves the previous completed report. Never print SQL, bindings or raw database messages. No source financial writes, migrations, configuration or frontend changes.

## Verification

44isolated MySQL tests/462assertions pass,1SQLite-onlyfault injection skipped. New independent-connection advisory-lock test verifies competing command skips without invoking builder/prune or writing a report and releases its own lock after a build exception. Injected1205prune failure preserves the completed run, prints its ID plus the cleanup warning and exits successfully. Existing failure redaction, transactions, report identity, event/payment and MCP journeys remain passing. Disposable schema removed; PHP8.2 syntax/Pint pass.

Live MCP acceptance on pinned01883b81-46d1-411a-8d8a-72737c395e6a: all17Kenya event pages yield1,623uniqueevents;650exceptions/597unpriced/KES39,705potentialexposure agree exactly with summary. Namedreads resolve1,606advertiser names+public URLs and return17identity_unavailable. Paid/free/no-match traces agree with pages. Independent SELECT-only source readc75e confirms1,623events/650exceptions,1,606same-run valid bindings and17missing clients. These17names cannot be recovered from the present CRM client cache by this feature.

Snapshot profile timestamp14:13:04UTC/17:13:04EAT is present. Fleet34,261cached profiles; Kenya2,813notclosed/341businessactive/1,047published. Coverage is partial and2,805Kenya profile cache rows are stale/unknown; report membership reflects cached state, not fresh independent WordPress verification. These are distinct populations, not unpaid counts.

The current connector catalog exposes34tools, including only four subscription event-audit methods; it does not expose exotic_subscription_audit_profiles. Existing named events/traces are accepted; full active-profile MCP pagination/filter acceptance remains pending the connection's explicit tool permission and refreshed discovery. No grant was changed. Julian-specific and provider/statement verification remain separate.

Private evidence: output/mcp-named-audit-lock-fix-2026-11-09/; Ops lock read20261109T141845Z-d4792-d9410-read-crm-named-audit-lock-read-83f9 and source read20261109T142404Z-d4792-d9410-read-crm-named-audit-live-acceptance-read-c75e. No production mutation or diagnostic report rebuild was performed in this checkpoint; completed reports came from Ian/scheduled production commands.

## Delivery

Pull this backend command patch through the reviewed cPanel workflow, preserving unrelated overlays. No migration, config-cache refresh, frontend build or immediate reporting rebuild is needed for this patch. The17:13EATreport is already available; the command guard applies to subsequent scheduled/manual invocations. Existing snapshots remain available while a later report builds.

For active-profile queries, explicitly enable exotic_subscription_audit_profiles for the management connection in CRMSettings/MCP token grant or reconnect with the desired audit tools; refresh discovery and verify the fifth tool. This does not require repeatedly granting names for each event page.
