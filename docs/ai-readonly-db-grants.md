# AI Read-Only Database Connection

The "Talk to Your Data" (NL→SQL) AI feature executes generated `SELECT` queries
against a dedicated **read-only** MySQL connection (`mysql_readonly`, defined in
`config/database.php`). This is defense-in-depth — the `SqlSafetyValidator` is the
primary guard (single `SELECT`, allow-listed `vw_*` views only, mandatory `LIMIT`,
no DDL/DML/comments/stacked statements) — but the connection should still be backed
by a database account that is **physically incapable** of writing.

## Environment variables

Set these in production `.env`. They fall back to the primary `DB_*` credentials,
so local/dev works without a separate user (do NOT rely on that in production):

```
DB_RO_HOST=127.0.0.1
DB_RO_PORT=3306
DB_RO_DATABASE=d9410_ExoticManagementDB
DB_RO_USERNAME=crm_ai_readonly
DB_RO_PASSWORD=change-me-strong-secret
# Optional, for Local-by-Flywheel style socket connections:
# DB_RO_SOCKET=/path/to/mysqld.sock
```

## Provisioning the read-only MySQL user

Run as a DB admin. Grant `SELECT` on **only** the allow-listed reporting views,
never on base tables (so even a validator bypass cannot read raw PII columns):

```sql
CREATE USER IF NOT EXISTS 'crm_ai_readonly'@'%'
  IDENTIFIED BY 'change-me-strong-secret';

-- Reporting views only — these expose platform_id + USD amounts and ZERO PII.
GRANT SELECT ON d9410_ExoticManagementDB.vw_payments_usd  TO 'crm_ai_readonly'@'%';
GRANT SELECT ON d9410_ExoticManagementDB.vw_market_revenue TO 'crm_ai_readonly'@'%';
GRANT SELECT ON d9410_ExoticManagementDB.vw_agent_perf      TO 'crm_ai_readonly'@'%';

FLUSH PRIVILEGES;
```

> The views are defined as `SQL SECURITY DEFINER` by default, so the read-only user
> does **not** need `SELECT` on the underlying `payments`/`platforms`/`deals`/`users`
> tables for the views to resolve. Confirm the view definer has those rights.

### Optional hardening

- Restrict the host (`'crm_ai_readonly'@'10.%'`) to your app subnet.
- Set a conservative `MAX_STATEMENT_TIME` / `max_execution_time` for this user so a
  runaway query is killed server-side (the app also sets a statement timeout per
  `config('ai.insights.sql_timeout_seconds')`).
- Put the account in a role with `REVOKE`d `INSERT, UPDATE, DELETE, CREATE, DROP,
  ALTER, GRANT` to be explicit.

## Verifying

```bash
# Should succeed:
mysql -u crm_ai_readonly -p -e \
  "SELECT platform_id, SUM(revenue_usd) FROM d9410_ExoticManagementDB.vw_market_revenue GROUP BY platform_id;"

# Should FAIL with an access-denied error:
mysql -u crm_ai_readonly -p -e \
  "UPDATE d9410_ExoticManagementDB.payments SET amount = 0 WHERE id = 1;"
mysql -u crm_ai_readonly -p -e \
  "SELECT * FROM d9410_ExoticManagementDB.clients LIMIT 1;"
```

## Notes

- Tests run on SQLite (`:memory:`), where the views are created portably and queries
  run on the default connection; the `mysql_readonly` connection only matters in
  MySQL environments.
- If you add a new reporting view, add it to `config('ai.reporting_views')` AND grant
  `SELECT` on it here, or the validator will reject queries that reference it.
