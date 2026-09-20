# Context migration — 2026-09-20

| Previous rule/source | Canonical destination |
|---|---|
| Root AGENTS architecture, Ads/STK, billing, style, hotspots | `AGENTS.md` |
| CLAUDE people glossary / blanket WP UI-only claim | Corrected ownership contract linked by `docs/agent/map.md` |
| CLAUDE local runtime/socket/upload, production/logs/diagnostics | `docs/agent/environment.md` |
| CLAUDE tests and UI | `docs/agent/verification.md`, `docs/agent/ui-rules.md` |
| Main-only vs worktrees | `AGENTS.md`: main default; explicitly authorized existing worktree exception, no implicit push |
| Broad git add -A | `AGENTS.md` and portable ship skill: preserve index and task-owned hunks |
| CLAUDE-only ship/prod-debug | Portable `.agents/skills/` sources; old `.claude/skills/` paths are local adapters |
| July handover says restore not started | `tasks/lifecycle-recovery-checkpoint.md`: code/tests exist, pass/deploy status unverified |
| Old routes/versions/table-count summaries | Existing source/config/lockfiles via `docs/agent/map.md`; historical snapshot remains local |

The pre-existing deletion of CLAUDE's sprint-status section remains deleted. The original
working copy (including that user edit) is preserved under `output/agent-history/2026-09-20/`.
Unrelated runbook deletion, plans/artifacts and index state are preserved.

`.gitignore` now permits the sanitized root AGENTS contract and excludes only the new local
agent-history/agent-runs output paths. Shared `.agents/skills` files are eligible for version
control; `.claude/` remains locally excluded. Ian authorized local commits on 2026-09-20; shared files belong in the scoped foundation
commit. Use Git history for its revision. No blanket force-add or unrelated staging is permitted. Local archives/private personal configuration stay local.

Guarded backups and validation live in the WordPress workspace's
`output/agent-harness-2026-09-20/`. Rollback restores selected pre-migration files after
checking for later edits; no DB/application rollback is needed for this foundation.
