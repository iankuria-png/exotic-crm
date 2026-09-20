# CRM context map

Read only the relevant row. Relative paths are rooted in this repository.

| Topic | Source |
|---|---|
| Current task | `docs/agent/state.md`, selected `docs/agent/tasks/` record |
| API / permissions | `routes/api.php`, `app/Http/Controllers/CRM/`; inspect middleware for the route |
| Payments / legacy constraints | `app/Billing/`, `app/Services/`, `config/billing.php`, `app/Http/Controllers/API/` |
| Billing rationale | `docs/billing-adr-log-2026-04-03.md`; dated reference, reconcile with code |
| Ownership / people glossary | `/Users/ian/Local Sites/exotic/app/public/docs/agent/contracts.md` (WP-owned contract) |
| Environment / checks / delivery | `docs/agent/environment.md`, `docs/agent/verification.md`, `.agents/skills/ship/SKILL.md` |
| UI | `docs/agent/ui-rules.md`, existing `resources/js/` components |
| Operational diagnosis | `.agents/skills/prod-debug/SKILL.md`, `docs/exotic-production-incident-runbook.md` |
| Lifecycle | `docs/agent/tasks/lifecycle-recovery-checkpoint.md` |
| MCP | `docs/agent/tasks/mcp-knowledge-checkpoint.md`, current `app/Services/Mcp/` and `tests/Feature/Mcp/`; use the existing plan for rationale, not an automatic backlog |
| Changelog / open items | `/Users/ian/Local Sites/exotic/app/public/changelog/` (separate CRM and WP entries) |
| Context migration / decisions | `docs/agent/migration.md`, `docs/agent/decisions.md` |

The private project registry can resolve another local clone path. If a related checkout
is unavailable, report that concrete limitation; do not substitute private model memory as
the contract. April documentation indexes and the archived July handover are historical.

Reusable checkpoint lessons: `docs/agent/lessons.md`. Compare current commits before treating old plans as outstanding.
