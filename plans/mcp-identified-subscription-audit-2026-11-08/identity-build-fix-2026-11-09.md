# Complete named audit build repair — 9 November 2026

Production has b685c10b receipt-grouping repair. A complete builder test, using session-only copies of the three reporting tables and an outer database-enforced READ ONLY transaction, exposed a second failure: SQLSTATE22003/driver1264, out-of-range wp_post_id during profile insertion. The CRM cache permits signed integer identifiers, including legacy negative placeholder values; the derived report accepts unsigned identifiers. Source-reader-only acceptance did not exercise these writes.

## Corrected behaviour

Nonpositive WordPress post/user identifiers become null only in the derived snapshot, with wordpress_identity_invalid in review flags. These profiles remain in population/payment reports; their names/URLs remain unavailable. Identity presentation requires a positive post ID and a valid optional user ID before attaching names. CRM source values are preserved.

The full production-data build then reached the original120second budget. Increase the bounded background build allowance to600seconds in config and fallback checks; population/source limits,384MBCLI cap, atomic publication, deadline rollback, retention and hourly overlap guard remain. Completed-run visibility remains atomic. The CLI now reports safe SQLSTATE/driver codes or a reporting reason without raw SQL, bindings or exception messages.

## Acceptance evidence

- Fresh deployed source/schema read5e1c proves b685c10b is present and migration147 applied; latest durable run remains5a75ab86 with no profile coverage.
- Full deployed shadow builder76e0 reproduces22003/1264 on inserting a negative post ID.
- Candidate full builder17e3 passes the insert then reaches the120second reporting deadline; no snapshot is published.
- Complete candidate5ae8 with600second allowance: completed34,256profiles/18,192events in178.55seconds,258MBPHP peak;19profiles have null frozen post IDs. Strict MariaDB11.4.13 mode remains enabled. Temporary table state is rolled back/discarded and durable writes are blocked by the enclosing READ ONLY transaction. This is production-data compatibility acceptance, not a live published snapshot.
- Local isolated MySQL regression:42tests/448assertions pass,1SQLite-onlyfault injection skipped; negative/zero post/user identifiers, unchanged source values, unavailable profile AND trace identities, valid null-user bindings, safe CLI diagnostics, payment/source rules and transaction rollback covered. Disposable Local schemas removed. PHP8.2 syntax7files/Pint7files pass. Current-baseSQLite remains blocked by an unrelated MySQL-only collection migration, as recorded in the preceding repair.

Private Ops run IDs use prefix20261109T134440Z for5e1c,134638Z for76e0,135103Z for17e3 and135337Z for5ae8. Full ledger/output and source/test hashes retained in output/mcp-named-audit-identity-fix-2026-11-09/ and exotic-ops var/runs/. Earlier candidate namespace/import harness failures are retained, not counted as passing acceptance.

## Manual cPanel deployment

Pull the repair commit through the reviewed deployment workflow, preserving unrelated live overlays. No new migration or frontend build is needed. Use productionPHP8.2:

```sh
cd "$HOME/crm.exotic-online.com"
php artisan config:clear
php artisan config:cache
php artisan crm:subscription-audit-build --days=90
```

The configuration-cache rebuild is required to replace the previously cached120second limit. Allow several minutes for the build. Only a completed-run message establishes that a new report was published. On failure the new CLI includes safe codes; preserve that full output.

Then pin the new run in MCP: require a profile timestamp, reconcile all activeKenya profile pages, test named profile/event/trace agreement and compare paid/free/unverified samples with source records. Expect individual legacy invalid/moved/deleted bindings to remain identity_unavailable; a successful build does not fabricate missing identities. Management-specific grants/Julian and independent provider/statement checks remain separate.

Rollback restores the private pre-deployment copies of the runtime/config files and rebuilds config cache. Existing reporting tables and completed snapshots are retained; do not alter CRM source identifiers or delete financial records.
