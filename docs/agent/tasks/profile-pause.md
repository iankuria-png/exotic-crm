# Task: staff can pause (hide) and resume a public profile

- ID / project: profile-pause / exotic-crm + exotic-crm-sync plugin
- Updated / status: 16 Oct 2026 — implemented and tested locally; not committed, pushed or uploaded.
- Goal and acceptance criteria: admin, sub-admin and sales can hide a public profile at the companion's request (even mid-subscription) and bring it back. Plan untouched, clock keeps running (Ian, 16 Oct). Easy: one button + optional reason.
- Scope authorized by the current request: build CRM + small plugin endpoint (Ian chose plugin over direct-DB or Client-access workarounds).
- Source plan / decisions / relevant code: mirrors theme admin toggle `escortwp/single-profile.php` settoprivate (private + `notactive=1`; companion cannot self-republish). Existing plugin routes unusable: `/deactivate` wipes plan, `/lifecycle` keeps posts published, `/update` blocks `post_status`/`notactive`. CRM already syncs `notactive`; ExpiredSubscriptionReconciler and `active()` skip it.
- Done with evidence: plugin `includes/class-profile-visibility-endpoint.php` (`POST /clients/{id}/visibility` action=pause|resume) + 2-line registration in `exotic-crm-sync.php`; 10/10 scenario checks via `wp eval-file` on Local (hide, plan kept, idempotent, refusals, lapsed-plan resume stays private, auth). CRM: migration `2026_10_16_120000_add_profile_pause_columns_to_clients`, `ClientProfilePauseService` (lifecycle lock, re-sync, timeline), `pause`/`resume` routes, audit actions, reactivation SMS excludes paused, UI Paused badge/timeline/buttons. `ClientProfilePauseTest` 14 passed/64 assertions; `LifecycleSms` 28 passed; vite compile OK.
- In progress / remaining: commit/push on Ian's word; plugin version bump + CHANGELOG at ship; plugin upload per market (CRM reports `plugin_update_required` until then); `php artisan migrate` on cPanel.
- Next concrete action or command: ship (CRM) and decide plugin packaging — WP working tree holds another session's unreleased 1.3.19/1.3.20 VVIP plugin changes, so a zip from it would include them.
- Verification: see above; source HEAD 9121f794 + uncommitted task files.
- Deployment: local only.
- Files/hunks owned: CRM — new service/test/migration/task file; hunks in ClientController (pause/resume actions, ensureCanPauseProfile, profileVisibilitySnapshot, import), routes (2 lines), Client model, CrmAuditAction, WpSyncService, LifecycleSmsService, ClientDetail.jsx, clientProfileState.js, StatusBadge.jsx, Timeline.jsx. WP — new endpoint file + 2 registration lines. Preserve other sessions' wallet/VVIP/rebates changes.
- Unresolved questions / blockers: plugin packaging vs unreleased VVIP work (above).
