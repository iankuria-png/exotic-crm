# Task: shared project context foundation

- ID: harness-foundation; project: exotic-crm.
- Updated: 2026-09-20. Status: foundation complete; local commit authorized on 2026-09-20.
- Goal: consistent instructions, verified facts, curated skills and compact resume records.
- Authorized: reconcile instructions, correct stale facts, curate duplicate skills and
  establish compact state/task records. Follow-up request authorizes local commits to
  the relevant repositories; no push or deployment.
- Plan: WordPress local `output/ai-workflow-audit-2026-09-20/plan.mdx`, reviewed READY.
  This task implements the foundation; verification runners, hooks, CI, connectors and
  schedules are later work, not implicit additions.
- Changes: shared AGENTS with thin Claude adapters; on-demand maps/rules/environment;
  compact state and task records; old instructions/handovers preserved locally.
- Preservation: see [migration record](../migration.md); preserve all existing Git index
  entries and unrelated working-tree files. CRM CLAUDE already had a user deletion of
  the old sprint-status section; do not restore it.
- Verification: local link/import/backup/ignore checks passed; 12 changed skills passed
  quick_validate; an independent fresh-context reader resumed CRM MCP and WP filters using
  current commit ancestry and code. HEAD, index and unrelated Git status were preserved.
- Next: resume the requested application task through state/map. Foundation follow-ups such
  as doctor/verify runners, CI or scheduling require their own implementation request.
- Current-history correction: older handovers are often consumed; current lifecycle/MCP
  implementation commits supersede old not-started/approval labels. Useful lessons are in
  ../lessons.md. Evidence does not establish current production behavior.
- Evidence location: WordPress `output/agent-harness-2026-09-20/` (local only).
- Deployment: documentation/configuration only; no application rollout.
