# Task context: MCP knowledge and diagnostics

- Updated: 2026-09-20. Status: implementation exists; investigate specific gaps, not a fresh build.
- Git evidence: `50aa8921` governed layer, `912953e3` standard protocol, `9b166f5d`
  staging pipeline, `8ebafdd5` staged review, `fa8f064d` enhanced tool access (12 Sep).
  `9787339f` (16 Sep) and `5908591e` (17 Sep) add revenue app and preview.
- Code: `app/Services/Mcp/ResourceRegistry.php`, `Knowledge/KnowledgeSearch.php`,
  `Knowledge/OntologyRegistry.php`, `Diagnostics/PaymentFlowTraceService.php` beneath
  that service directory, and `tests/Feature/Mcp/McpKnowledgeLayerTest.php`.
- The existing plan remains a requirements/rationale source, not an authoritative pending list.
- 20 Sep audit observation: catalog calls succeeded, resource listing failed with an unexpected
  response, and template listing returned method-not-found. This does not prove the knowledge
  layer is unimplemented; diagnose client/protocol/auth/endpoint differences against current code.
- Next on a relevant request: inspect current registry/server and path-specific Git history,
  reproduce the exact failing interface, then run scoped MCP tests locally. No test or production
  result is asserted here. Old plan approval does not authorize new remote writes.
