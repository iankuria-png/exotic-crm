# CRM current state

Updated 2026-09-20. Check live Git status/HEAD before editing.
Baseline inspected: `824c76712b46d0834ae712fb6442b992b1e3b0ef`, main.
Existing checkout has unrelated changes: CLAUDE.md modified, a deleted performance
runbook, and many untracked plans/artifacts; the index was empty. Preserve those changes.

- Current request: [shared context foundation](tasks/harness-foundation.md).
- Lifecycle resumption: [code/evidence checkpoint](tasks/lifecycle-recovery-checkpoint.md).
  The archived July handover has been overtaken by implementation: `66371e69`
  (12 Sep) archive recovery, `d0dac577` (17 Sep) operability, `7aaaf703` (19 Sep)
  database-backed locks, and `824c7671` (20 Sep) private-profile lifecycle drift.
  Nearby `03dc03ab` / `8c082123` add offline deletion controls/filters. Neither that archive nor recent Git subjects prove current production state.
- MCP knowledge work is substantially implemented in local Git: `50aa8921` (12 Sep)
  governed knowledge/diagnostics, `912953e3` protocol support, `9b166f5d` staging pipeline,
  `8ebafdd5` staged review; later `9787339f` / `5908591e` add dashboard/preview.
  Use [MCP checkpoint](tasks/mcp-knowledge-checkpoint.md), not the old plan status as a backlog.
- Shared team delivery/open items: WordPress repository `changelog/`, with CRM entries
  separate from WP entries. Retrieve the relevant dates; do not copy the full backlog here.
- MCP implementation handoff (22 Sep): [checkpoint](tasks/mcp-knowledge-checkpoint.md)
  records the re-baselined client-compatibility plan, confirmed OAuth/client-detail
  decisions, live defects to repair first, and the one remaining FX-detail decision.
  No application code, deployment or production mutation occurred in that planning pass.
- MCP implementation checkpoint (22 Sep): the authoritative compatibility plan is
  implemented locally, including 2026 stateless compatibility, legacy adapters,
  CRM-hosted PKCE OAuth, governed intelligence tools, managed aliases and token
  grant edits. Verification is recorded in the MCP checkpoint; it is local only,
  with no commit, push or deployment performed.
- MCP production-defect repair (22 Sep): read-only production evidence attributed
  weekly-scorecard, visitor-demand and city-performance MCP failures plus an
  enhanced token-grant validation mismatch. The authorised local repair and
  verification are recorded in the MCP checkpoint; push status must be checked
  against Git rather than inferred from this note.
- Related WP root: `/Users/ian/Local Sites/exotic/app/public`.

Use [map](map.md) for contracts, decisions, incidents and tools. Historical context is
local under `output/agent-history/2026-09-20/`. No production tests or deployment were
performed for this documentation cleanup. Keep state short; details belong in task records.
