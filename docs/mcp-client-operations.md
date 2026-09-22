# MCP client operations

## Connection paths

- **ChatGPT:** create a remote MCP app/connector, enter the public HTTPS MCP
  endpoint, select OAuth, and sign in to CRM when prompted.
- **Claude remote connector:** add a custom web connector, enter the same
  endpoint, and complete the CRM OAuth consent screen.
- **Claude Code:** optional technical fallback using an administrator-minted
  bearer token. Do not use this fallback for ChatGPT or Claude remote connectors.

The public discovery documents are available at
`/.well-known/oauth-protected-resource` and
`/.well-known/oauth-authorization-server`. OAuth is authorization-code with
S256 PKCE, exact registered redirect URIs, short-lived access tokens, rotating
refresh tokens and revocation. The browser authorization endpoint uses the
existing first-party CRM web session; users sign in to CRM before approving a
remote connector.

## Operator checks

1. In Settings → MCP, confirm the server is enabled and run the self-test.
2. Connect using OAuth and confirm `server/discover`, `tools/list`, and one
   permitted read-only tool call work.
3. Use Settings → MCP → Tokens to amend a managed-token capability. The same
   secret remains valid; the redacted grant audit records the change.
4. Assign active staff a stable word-style alias in Data & Privacy before their
   performance can be shown through MCP.
5. Review the Activity tab for refusals, budget use and latency. Revoke a token
   or OAuth refresh token when a connection is no longer required.

## Incident and rollout posture

MCP does not deploy itself. Local verification, a commit/push, manual cPanel
deployment, and observed production behavior are separate states. Before any
production rollout, use restricted non-production users to connect both remote
clients and compare one question from every intelligence family against CRM.
Verify revocation immediately blocks access, named-client governance is audited,
and no MCP token works on normal CRM endpoints.
