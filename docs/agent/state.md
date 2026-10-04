# CRM current state

- Wallet rebates (4 Oct): [task](tasks/wallet-rebates.md) — r2 implemented: market revisions/simulator/projection/ledger/performance, exactly-once post-settlement grants/retry/reversal and compiled build. Financial, InnoDB and browser checks pass. New programs Off, Local Kenya Sandbox only. Shipping to origin/main; cPanel pull/migration + separate WP overlay upload and live KES 500 canary remain.

- Database Observatory (2 Oct): base scanner and timed overrides pushed; latest follow-up simplifies setup with Save & test, specific MySQL access errors, and a persistent per-market Load gate toggle (including Critical/missing telemetry). Gate defaults On; additive migration required. Base scanner deployed per Ian; latest follow-up and first successful canary remain unverified in production. See [task](tasks/market-db-scanner.md).
- Guided KYC (27 Sep): implemented locally with guided WP capture, AI policy and reviewer UI. 41 KYC tests / 236 assertions pass; build ready. Ian owns browser QA. AI Off; no push or deployment. See [task](tasks/kyc-guided-flow.md).
- Monetized content (29 Sep): live on Kenya after CRM `bc9ef6ec` / `aea5da64` / `ea2230e5`, WordPress deployment and production configuration. Revision `10/10`, all six protected-delivery checks and owner availability verified; no real-money purchase, restoration or settlement test. The current CRM shipment adds checkout/seller metrics, separate content/pass revenue, searchable/filterable sales, payment/provider detail and visible failure reasons, plus first-visit recovery when a new creator's CRM cache row is missing. The complete suite passes 30 tests / 121 assertions. Deployment still requires the cPanel pull, and Ian owns browser QA. See the [task](tasks/monetized-content.md).

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
- Client story controls (24 Sep): Stories tab + WP story routes pushed as `ede8b6be`; staff posting `641c8771` pushed; market-wide Stories page `aceb3a43` local only (needs exotic-crm-sync 1.3.12 + theme). See [task](tasks/crm-client-story-controls.md).
- Profile URL ownership (24 Sep): provisioning claim + Clients → Profile URLs repair pushed as `a3676c65` (awaiting cPanel pull + migrate); needs migrate and exotic-crm-sync 1.3.13. See [task](tasks/profile-url-ownership.md).
- Bio text integrity (24 Sep): shared PHP/JS detection, editor warnings, save-time accent repair and Clients → Bio text market scan/repair/restore were pushed in `e1ff2d44` (with `65a76176`). Final read-only Local Kenya run checked 2,505 profiles and reduced the earlier 64 hits to 27 genuine invisible/encoding findings after false-positive tuning. Migration passed on MySQL 8. Awaiting cPanel pull/migrate; not deployed. See [task](tasks/bio-text-integrity.md).
- Related WP root: `/Users/ian/Local Sites/exotic/app/public`.

Use [map](map.md) for contracts, decisions, incidents and tools. Historical context is
local under `output/agent-history/2026-09-20/`. No production tests or deployment were
performed for this documentation cleanup. Keep state short; details belong in task records.
