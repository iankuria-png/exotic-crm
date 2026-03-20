# Support Board Sync Timeout Remediation Plan

**Date:** 2026-03-20  
**Status:** Planning only. No product behavior changes are included in this document.  
**Primary goal:** Eliminate production timeout failures on Support Board link sync without changing the underlying matching rules.

---

## 1) Root issue confirmed

The current Support Board link sync runs inside a single synchronous HTTP request:

- the Settings UI posts to `POST /api/crm/settings/integrations/platforms/{platform}/support-board/sync`
- `SettingsController::runPlatformSupportBoardSync()` calls `SupportBoardLinkSyncService::syncPlatform()` directly
- `syncPlatform()` loops through candidates client-by-client and persists matches progressively
- the request does not return until the full loop is complete

This design is the root cause of the production failure:

- some clients are matched because writes happen during the loop
- the browser later receives a timeout (`524`) because the origin did not finish the request in time

This is an execution-model problem, not a matching-logic problem.

---

## 2) What we must preserve

The fix must not regress these behaviors:

- existing phone/email matching rules
- incremental mode meaning:
  - only clients with `sb_user_id IS NULL`
- revalidation mode meaning:
  - recheck all clients in the market
- progressive persistence of successful matches
- per-client error isolation
- current audit semantics for who initiated the run and for which market

The remediation should change **how** the sync runs, not **how matching works**.

---

## 3) Current state summary

### Backend

- `SettingsController::runPlatformSupportBoardSync()` runs synchronously
- `SupportBoardLinkSyncService::syncPlatform()`:
  - counts candidates
  - iterates with `lazyById(100, 'id')`
  - calls `SupportBoardService::resolveClient()` per client
  - sleeps `100ms` per client
  - collects up to 25 error details
- `SupportBoardService::request()`:
  - posts to Support Board with a `20s` timeout

### Frontend

- Settings starts the sync and waits for the single request to finish
- UX is effectively:
  - click run
  - spinner
  - eventual success or failure
- there is no durable progress model
- there is no “leave this page safely” assurance
- there is no resumable run state

### Production outcome

- partial DB success is possible
- final browser-visible failure is possible
- operators cannot tell:
  - whether matching is still running
  - how much completed
  - whether the run stopped
  - which clients failed

---

## 4) Desired end state

The Support Board sync should behave like a background import job:

1. the operator starts a run from Settings
2. the server immediately acknowledges the run
3. the actual work continues in queued background jobs
4. progress is stored durably
5. the UI polls for status and can be left safely
6. duplicate runs for the same market are prevented
7. failures are visible with actionable summary details

This removes the production timeout risk while preserving current matching behavior.

---

## 5) Remediation strategy

## Phase A — Persist sync runs

### Objective

Introduce a durable record for each Support Board sync run.

### New table

Create a dedicated table, for example:

- `support_board_sync_runs`

Suggested columns:

- `id`
- `platform_id`
- `initiated_by`
- `mode`
  - `incremental`
  - `refresh`
- `status`
  - `queued`
  - `running`
  - `completed`
  - `failed`
  - `cancelled` (optional later)
- `candidates`
- `processed`
- `matched`
- `updated`
- `cleared`
- `unchanged`
- `errors`
- `error_details` JSON
- `started_at`
- `finished_at`
- `last_heartbeat_at`
- `reason`
- `meta` JSON

### Why this is required

- gives the UI something durable to poll
- gives operators visibility after page refresh
- gives us a clear audit/progress model
- lets us distinguish timeout-free background processing from request/response behavior

### No-regression note

- this table is additive only
- existing client/support-board fields remain unchanged

---

## Phase B — Move work to queued jobs

### Objective

Run the sync outside the HTTP request lifecycle.

### Design

Add jobs such as:

- `RunSupportBoardSync`
- optional child job:
  - `ProcessSupportBoardSyncChunk`

Recommended approach:

1. Settings endpoint creates a sync-run record
2. endpoint dispatches a queue job
3. endpoint returns immediately with:
   - run id
   - initial status
   - market summary
4. background job updates the run as processing continues

### Chunking recommendation

Do not process the full market in one job if the candidate count is large.

Preferred pattern:

- fetch candidate IDs in chunks of `100` or `250`
- dispatch sequential chunk jobs or process chunk windows inside one queue worker with periodic heartbeat updates

### Why chunking matters

- safer retries
- better visibility
- lower memory risk
- easier partial recovery
- avoids one giant job with opaque failure state

### Matching logic rule

Reuse `SupportBoardLinkSyncService` matching logic rather than rewriting it.

Possible refactor:

- keep `recordOutcome()` and the existing resolve flow
- extract a per-client method:
  - `syncClient(Client $client): array`
- let background jobs call the same core logic

This keeps regression surface low.

---

## Phase C — Add run locking and idempotency

### Objective

Prevent accidental duplicate runs for the same market.

### Rules

- only one active run per platform at a time
- “active” means:
  - `queued`
  - `running`
- if a second operator tries to start another run:
  - return existing run state
  - show a clear message in the UI

### Why

- avoids duplicated API traffic
- avoids confusing counts
- avoids race conditions on the same client set

### Implementation options

- DB-level guard via unique partial logic in code
- cache lock around dispatch
- both, if we want stronger protection

---

## Phase D — Progress API and operator UX

### Objective

Replace the blocking spinner model with durable progress UX.

### New endpoints

Suggested endpoints:

- `POST /api/crm/settings/integrations/platforms/{platform}/support-board/sync`
  - starts a run and returns immediately
- `GET /api/crm/settings/integrations/platforms/{platform}/support-board/sync/latest`
  - returns latest run summary
- `GET /api/crm/settings/integrations/platforms/{platform}/support-board/sync/runs/{run}`
  - returns detailed run state

Optional later:

- `GET /api/crm/settings/integrations/platforms/{platform}/support-board/sync/runs`
  - run history

### Settings UX

Replace the current modal-with-spinner with:

1. confirmation modal
2. immediate transition to a progress card after “Start sync”

### Progress card contents

- market
- mode
- status
- started at
- last updated
- candidates
- processed
- matched
- updated
- unchanged
- errors
- progress percentage
- ETA or “estimating”

### UX copy requirements

Must clearly say:

- `Sync started`
- `You can leave this page. The sync will continue in the background.`
- `Only one Support Board sync can run per market at a time.`

### Completion states

#### Completed

- success banner/toast
- summary metrics
- “View matched clients” CTA

#### Failed

- visible failed state
- top error summary
- expandable recent error details
- “Retry incremental sync” CTA

#### Running

- polling every few seconds
- no blocking modal required once started

---

## Phase E — Failure visibility and observability

### Objective

Make failures diagnosable without browser console screenshots.

### What to capture

At the run level:

- total errors
- first N error details
- last heartbeat
- last processed client id (or equivalent pointer)

At the log level:

- run id
- platform id
- client id on per-client error
- Support Board function that failed
- response status/body excerpt when available

### Why this matters

Today the operator sees:

- partial success
- then a generic request failure

After remediation the operator should see:

- run started
- run progressed
- run failed at a known stage if it fails
- enough context to act without guessing

---

## 6) Recommended rollout order

### Step 1

Add `support_board_sync_runs` table and backend model/resource support.

### Step 2

Refactor sync orchestration into queued jobs while reusing existing per-client matching logic.

### Step 3

Update Settings API to start runs asynchronously and expose run status endpoints.

### Step 4

Replace the current blocking Settings sync UX with background progress UI.

### Step 5

Add run locking and duplicate-start messaging.

### Step 6

Add richer run history and retry affordances if needed.

This order minimizes regression because the matching core is moved last, not rewritten first.

---

## 7) Implementation details by layer

## 7.1 Schema changes

### Add

- `support_board_sync_runs`

### No schema changes required for

- `clients`
- `platforms`
- existing Support Board link fields

Optional later:

- `last_support_board_sync_run_id` on `platforms`

Not required for first pass.

---

## 7.2 Backend changes

### Controller

Refactor `SettingsController::runPlatformSupportBoardSync()` so it:

- validates request
- checks access/configuration
- creates a sync run
- dispatches background work
- returns JSON immediately

### Service layer

Refactor `SupportBoardLinkSyncService` into:

- orchestration-safe pieces
- per-client sync logic
- run result aggregation helpers

Suggested methods:

- `startPlatformRun(Platform $platform, bool $refresh, User $initiator, ?string $reason): SupportBoardSyncRun`
- `processClient(Client $client): array`
- `appendRunOutcome(SupportBoardSyncRun $run, array $outcome): void`
- `markRunFailed(...)`
- `markRunCompleted(...)`

### Jobs

Add one or more jobs for:

- starting the run
- processing chunks
- updating run heartbeats

### Authorization

Keep current admin/sub-admin rules unchanged.

---

## 7.3 Frontend changes

### Settings page

Current issue:

- single request blocks until complete

New behavior:

- `Run sync` becomes `Start sync`
- confirmation modal remains
- after start:
  - close modal
  - show persistent progress card
  - poll latest run status

### Good UX requirements

- page refresh should restore the latest run state
- if the user revisits later, they can still see the latest summary
- if a run is already active, the button should not start a new run
- mobile layout must keep the status readable

---

## 8) Testing requirements

## Backend tests

Add coverage for:

- sync start endpoint returns immediately
- sync run record is created correctly
- duplicate run start for same platform is rejected or reused correctly
- successful background completion updates counters
- failed background run updates status and error details
- incremental mode only targets `sb_user_id IS NULL`
- refresh mode targets all clients in the market

## Frontend tests

Add coverage for:

- start sync button flow
- running state render
- completed state render
- failed state render
- duplicate-run warning state

## Manual verification

Verify on a production-like dataset:

1. start Tanzania incremental sync
2. leave Settings page
3. return later and confirm progress persisted
4. confirm partial matches are visible while run is still active
5. confirm final state is `completed` or `failed`, not a browser timeout

---

## 9) Risks and mitigations

### Risk: matching logic changes accidentally

Mitigation:

- keep core matching functions unchanged
- move orchestration first, not matching rules

### Risk: duplicate jobs for same market

Mitigation:

- add active-run guard and locking

### Risk: queue not configured on production

Mitigation:

- confirm queue driver and worker process before rollout
- if queue worker is not available, do not deploy async orchestration yet

### Risk: operators think sync stopped when page changes

Mitigation:

- explicit copy:
  - `You can leave this page`
  - status polling
  - last heartbeat

### Risk: large error payloads bloat storage

Mitigation:

- cap stored detailed errors
- keep full context in logs

---

## 10) Acceptance criteria

This remediation is complete when all of the following are true:

- starting a Support Board sync no longer depends on one long-lived HTTP response
- production no longer fails with request timeout while the sync is still processing
- operators can leave and return to Settings without losing sync visibility
- only one active run per market can exist at a time
- partial progress and failure reasons are visible in the product
- underlying client matching behavior remains unchanged

---

## 11) Recommended next implementation tranche

Implement only this timeout remediation first:

1. sync run table
2. async job dispatch
3. progress endpoints
4. Settings progress UI
5. duplicate-run protection

Do not combine this tranche with:

- Support Board lead import
- lead-to-client conversion
- additional metadata sync changes

Keeping the timeout fix isolated is the safest way to remove the production failure without introducing unrelated regressions.
