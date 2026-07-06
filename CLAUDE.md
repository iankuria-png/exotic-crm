# Exotic Sales CRM

## Project Overview

Sales CRM for ExoticKenya.com built on top of the existing Ads API Laravel codebase. React SPA frontend communicates with Laravel API via Sanctum token auth. Syncs client profiles from WordPress via a custom REST plugin.

## Architecture

```
React SPA (Vite 6)  →  Laravel 10 API (Sanctum)  →  MySQL (shared with Ads API)
                                                   →  WordPress REST API (sync plugin)
```

- **Monorepo:** Single Laravel project serves both the existing Ads API and new CRM routes
- **Frontend:** React 19 + TailwindCSS 4 + TanStack Query 5, served via Vite
- **Database:** `d9410_ExoticManagementDB` — existing Ads API tables + 9 new CRM tables
- **WP Plugin:** `exotic-crm-sync` installed on WordPress for profile read/write via REST

## Key Paths

| What | Path |
|------|------|
| CRM project root | `~/Projects/exotic-crm/` |
| CRM controllers | `app/Http/Controllers/CRM/` |
| Ads API controllers | `app/Http/Controllers/API/` (existing, do NOT break) |
| CRM models | `app/Models/{Client,Lead,Deal,Template,RenewalCampaign,RenewalRun,TimelineEvent,AuditLog,ClientNote}.php` |
| CRM services | `app/Services/{WpSyncService,ClientSyncService,RenewalService,PaymentMatchingService}.php` |
| React entry | `resources/js/app.jsx` |
| React pages | `resources/js/pages/` |
| React components | `resources/js/components/` |
| SPA blade template | `resources/views/crm.blade.php` |
| WP sync plugin | `/Users/ian/Local Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/` |
| Plan document | `~/.claude/plans/zesty-plotting-snowglobe.md` |

## Running Locally

```bash
# Terminal 1: Laravel API (requires PHP 8.2)
cd ~/Projects/exotic-crm
/usr/local/opt/php@8.2/bin/php artisan serve

# Profile media uploads support 50 MB videos. Make sure the PHP process serving
# the API has upload_max_filesize and post_max_size set to at least 64M.

# Terminal 2: Vite dev server
cd ~/Projects/exotic-crm
npm run dev

# Sync WP profiles to CRM
/usr/local/opt/php@8.2/bin/php artisan crm:sync-clients --platform=1 --full
```

Requires Local by Flywheel running (provides MySQL + WordPress).

## Database

- **Connection:** MySQL via Unix socket (Local by Flywheel)
- **DB name:** `d9410_ExoticManagementDB`
- **Socket:** `/Users/ian/Library/Application Support/Local/run/fktJdgfaK/mysql/mysqld.sock`
- **User/pass:** `root` / `root`

### CRM Tables (new)
`clients`, `leads`, `deals`, `templates`, `renewal_campaigns`, `renewal_runs`, `timeline_events`, `audit_log`, `client_notes`

### CRM Columns Added to Existing Tables
- `platforms`: `wp_api_url`, `wp_api_user`, `wp_api_password`, `phone_prefix`, `timezone`, `currency_code`
- `users`: `assigned_market_ids`, `status`
- `payments`: `deal_id`, `client_id`, `match_confidence`, `confirmed_by`, `confirmed_at`
- `clients`: `last_online_at`, `duplicate_of`
- `deals`: `is_free_trial`, `free_trial_approved_by`, `payment_reference`, status enum now includes `renewed`
- `platforms`: `payment_link_providers`

### CRM Performance Indexes
- `timeline_events(entity_type, entity_id, event_type)`
- `payments(deal_id, status)`

## API Routes

- **CRM routes:** `/api/crm/*` — protected by `auth:sanctum` middleware
- **Ads API routes:** `/api/*` — currently public (securing in Sprint 5)
- **CRM login:** `POST /api/crm/login` — returns Sanctum bearer token
- **WP Sync:** `http://exotic.local/wp-json/exotic-crm-sync/v1/*` — Basic Auth with Application Password

## Important Conventions

1. **Never modify Ads API controllers** (`app/Http/Controllers/API/`) without understanding the existing payment flow — STK Push auto-activation is critical production behavior
2. **CRM code goes in `app/Http/Controllers/CRM/`** namespace, not mixed with API controllers
3. **The `clients` table is a CRM-side cache** of WordPress profiles — WordPress remains the source of truth
4. **Phone normalization:** Strip `+`, leading `0` → country prefix (e.g., `0712...` → `254712...`)
5. **WP post type slug:** Stored in WP option `taxonomy_profile_url`, defaults to `"escort"`
6. **Two expiry systems exist in WP:** Unix timestamps (`escort_expire`) and datetime strings (`subscription_start/end`) — the sync plugin reads both

## Tech Stack Versions

| Tool | Version |
|------|---------|
| PHP | 8.2.30 (Homebrew `php@8.2`) |
| Laravel | 10.50.2 |
| React | 19.2.4 |
| Vite | 6.4.1 |
| TailwindCSS | 4.x |
| MySQL | 8.0.35 (Local by Flywheel) |
| Node | 22.x |

## Sprint Status

| Sprint | Status | Key Deliverable |
|--------|--------|----------------|
| Sprint 1: Foundation + Auth | ✅ Complete | 4,794 profiles synced, SPA shell, WP plugin |
| Sprint 2: Clients + Payments + Deals | ✅ Complete | Core lifecycle management, payment matching, subscriptions baseline |
| Sprint 3: Leads + Renewals + Notifications | ✅ Complete | Lead reconciliation, campaign workflows, reminders |
| Sprint 4: Dashboard + Reports + Settings | ✅ Complete | KPI/reporting surfaces, settings workspace, audit visibility |
| Sprint 5: Polish + Testing + Launch | ✅ Complete | Hardening, UX cleanup, regression coverage |
| Sprint 6: Comprehensive Remediation | ✅ Complete | Campaigns rename/config modal, profile health, WP profile/media sync, free-trial + renew flows |

## Deploying (READ THIS FIRST)

**Production is cPanel shared hosting and CANNOT run npm.** Compiled Vite assets in `public/build/` are committed to git. A UI change is NOT deployed until a fresh build is committed.

**This repo is trunk-based: `main` is the deploy branch.** Ian deploys by pulling `origin/main` on cPanel. When Ian says "commit and push", pushing directly to `origin main` is the normal, expected finish step — do not suggest branches or PRs. Never create feature branches.

Ship checklist (use the `ship` skill):
1. `npm run build`
2. `git add -A` (must include `public/build/`)
3. Commit (no Claude co-author trailer) and `git push origin main`
4. Tell Ian: pull on cPanel; list any migrations/seeders to run there

## Production Environment (cPanel)

- App root on server: `~/crm.exotic-online.com/`
- Logs: `~/crm.exotic-online.com/storage/logs/` — `crm_*.log` and `laravel-YYYY-MM-DD.log`
- Ian's only SQL surface on prod is phpMyAdmin — format queries for pasting
- Production WP table prefix: `uapy1_`
- **Any prod data change must be a single self-contained PHP script with DRY-RUN → BACKUP → APPLY → VERIFY stages** (see `prod-debug` skill). Never a bare one-liner.
- `php artisan tinker --execute` breaks on unescaped quotes/parens in bash — prefer writing a temp script file and running it with `php artisan tinker < script` or as an artisan command

## WordPress Sync (context Claude re-learns every session)

- Each market = a WordPress site; the CRM syncs via the `exotic-crm-sync` plugin
- Local plugin path: `/Users/ian/Local Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/`
- Local theme path: `/Users/ian/Local Sites/exotic/app/public/wp-content/themes/escortwp-child/` (note the space in "Local Sites" — always quote)
- **Plugin deploys are manual**: Ian uploads plugin files by hand. Never assume plugin changes are live after committing; CRM changes push to main, plugin changes are handed to Ian.
- Location taxonomy on WP: `escorts-from`
- `platforms` table = markets; primary/subsidiary market pairs exist (e.g. tz/tanzania, rw/rwanda)
- WP is UI-only — storage, KYC, and keys live in the CRM database

## Testing

- Canonical test command: `/usr/local/opt/php@8.2/bin/php artisan test` (plain `php` is NOT 8.2)
- PHPUnit 10: `--verbose` does not exist; use `--filter='TestName'` for scoping
- After editing any PHP file, run `/usr/local/opt/php@8.2/bin/php -l <file>` — smart quotes/backticks from generated code have broken prod before

## Worktrees

- Fresh worktrees have no deps: run `composer install && npm ci` and rebase on `origin/main` before working
- Exclude `.claude/worktrees/` from all `grep`/`find`/`rg` sweeps — sibling worktrees pollute results
- Land worktree work by pushing the branch and fast-forwarding main — never `git checkout main` inside a worktree

## UI Standards (apply to EVERY feature without being asked)

Ian's bar is "worldclass ui/ux" — he should never have to type it. Concretely:
- Every list/table: filters + export button, no navigation required to act on rows
- Every async action: visible progress/health indicator (reference: the SEO engine does this well)
- Every view: designed empty, loading, and error states
- No raw UUIDs/URLs exposed — make them revealable/copyable
- Currency: always converted or hidden, never mixed
- Dashboards: per-widget market switchers (reference: the main dashboard page handles this well)
- Match the existing design language; when in doubt route through the frontend-design/impeccable skills

## Working Style

- Ian gives feedback in numbered batches and keeps adding context — wait for the full batch, ask clarifying questions, then act. No assumptions.
- For multi-phase plans, split execution across subagents/phases rather than one marathon session; write a HANDOVER.md when context runs low (see `handover` skill)
- Never accept auth tokens pasted in chat — auth is preconfigured via `gh auth`/git credential helper; if push auth fails, fix the credential helper, don't ask for a token
