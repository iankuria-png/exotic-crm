# CRM current state

Updated 2026-09-20. Check live Git status/HEAD before editing.
Baseline inspected: `824c76712b46d0834ae712fb6442b992b1e3b0ef`, main.
Existing checkout has unrelated changes: CLAUDE.md modified, a deleted performance
runbook, and many untracked plans/artifacts; the index was empty. Preserve those changes.

- Current request: [profile media metadata backfill](tasks/profile-media-metadata-backfill.md).
- Profile-media metadata (22 Sep): CRM Media-tab UI/build is pushed as `85e3c696`; it awaits a cPanel pull. Local `exotic-crm-sync` commit `f57f18b76` sets image/video attachment titles and Media Library descriptions from profile fields, and refreshes them after relevant profile saves. It is intentionally unpushed/unpackaged. See [task](tasks/seo-media-metadata.md) for verification and the missing WP PHPUnit bootstrap.
- Profile-media metadata backfill (22 Sep): CRM `d3099d4c` is pushed to `origin/main`, adding an admin-only Clients-page repair run with preview, market lock, durable counts/failure notes, and five-profile heavy-queue slices. It introduces one CRM migration (`profile_media_metadata_backfill_runs`) and one authenticated WP metadata endpoint; neither side is deployed or production-tested. See [task](tasks/profile-media-metadata-backfill.md).
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
- MCP App compatibility repair (22 Sep): ChatGPT template fetching exposed that
  the advertised `2025-11-25` adapter was not routed to enhanced UI resources.
  The local repair and regression are recorded in the MCP checkpoint; verify the
  deployed connector separately after any authorised cPanel pull.
- Client story controls (24 Sep): Stories tab + WP story routes committed locally as `ede8b6be` (needs exotic-crm-sync 1.3.10 and the matching theme on each market). Not pushed or deployed. See [task](tasks/crm-client-story-controls.md).
- Related WP root: `/Users/ian/Local Sites/exotic/app/public`.

Use [map](map.md) for contracts, decisions, incidents and tools. Historical context is
local under `output/agent-history/2026-09-20/`. No production tests or deployment were
performed for this documentation cleanup. Keep state short; details belong in task records.
