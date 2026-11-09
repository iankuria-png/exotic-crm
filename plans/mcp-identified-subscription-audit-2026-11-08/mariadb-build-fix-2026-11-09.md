# Named audit build repair — 9 November 2026

Production diagnosis confirms the original named-audit source files are deployed and the profile-report migration is applied (batch147). The latest completed run remains `5a75ab86-c6df-44a7-b15d-20a03788509e`, built by the old rule version, with no profile coverage. Names therefore fail the required same-run WordPress identity binding. Reconnecting or refreshing cannot repair this snapshot.

## Cause and fix

On MariaDB11.4.13 with Laravel's strict `ONLY_FULL_GROUP_BY` session, the duplicate-receipt query fails with SQLSTATE42000/driver1055: `payments.reference_root` is not in GROUP BY. MySQL and SQLite acceptance did not catch this MariaDB expression-grouping incompatibility. The command rolls the new run back and correctly preserves the previous completed report.

Project the normalised root and physical receipt identity in a derived table, then group its column names. Preserve all existing successful-payment filters, platform boundaries and the rule that allocations from one bundle count as one physical receipt for duplicate detection. Strict SQL mode stays enabled. No schema, frontend, grant or financial mutation is part of this repair.

## Verification

- Original deployed reader reproduces driver1055 in the first profile chunk.
- Candidate reader passes all69profile chunks against production in a database-enforced read-only transaction, with the same strict SQL mode. No report rows are written or published by that check.
- Isolated current committed base214c856e plus this patch:39MySQL tests/403assertions pass, oneSQLite-only injection test skipped. Includes a new regression for trimmed/case-folded and fallback roots, bundle allocations and same-reference/different-market boundaries; real MCP report/trace journeys and rollback tests pass. Disposable Local schema dropped.
- PHP8.2 syntax and Pint pass. No UI build required for this backend-only change.
- Fresh214c856e SQLite suite cannot migrate because the unrelated committed collection-automation migration uses MySQL UPDATE JOIN. It fails before assertions; this repair does not alter that migration. MySQL is the production-compatible acceptance environment.

Private diagnostic evidence is retained in the exotic-ops run ledger and raw output: schema `20261109T132853Z-d4792-d9410-read-crm-named-audit-build-read-d42e`; original failure `20261109T133310Z-d4792-d9410-read-crm-named-audit-native-read-6ce0`; candidate `20261109T133241Z-d4792-d9410-read-crm-named-audit-candidate-read-f8bb`. Local test and preservation evidence: `output/mcp-named-audit-fix-2026-11-09/`.

## Manual cPanel delivery and acceptance

After the repair commit is pulled through the usual reviewed cPanel Git workflow, check the single runtime file and rebuild using the production PHP8.2CLI:

```sh
cd "$HOME/crm.exotic-online.com"
sha256sum app/Services/SubscriptionAudit/SubscriptionProfileAuditBuilder.php
php artisan crm:subscription-audit-build --days=90
```

Expected runtime SHA256: `08cdbcb489a46acc19bab835c1521efd539f469e933238151fbd1335604f4be6`.

The profile-report migration is already verified; this repair adds no migration. Existing source overlays/unrelated live work must be preserved by the deployment workflow. No broad migration, cache flush or permission change is required for the one-file query correction.

Require a successful completed-run message, then verify the new pinned run through MCP: profile coverage has a timestamp, named profile/event/trace reads agree, all profile pages reconcile, and paid/free/unverified samples match source evidence. The live snapshot publication and named-management acceptance remain pending until these checks pass.

For rollback, restore the private pre-deployment copy of the one runtime file. Completed report snapshots and financial source records are not deleted by a code rollback.
