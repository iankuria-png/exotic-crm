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
