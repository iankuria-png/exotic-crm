---
name: ship
description: Prepare and perform an authorized Exotic CRM commit/push, including matching frontend build assets and the manual cPanel checklist. Use when Ian requests shipping or commit/push for this repository.
---

# Ship the CRM

Read AGENTS and the task's authorized scope. Inspect branch, current index and working
tree before doing anything; main is the expected deploy branch. Keep unrelated staged
content and mixed-file edits intact. Review only task-owned files/hunks; never sweep the
tree with git add -A. If the intended commit cannot be isolated without disturbing others'
staging, report the specific conflict and leave it pending.

For changed frontend sources/config, run `npm run build` and verify public/build represents
the intended sources before staging. Backend-only work does not require a frontend build.
Lint changed PHP with `/usr/local/opt/php@8.2/bin/php -l` and run relevant scoped checks.
Commit only when authorized, with no AI co-author trailer. Push `origin main` only when
the user's scope includes pushing. Check that pending commits included in a push are
understood; a build or task completion is not itself push authorization.

Follow the linked WordPress changelog workflow after commits; preserve repo-specific entries.
Report the commit/push result and cPanel pull checklist, specific migrations/seeders/config
changes if any. WP sync-plugin uploads are separate. Do not claim deployment from a push.
If auth fails use the configured credential helper or normal login; never request a token in chat.
