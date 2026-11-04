# CRM bio speed release delivery

Status: scoped commit/push authorized by Ian; shipping in progress. Ian’s cPanel pull and live acceptance remain pending. Target root: `/home/d9410/crm.exotic-online.com`; base commit `8c64050968e97a432adf5c3f6afd3ec035735192`. No migrations, dependency updates, saved SEO-setting changes, WordPress files or payment routes.

## Before delivery

Review `implementation.md`, `release-manifest.json` and both ZIP hashes. The payload contains 21 source files plus 6 frontend build files. Fresh affected-source preimages came from Ops read `20261104T103335Z-d4792-d9410-read-crm-bio-release-read-e4ca`; any subsequent live change invalidates those pins and requires a new comparison.

Ian authorized the scoped commit/push for his cPanel pull. Carry that authorization through shipping; production actions follow the applicable deployment authorization. Keep the unrelated local `WpSyncService` collection guard unstaged; only `WpSyncService-owned.patch` belongs to this task. Existing local build artifacts must also stay intact: use this release's isolated manifest/assets for an authorized commit. Do not include unrelated main-checkout changes or force a push. Shared Ops has pre-existing dirty remote tooling; no apply guard has been bypassed or unrelated work committed.

For a Git/cPanel deployment, prepare an isolated index from current HEAD plus owned source hunks, these four new LLM helper classes, the three scoped test files, and the matching build artifact. Check actual origin/main, pending commits and production preflight before an authorized push/pull. Follow the repository's separate changelog workflow after any commit. The ZIP is an additional review/manual-upload artifact; it has not been installed.

## Installation sequence after authorization

1. Recheck actual affected-source/manifest hashes against the manifest and save private originals. Preserve existing keys, `.env`, database, profile state, old hashed frontend assets and unrelated files. The supplied rollback ZIP contains the observed source/manifest preimages; server-side backups must reflect the actual final preflight.
2. Deliver the four new `app/Services/Seo/Llm/` helper classes first, then the changed adapters/services/controller and config files. Deliver the new hashed assets before replacing `public/build/manifest.json`. Old assets remain available to existing browser sessions. The owned WordPress-sync patch is additive and the shipped full file is based on the verified deployed HEAD, without the unrelated local guard.
3. Rebuild configuration using the server's configured PHP 8.2 runtime. From the CRM root, the required command is:

```sh
php artisan config:cache
```

No new environment variables are required. Defaults in `config/services.php` enable 30-second interactive deadlines, 12-second attempts and latency routing. `config/logging.php` adds the dedicated daily performance channel. No SQL/migration or queue-job rollout is needed for this web-request-only scope. Do not run a migration command as part of this release.

4. Verify actual delivered hashes and the served JS/CSS referenced by the new manifest. Sign in, open Client Detail → Edit Profile, generate a draft with `save:false`, review the result, test cancellation and confirm normal form editing. Use a representative real affected profile only under the normal staff access rules; generation alone does not authorize publishing or saving its bio.
5. Read the corresponding `storage/logs/seo-performance-YYYY-MM-DD.log` entry: expected safe request ID, stage timings, at most two provider HTTP calls, zero reported reasoning tokens on the controlled models and explicit terminal status. Check bounded existing/new error windows without clearing logs. Compare click-to-draft time separately from the provider benchmark. Verify supported-language drafts and retain editor review for short/awkward copy.

## Rollback

Restore the 17 previous source files and old build manifest from the verified originals/rollback ZIP, then rebuild the old configuration with `php artisan config:cache`. Existing old hashed assets were retained, so the old manifest can resolve immediately. The four added helper class files and new hashed assets can remain unused during in-flight request drainage; removal is a separate guarded cleanup, not required for functional rollback. No database rollback is needed.

Do not claim deployment or improved production timings from a local commit, ZIP creation, provider-only benchmark or HTTP 200 alone. Update the task/state/changelog only after actual live acceptance.
