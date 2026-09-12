# MCP knowledge-layer operations

## Deployment order

1. Deploy the additive migration and run `php artisan migrate`.
2. Run `php artisan crm:mcp-bootstrap-ontology` once. It is idempotent.
3. Keep all three flags disabled initially: `MCP_CONTRACTS_2026`,
   `MCP_KNOWLEDGE`, and `MCP_DIAGNOSTICS`.
4. Enable contracts, mint a replacement token with
   `mcp:protocol:2026-07-28` plus narrowly selected abilities, then use the
   2026 discovery smoke test.
5. Enable knowledge, stage a Mintlify snapshot, review it in Settings → MCP →
   Knowledge, then promote the version.
6. Enable diagnostics only after `crm:mcp-audit-schema` passes.

## Rollback

Set the corresponding flag to false to remove the modern surface while the
legacy 2025 endpoint stays available. Do not remove additive tables. A retained
semantic release can be reactivated from the Knowledge panel if an approved
reference snapshot needs rollback.

## Safety checks

- Use `crm:mcp-audit-schema` after a schema deploy.
- Staged documentation is never exposed to MCP; only a promoted snapshot is.
- Keep capability grants narrow. Bare `mcp:read` grants only bootstrap context
  resources, never modern tools, prompts, or diagnostic traces.
- The endpoint is read-only. A payment trace may diagnose activation evidence
  but cannot modify payments, deals, profiles, or WordPress.
