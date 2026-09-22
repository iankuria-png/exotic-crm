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

## 2026-09-22 implementation handoff

- Plan authority: plans/mcp-client-compatibility-optimization/plan.mdx and its
  rendered plan.html. It supersedes the older knowledge-layer plan for transport,
  OAuth, token grants, diagnostics and intelligence coverage; do not execute both
  plans in parallel.
- Confirmed product decisions: CRM-hosted OAuth 2.1 authorization-code flow with
  PKCE; role-gated named client rows for authorised administrators only with explicit
  confirmation and immutable audit; staff outputs use administrator-managed aliases
  such as benja, flo, and zedo; Support Board is excluded; preserve all CEO
  dashboard calculations and the full dashboard contract.
- Verified source defects to repair before expansion:
  1. Modern tools/call falls through to legacyData(), which omits several
     advertised legacy tools and returns -32601.
  2. Generic schema pattern interpolation in McpServer validateModernArguments()
     makes slash-bearing patterns invalid; exotic_get_document therefore fails
     internally. Fix the generic matcher first, then use ResourceRegistry as the
     document allow-list.
  3. ReportingCurrencyService normalization_meta.rows is per-event FX metadata
     and can bloat/repeat within revenue MCP output. Preserve calculation, project
     this metadata out of normal MCP responses.
  4. Modern vitals status thresholds are not configured, and Mintlify ingestion
     leaves provider chrome in chunks.
- Forward protocol: implement the 2026-07-28 stateless path with server/discover
  and per-request metadata, while retaining isolated 2025-03-26, 2025-06-18
  and 2025-11-25 handshake adapters. Protocol version is server compatibility,
  not a token data grant.
- Mandatory first test gate: enumerate each permitted result from tools/list,
  call it with minimal valid fixture arguments, and assert no -32601/-32603;
  also assert resources/read parity with exotic_get_document. Add modern-era
  and legacy-era protocol fixtures. Re-run and record current scoped MCP test
  counts; historical counts are not current evidence.
- Remaining product decision: whether any transaction-level FX normalization rows
  can ever be exposed through MCP. Default/recommended policy is never; retain
  aggregate currency coverage, freshness and source breakdown only.
- Local state: source HEAD observed 80976aab; the checkout is dirty with unrelated
  Client/UI/build/docs work. Planning created untracked files under
  plans/mcp-client-compatibility-optimization/; no application source was changed,
  no deployment was requested, and no production credential should be copied into
  chat or tests.

## 2026-09-22 implementation checkpoint

- Local source implementation now includes `2026-07-28` stateless
  `server/discover` support with per-request metadata validation and preserved
  `2025-03-26`, `2025-06-18` and `2025-11-25` handshake compatibility.
  Discovery, resource reading and every advertised permitted tool have an
  executable conformance fixture.
- Added CRM-hosted OAuth authorization-code + S256 PKCE metadata, dynamic client
  registration, consent, short-lived access credentials, rotating refresh tokens,
  revocation and root/API protected-resource discovery. MCP credentials are
  rejected outside `/api/mcp`.
- Added governed CEO, scorecard, team, visitor, commission, client operations,
  lifecycle and city tools. CEO calculations remain in `CeoDashboardDataService`;
  the MCP response projection removes transaction-level FX normalization rows.
  Support Board is not registered.
- Staff MCP results use administrator-managed aliases only. Settings → MCP → Data
  & Privacy now manages aliases. Identified client queues require an admin-owned
  capability, explicit confirmation and purpose; actor/tool/filter/count/purpose
  are recorded in `audit_log`, and phone/email/content/payment references remain
  absent from the result.
- Managed token grants support atomic in-place PATCH updates with a redacted audit
  diff and no secret rotation. Partial updates preserve existing capabilities.
- Verification on HEAD `80976aab` plus local uncommitted implementation changes:
  baseline before implementation `/usr/local/opt/php@8.2/bin/php artisan test
  tests/Feature/Mcp` = 26 tests / 139 assertions (67.47s). Final complete suite:
  34 tests / 226 assertions (54.28s). Targeted OAuth/grant coverage: 5 tests /
  32 assertions (7.81s). Pint passed for 21 task-owned PHP files; `routes/web.php`
  retains two pre-existing unrelated Pint findings. `npm run build` passed; it
  retained the pre-existing forecast CSS warning and large bundle advisory.
- New operator references: `docs/mcp-intelligence-contracts.md` and
  `docs/mcp-client-operations.md`. Implementation commit `a042a1d4` was pushed
  to `origin/main` on 22 Sep; no cPanel pull, deployment or production validation
  has been performed. Production still needs separately authorised rollout and
  restricted non-production ChatGPT/Claude connector checks first.
