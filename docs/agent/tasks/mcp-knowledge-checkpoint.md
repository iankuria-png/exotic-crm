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
  to `origin/main` on 22 Sep. It had not been deployed at shipping time; the
  later read-only probe below confirms current MCP code is reachable in production,
  although the deployment actor/time is not recorded here. Production still needs
  restricted non-production ChatGPT/Claude connector checks first.

## 2026-09-22 production read-only probe

- A supplied administrator MCP credential discovered 30 tools on
  `https://crm.exotic-online.com/api/mcp`; 24 returned successful bounded test
  responses. The shared CEO metrics matched across the summary, full CEO and
  rendered-dashboard paths. Transaction-level FX rows were absent from returned
  payloads. The complete response sweep was about 1.247 MB (roughly 312k output
  tokens at four bytes/token); no server-side model-token measurement exists.
- Confirmed live defects: `exotic_city_performance` throws `Indirect modification
  of overloaded element of Illuminate\Support\Collection has no effect` at
  `McpAnalyticsService.php:162`. `exotic_visitor_demand` reaches
  `ToolResultSanitizer`; source inspection shows its top-profile source row retains
  `client_id`, which sanitizer correctly refuses instead of projecting it to a
  pseudonym. `exotic_weekly_executive_scorecard` returns generic `-32603`, but its
  specific exception was not in the supplied log extract.
- The supplied logs also contain earlier `exotic_get_document` regex delimiter
  failures. The current live document call succeeds, so that error predates the
  delimiter-safe matcher rollout.
- Token-grant edit failure is deterministic: the Settings UI selects tools from
  `managementDefinitions()` (including enhanced tools), while
  `McpTokenGrantService` validates against legacy-only `definitions()`. Validate
  against enabled, role-appropriate management definitions to allow unchanged
  enhanced grants to save safely.
- No production mutation was made during this probe. The externally supplied token
  should be rotated because it was pasted into chat.

## 2026-09-22 supplied log follow-up

- The follow-up extract confirms the visitor-preview refusal: an unsanitized
  `client_id` reaches `ToolResultSanitizer` from the Settings preview path. The
  safe repair is a deliberate pseudonymous projection of the visitor top-profile
  rows, not disabling the sanitizer.
- It does not contain the weekly-scorecard exception. Its broad `tail` was
  dominated by an earlier daily-statistics foreign-key failure, so the generic
  weekly MCP refusal remains un-attributed pending a time-bounded log slice.
- Independently, the scheduled daily staff-statistics upsert attempted rows for
  a missing platform and failed its whole batch. That can leave team/weekly
  performance data stale or incomplete; it is separate from the MCP transport
  defects and requires an authorised data-health repair before scorecard
  accuracy can be certified.
- The focused grep confirms additional direct enhanced `tools/call` sanitizer
  failures immediately before the city failure, but its context begins at the
  stack trace and does not include the request's tool name. `mcp_tool_calls`
  records that exact attribution (tool, status, request ID and timestamp) and
  is the bounded read-only source required before assigning the remaining
  weekly failure to the archived scorecard projection.

## 2026-09-22 failed-call attribution

- The bounded `mcp_tool_calls` query attributed the three direct failures:
  `exotic_weekly_executive_scorecard` at 09:09:12 UTC, `exotic_visitor_demand`
  at 09:09:15 UTC, and `exotic_city_performance` at 09:09:21 UTC. All failed
  internally; no tool returned an unsafe result. The remaining scorecard repair
  must deliberately project stored `Briefing::decodedBody()` archives into the
  MCP-safe executive-scorecard contract before the final sanitizer, rather than
  weakening the sanitizer or forwarding arbitrary archival JSON.

## 2026-09-22 authorised production-defect repair

- Authorised local repair: token-grant validation now uses the same enabled,
  role-aware management registry shown by Settings; visitor top profiles are
  projected to pseudonymous client handles; archived scorecards remove raw
  entity/identity fields before the final fail-closed sanitizer; city scoring
  rebuilds collection rows instead of mutating them in place.
- Targeted regression plus OAuth/grant coverage passed: 9 tests / 50 assertions.
  Complete MCP feature suite passed: 38 tests / 244 assertions. Pint passed for
  the four changed PHP files and `git diff --check` passed. This backend-only
  repair changes no frontend source or generated build asset.
- Commit/push is authorised; production deployment still requires a separate
  cPanel pull authorisation. After a pull, retest the three failed tools and one
  enhanced-token edit from Settings, then rotate the externally shared token.
