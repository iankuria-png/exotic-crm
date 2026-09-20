---
name: prod-debug
description: Diagnose production-only Exotic CRM/WP issues using available read-only evidence, and prepare complete cPanel diagnostics or authorized dry-run/backup/apply/verify repair scripts.
---

# Production diagnosis

Read AGENTS, the matching incident/task and `docs/agent/environment.md`. Inspect actual
tool access first: use relevant read-only MCP, logs or HTTP evidence if available. Do not
assume either full production shell access or no access at all. When data cannot be reached,
give Ian one complete bounded cPanel/phpMyAdmin diagnostic and the exact output needed.

Production CRM root is `~/crm.exotic-online.com/`, logs under `storage/logs/`.
Find the relevant dated/channel log and error window; avoid dumping whole private logs.
Verify the target market's DB engine/prefix; do not hardcode the local WP prefix for all markets.
Prefer complete PHP files over fragile `tinker --execute` shell quoting.

Any authorized production data repair must be a self-contained script with:
1. Dry-run by default: bounded candidates/counts, exact intended changes; explicit apply flag.
2. Backup affected records before mutation; print a usable restore procedure.
3. Apply with appropriate transactions and mutation logging.
4. Re-query and verify expected post-state; report limitations and failures.

Never replace this with a mutation one-liner or infer authorization from an old incident.
Implement a code fix locally and use ship only to the extent commit/push was authorized.
Capture root cause, ruled-out explanations worth retaining and evidence in the relevant task.
