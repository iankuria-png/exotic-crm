# CRM runtime and delivery environment

- PHP: `/usr/local/opt/php@8.2/bin/php` (8.2.30 verified 2026-09-20). Verify the binary
  on another machine. Dependencies/versions come from composer/package lockfiles.
- Local by Flywheel supplies development MySQL and WP. Resolve its active socket;
  historical path `/Users/ian/Library/Application Support/Local/run/fktJdgfaK/mysql/mysqld.sock`
  is a hint, not a permanent setting. Get DB credentials from local configuration privately.
- Local API: `/usr/local/opt/php@8.2/bin/php artisan serve`; frontend: `npm run dev`.
  Start services only when needed for the authorized task; discover existing listeners first.
- Upload tests involving 50 MB videos require at least 64M upload/post limits on the API
  process. Test configuration is in `phpunit.xml` / `playwright.config.js`.
- Production is cPanel at `~/crm.exotic-online.com/`; logs under `storage/logs/`
  (`crm_*.log`, `laravel-YYYY-MM-DD.log`). It cannot run npm; deploy local compiled assets.
- Production DB engine/prefix can differ from Local; verify against the target, not a
  hardcoded WP prefix. phpMyAdmin/cPanel are Ian's documented fallback surfaces.
- Inspect available read-only MCP tools/logs before requesting relayed output. Tool
  presence does not establish working resource discovery or arbitrary production access.
- Prefer complete PHP diagnostic files over heavily quoted `tinker --execute` one-liners.
  Production data writes require the dry-run/backup/apply/verify workflow and authorization.
- Auth uses configured helpers; repair/reauthenticate using the normal login flow if needed,
  never by asking for a secret in chat. Do not expose environment values in reports.
