# CRM conventions

- Phone normalization strips + and a leading 0, then applies the country prefix.
- escort_expire is a Unix timestamp; subscription start/end values may be stored as strings.
- platform_id is the market scope key.
- Production uses MariaDB-compatible SQL. Avoid reserved aliases such as rows.
- The MCP endpoint is read-only and stateless.
