# Staff profile lock (15 Nov 2026)

## Goal
Let CS lock profiles from the Clients bulk bar and the client page. A lock holds the
profile in the Expired lifecycle state (published, indexed, contacts hidden, editing
locked) with a reason, reusing the WordPress lifecycle path SEO Recovery already uses.

## Product decisions (Ian, 15 Nov)
- Paid profiles: the plan keeps running while locked. Background repair and client sync
  must not undo the lock. A new successful activation (payment/renewal) ends it. Staff
  can Unlock at any time: paid → Active again; unpaid → hold lifted, stays Expired.
- Roles: admin, sub_admin, sales, field_sales. Offline (private) profiles can be locked;
  that republishes them as locked pages, with SEO Recovery safety guards (high-risk,
  duplicate, bad close reason, never-live, paused and removal-requested are refused).

## Implementation (source HEAD 0ff7cfb4 + uncommitted)
- Migration `2026_11_15_120000_add_profile_lock_columns_to_clients` (4 nullable columns).
- `ClientLockReason` catalogue; `ClientLifecycleService::lockPlan/lock/unlock`;
  `ClientProfileLockController` (reasons, bulk preview, bulk slice ≤10, lock, unlock).
- Shields: `ClientSyncService` (single + bulk) keeps a locked paid profile Expired while
  WP reports the restriction and clears the lock when WP returns Active;
  `ActiveSubscriptionProfileRepairService` skips locked profiles except lock-releasing
  sources; `crm:archive-expired` skips locked profiles.
- UI: `LockProfilesDialog` (preview tiles, reason cards, chunked progress, retry), Clients
  bulk action + "Locked by staff" recovery-source filter, client page Lock/Unlock button
  and banner, timeline labels, Locked status badge. `DataTable` bulk actions accept `className`.

## Verification
- `artisan test --filter=ClientProfileLockTest`: 10 passed / 65 assertions.
- Isolated Playwright fixture `tests/browser/profile-lock.spec.js` (crm-fixture-vite, 5176):
  4 passed at 1440/390px; screenshots in `output/profile-lock-2026-11-15/`.
- Neighbouring suites (ProfileLifecycle, LifecycleRestore, LifecycleArchiveRecovery, ClientProfilePause,
  RepairActiveSubscriptionProfiles, ClientSync*, ExpiredSubscriptionReconcile, ClientIndexListing,
  ClientLifecycleFunnelAndSegmentation): 103 passed / 559 assertions (738s).

## Release status
- PUSHED 15 Nov: origin/main = 5bce002c (parent e940ae8f). Not deployed.
- Released as two commits on main: first `Land the live flexible amounts and pay link
  integration` (the 80-path Kenya overlay, byte-for-byte from the 15 Nov Ops restoration
  snapshot; its rebuilt JS is byte-identical to live `app-C0GQYIIZ.js`), then this lock commit
  with one combined build (live UI + lock). Unfinished local wallet-payment work stays uncommitted.
- Deploy: a plain `git pull` refuses because the server's overlay files are uncommitted local
  changes. Verify server files equal the landing commit, then fast-forward with a hard reset to
  origin/main, run the migration, refresh caches. WordPress plugin unchanged.

## Next
- First live check after deploy: lock one paid Kenya profile, confirm contacts hidden publicly,
  then Unlock.
