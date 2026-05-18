# SEO Engine — Deployment Checklist

End-to-end procedure for shipping the SEO Profile Optimization Engine to production.
The feature spans three codebases:

- **CRM** (Laravel, `~/Projects/exotic-crm/`)
- **WP plugin** (`exotic-crm-sync` on each WordPress site)
- **WP theme** (`escortwp-child` on each WordPress site)

It is gated by **two independent feature flags** (CRM and WP) plus a CRM platform allowlist.
Even with code shipped, the feature is dormant until both flags are turned on.

---

## 0. Pre-deployment sanity check

Before touching production:

```bash
# CRM tests (run from ~/Projects/exotic-crm/)
/usr/local/opt/php@8.2/bin/php -d memory_limit=512M artisan test tests/Unit/Seo tests/Feature/Seo
```

All 59 tests should pass before deploying.

---

## 1. Deploy CRM code

The CRM lives at `~/Projects/exotic-crm/`. Files added/changed in this release:

**New:**
- `app/Services/Seo/*` — DTOs, scorer, link injector, template engine, LLM adapters, waterfall, bio generation orchestrator
- `app/Http/Controllers/CRM/SeoController.php` — CRM SPA endpoint
- `app/Http/Controllers/CRM/SeoSettingsController.php` — Settings UI backend
- `app/Http/Controllers/Wp/WpSeoController.php` — WordPress-facing endpoint
- `app/Http/Middleware/WpServiceAuth.php` — HMAC middleware
- `app/Jobs/RecomputeSeoScoreJob.php` — async score recompute on WP edits
- `app/Providers/SeoEngineConfigProvider.php` — DB-driven config override
- `resources/js/components/seo/*` — React components
- `resources/js/components/settings/SeoEnginePanel.jsx` — settings UI
- `database/migrations/2026_05_18_000001_add_seo_to_clients.php`
- `tests/Unit/Seo/*`, `tests/Feature/Seo/*`

**Modified:**
- `config/services.php` — `seo_engine` block
- `config/app.php` — register `SeoEngineConfigProvider`
- `app/Http/Kernel.php` — alias `wp.service.auth`
- `routes/api.php` — SEO routes + settings routes
- `app/Models/Client.php` — `seo_score*` fillables and casts
- `app/Services/WpSyncService.php` — `writeSeoScore`, `getLinkCatalog`
- `app/Services/ClientSyncService.php` — score sync + stale recompute dispatch
- `resources/js/pages/ClientDetail.jsx`, `Clients.jsx`, `Settings.jsx`
- `.env.example`

**Deployment steps:**

```bash
# 1. Pull code to production server
git pull origin main

# 2. Install any new composer dependencies (none in this release)
composer install --no-dev --optimize-autoloader

# 3. Install JS dependencies and rebuild SPA bundle
npm ci
npm run build

# 4. Run the new migration (adds seo_score columns to clients table)
php artisan migrate --force

# 5. Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 6. (Optional) Re-cache configs/routes for production performance
php artisan config:cache
php artisan route:cache
```

**Verify the deployment was successful:**

```bash
# These routes should be listed:
php artisan route:list | grep -i seo

# Should output something like:
#   GET    api/crm/settings/seo-engine            ...SeoSettingsController@show
#   PATCH  api/crm/settings/seo-engine            ...SeoSettingsController@update
#   POST   api/crm/settings/seo-engine/test       ...SeoSettingsController@test
#   POST   api/crm/seo/generate-bio               ...SeoController@generateBio
#   POST   api/wp-svc/seo/generate-bio            ...WpSeoController@generateBio
```

---

## 2. Configure CRM via Settings UI

Once code is deployed, an **admin** logs into the CRM and visits:

**Settings → SEO Engine**

The settings panel has 4 sections:

### 2.1 Master toggle (top)
- **Enabled / Disabled** — flip to enabled when ready. Leaves the endpoint refusing requests with 403 when off.

### 2.2 Platform allowlist
- Checkbox each market that should be allowed to call the SEO endpoint.
- Leave all unchecked = allow all platforms (NOT recommended for production).
- Recommended initial state: only ExoticKenya. Add ExoticUganda later once validated.

### 2.3 Provider order
- Reorder the LLM waterfall (try first → fall back). Default is `gemini → claude → openai → deepseek`.
- Providers without a key are auto-skipped at runtime.

### 2.4 Provider API keys
Paste keys for any providers you have. **Keys are encrypted at rest** and never returned by the API (the panel shows only a `xxxxxx…xxxx` preview after save).

- **Anthropic Claude** — best quality. https://console.anthropic.com
- **OpenAI** — solid quality, mid cost. https://platform.openai.com
- **Google Gemini** — free tier available. https://aistudio.google.com
- **DeepSeek** — lowest cost. https://platform.deepseek.com

For each provider, click **Test** to send a one-token "Say OK" request and verify the key works.

Click **Save SEO Engine settings** when done.

> NOTE: Settings stored in the UI override any `.env` values. To remove the DB override and fall back to `.env`, clear the field and Save.

---

## 3. Deploy WP plugin

The plugin lives at `/wp-content/plugins/exotic-crm-sync/` on each WP site.

**Files added in this release:**
- `includes/class-profile-media-summary.php`
- `includes/class-seo-endpoint.php`
- `includes/class-seo-score-endpoint.php`
- `includes/class-link-catalog-provider.php`
- `includes/class-seo-settings-page.php`

**Files modified:**
- `exotic-crm-sync.php` — version bump to 1.3.0, new requires/registrations
- `includes/class-client-endpoint.php` — emits seo_score + media_summary in client payload
- `includes/class-client-sync-endpoint.php` — same for sync payload

**Deploy:**

```bash
# Deploy via your usual mechanism (rsync, SFTP, or git pull on the server)
# After files are in place, no other action is needed — plugin auto-loads new classes.
```

**Verify plugin install:**

In WP admin, visit **Plugins**. "Exotic CRM Sync" version should read **1.3.0** or later.

The new REST endpoints should be reachable (404 if you GET them, but they should exist):

```bash
curl -I https://exotickenya.com/wp-json/exotic-crm-sync/v1/seo/generate-bio
# Expect: HTTP/2 405 (Method Not Allowed for GET — endpoint exists, just expects POST)
```

---

## 4. Configure WP plugin via Settings UI

Wp-admin → **Settings → Exotic CRM SEO**.

Configure:

- **Enable SEO Engine** ✔ (master kill-switch on the WP side)
- **Platform ID** — the numeric ID this WP site has in the CRM `platforms` table. Get it from the CRM by running:
  ```sql
  SELECT id, name FROM platforms;
  ```
  Typically `1` for ExoticKenya, `2` for ExoticUganda.
- **Attribute pages** (optional JSON) — link-injection landing pages. Example:
  ```json
  [
    {"keyword": "GFE escorts", "url": "/services/gfe", "category": "service", "priority": 5},
    {"keyword": "massage", "url": "/services/massage", "category": "service", "priority": 4}
  ]
  ```

Confirm the "Configuration status" table shows green checkmarks for:
- `EXOTIC_CRM_BASE_URL` (defined in `wp-config.php`)
- `EXOTIC_CRM_SYNC_SHARED_KEY` (defined in `wp-config.php`)

If either is missing, add them to `wp-config.php`:

```php
define('EXOTIC_CRM_BASE_URL', 'https://crm.exotickenya.com');
define('EXOTIC_CRM_SYNC_SHARED_KEY', '<same shared key as the CRM is using>');
```

---

## 5. Deploy WP theme

The theme lives at `/wp-content/themes/escortwp-child/`.

**Files modified in this release:**
- `register-independent-personal-info-process.php` — fixed `wp_kses_post` sanitizer, sets `seo_quality_score_stale=1` on save
- `register-independent-personal-information-form.php` — `esc_textarea($aboutyou)`, "Generate Bio" button
- `template-edit-profile.php` — `esc_textarea`, raw read, "Generate Bio" button + score chip
- `functions.php` — `wp_localize_script` for SEO config (only on relevant templates)
- `js/custom-script.js` — DOMContentLoaded handler for `#seo-generate-bio`
- `css/override.css` — styles for `.seo-generate-wrap`, `.seo-score-chip`, etc.

**Deploy via your usual mechanism.** No DB migration on the theme side.

---

## 6. End-to-end smoke test

Once all three are deployed and the feature flag is ON on both sides:

1. Log into the CRM as an admin
2. Navigate to **Clients → choose a profile → Edit Profile tab**
3. Click **✨ Generate Bio**
4. Modal should show a bio + score badge after 5–15s
5. Click **Accept** — bio populates the textarea

Then:

1. Log into the WP site as a regular escort user
2. Visit **Edit Profile**
3. Click **✨ Generate Bio** next to the bio textarea
4. After 5–15s, the textarea should be replaced with new SEO-optimised content
5. The score chip should update (e.g. "78/100" with green styling)

---

## 7. Rollback procedure

If something goes wrong **after** the feature flag is on:

### Quick rollback (no code revert needed)
1. **CRM**: Settings → SEO Engine → uncheck "Enabled" → Save. All routes return 403.
2. **WP**: Settings → Exotic CRM SEO → uncheck "Enable SEO Engine" → Save. Generate Bio buttons disappear.

### Full code rollback
Revert the deployment in each repo. The migration adds three nullable columns (`seo_score`, `seo_score_breakdown`, `seo_score_updated_at`) which are safe to leave in place — no data loss, no behavior change.

---

## 8. Known limitations / future work

- **No integration tests** between the three codebases yet. The CRM unit/feature tests stub the WP plugin REST endpoint. Manual smoke test is the gate.
- **No retry/backoff** on transient LLM provider errors beyond the waterfall — if all four providers fail simultaneously, the deterministic template fallback is used.
- **No rate limiting** on the bio generation endpoint. If a single profile fires multiple Generate Bio requests in quick succession, all are processed.
- **No cost tracking** — the LLM token counts are returned in the response but not persisted.

---

## 9. Cost expectations

Assuming Gemini 1.5 Flash (the recommended primary):
- Input: ~400 tokens per bio (system + user prompt)
- Output: ~250 tokens per bio
- Cost: ~$0.0001 per bio generation
- At 100 generations/day: ~$0.30/month

Anthropic Claude 3.5 Sonnet:
- Same token counts → ~$0.005 per bio
- At 100/day: ~$15/month

Run with Gemini primary for cost; bump Claude to primary in the Settings panel if quality is insufficient.
