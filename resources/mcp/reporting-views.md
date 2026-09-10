# Reporting views

The existing AI reporting views are dashboard-adjacent and may contain raw internal identifiers.
They are not automatically eligible for the MCP SQL hatch. MCP SQL is restricted to explicit
aggregate wrapper views that expose platform_id and no entity identifiers.

All reportable payment views exclude manual review rows, reversed or invalid references, test
records, sandbox records, wallet topups and visitor contact unlocks when the source column exists.
