# Task: client Stories tab (CRM half)

Status 2026-09-24: implemented and committed locally as `ede8b6be`; not pushed, not deployed.
The full brief, WordPress half, verification evidence and per-market deployment steps live in
the WP repository: `/Users/ian/Local Sites/exotic/app/public/docs/agent/tasks/crm-client-story-controls.md`
(WP `86884471e`, changelog `11d9a192d`).

- Backend: `app/Http/Controllers/CRM/ClientStoryController.php`; routes `GET|POST
  /api/crm/clients/{client}/stories[/posting|/{storyId}/moderate|/{storyId}/expire]`.
  Read for every client-profile role; act for `admin`, `sub_admin`, `sales` (`can_manage` in the GET).
- Service: `WpSyncService::getClientStories|moderateClientStory|expireClientStory|setClientStoryPosting`.
  Never cache story rows; WordPress hard-deletes expired stories hourly.
- Records: `TimelineEvent` types `story_moderated|story_expired|story_posting_blocked|story_posting_unblocked`
  and audit actions `client_story_moderate|client_story_expire|client_story_posting_update`.
- UI: `resources/js/components/clients/ClientStoriesTab.jsx`, wired in `ClientDetail.jsx`
  (`?tab=stories`; hidden for agencies; fetched only while the tab is open).
- Verified: `php artisan test --filter=ClientStoryControllerTest` 13 passed; Pint clean;
  `npm run build` OK; real `WpSyncService` call against Local WP (platform 12, post 27955).
  Not browser-checked (local DB has 18 pending migrations; local token minting was refused).
- Next: push on request, cPanel pull (no migration), deploy plugin 1.3.10 + theme per market,
  then smoke-test the tab on a stories-enabled market.

Follow-up 2026-09-24: staff posting from profile media or upload, header "Add story" badge and
icon tabs for Chat/Profile Health, committed locally as `641c8771` (needs exotic-crm-sync 1.3.11 +
theme `upload-handler.php`). Tests 18 passed; see the WP task record for evidence and deploy steps.

Follow-up 2026-09-24: market-wide Stories page (`/stories`: review, live, hottest, brand, settings,
post for an advertiser) committed locally as `aceb3a43`; needs exotic-crm-sync 1.3.12 + theme
`includes/stories/admin.php`. 31 story tests passed; evidence in the WP task record.
