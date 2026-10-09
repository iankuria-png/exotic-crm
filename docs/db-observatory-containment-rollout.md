# Database Observatory containment — rollout

This release adds bounded account locking, application-key revocation, session logout, stale user-level correction, supported hidden-widget-link removal and independently confirmed dangling-plugin-entry deactivation. Admins receive exact previews, short approvals, encrypted backups, separate database/cache verification, recoverable operation history and separately confirmed Restore. Campaign approval freezes the complete member set. Restricted Linux filesystem diagnostics, quarantine and Restore are separate from database coverage.

All global and per-market action switches default Off. Installing the code and importing private configuration do not authorize or execute a containment action. WordPress adapter upload, trusted runtime/catalog checks and named canary evidence are separate deployment steps.

## CRM deployment

Install the complete release through the normal CRM Git deployment, including its matching compiled frontend. Then run in the CRM cPanel Terminal:

```bash
cd ~/crm.exotic-online.com
php artisan migrate --force
php artisan crm:db-scan-sync-packs
php artisan config:cache
php artisan queue:restart
php artisan help crm:db-containment-provision
```

The additive migration creates CRM operation journals, encrypted market configuration, outbox, backup records and coordinator leases. It does not modify market schemas. The scheduler consumes the separate `db-containment` queue on `database_long`. Scanning remains read-only and coordinates with pending/active containment without stealing paused scan claims.

Retain these CRM environment defaults during setup:

```dotenv
DB_CONTAINMENT_ENABLED=false
DB_CONTAINMENT_FILESYSTEM_ENABLED=false
DB_CONTAINMENT_QUARANTINE_ENABLED=false
```

## Private configuration

`tools/db-containment/init-config.py` generates a new private 0700 directory containing 0600 files. Use one directory for each market and its actual CRM platform ID. It refuses an existing directory rather than replacing keys.

- `crm-env.txt`: global backup-vault key and Off switches. Establish the vault key once for the entire CRM; preserve the existing key when adding another market. Retain key versions required by outstanding backups/recovery.
- `market.json`: that market's Off configuration and independent cache/SSH signing material. Edit only verified settings and upload outside every served root.
- `wp-signing.php`: that market's WordPress platform/key-version/signing constants; install privately outside served roots.
- `signing.key`: restricted filesystem helper signing material, used during separate host onboarding.

Do not log, print, commit or place the generated secrets in the deployment archive.

For the first Kenya rollout, live CRM catalog identifies platform 1. Ian has already generated matching private files locally and uploaded `market.json` under the CRM account's `.exotic-containment/kenya/`. After the complete code/migration deployment:

```bash
cd ~/crm.exotic-online.com
chmod 700 "$HOME/.exotic-containment" "$HOME/.exotic-containment/kenya"
chmod 600 "$HOME/.exotic-containment/kenya/market.json"
php artisan crm:db-containment-provision 1 "$HOME/.exotic-containment/kenya/market.json"
```

Expected output starts `Encrypted containment configuration saved for market 1.` Import remains Off. A missing command means the release code is not installed/registered; successful `config:cache` alone does not prove deployment.

The writer accepts MySQL 8 and MariaDB 11.4, verifies single-site schema/URLs/prefix, InnoDB/PK/columns, and refuses touching triggers or incoming/outgoing foreign keys. Exhaustive metadata visibility currently requires verified global SELECT/TRIGGER access on a trusted catalog connection. Configure `catalog_user`/`catalog_password` independently; do not broaden the website login. If hosting cannot supply the required proof, writes remain unavailable. Writer site credentials come from the market Platform, independently of the scanner's reader credentials.

## WordPress adapter

Containment support belongs to `wp-content/plugins/exotic-crm-sync/`; uploading a child theme does not install it. Upload:

- `includes/class-containment-cache-endpoint.php`
- `bin/verify-containment-cache.php`
- `bin/containment-session-canary.php`
- The target's existing `exotic-crm-sync.php`, patched with CRM `tools/db-containment/apply-cache-loader.py` and PHP-linted locally. Preserve the target plugin version and unrelated endpoints.

Store that market's matching `wp-signing.php` privately, mode 0600, and require it from `wp-config.php` before WordPress startup using the actual private path. The adapter installs its versioned replay ledger on upgrade/init when signing is configured. Unknown requests reconcile without repeating invalidation. Known failed checks can obtain fresh read-only verification.

Trusted WordPress/runtime/object-cache behavior must be established independently. After Ian names and approves a synthetic `containment-canary-*` subscriber with no existing sessions, run the supplied canary with positional arguments `user=ID confirm=DOMAIN output=PRIVATE_PATH`. It defaults to dry-run. Only the separately approved positional `apply` creates/deletes the synthetic session and proves its token and cookie fail afterward. Verify the signed proof before setting `cache_runtime_trusted=true` and `session_canary_verified_at`; session actions require evidence within 24 hours.

Widget/plugin changes additionally require real host-specific `exotic_containment_public_cache_verified` and observation-only `exotic_containment_public_cache_verified_readonly` adapters. Missing public cache proof keeps results cache pending and findings uncontained.

## Separate filesystem onboarding

Install `host-helper.py` and the matching signing file outside every served root. Enforce restricted per-account SSH with a forced helper command, no general shell/PTY/forwarding, and pinned host fingerprint. Provision private SSH key/known_hosts paths and exact registered site identity in encrypted CRM configuration. `register-root.py` previews by default, binds official version-specific core inventory and config hash, and does not register sibling/addon sites automatically. Registration apply does not enable quarantine.

Begin with read-only diagnostics. After database canary evidence and separate quarantine approval, enable only the named root/allowlisted paths and matching host/per-market/global switches. Verify quarantine and separately confirmed Restore on an agreed disposable file. Linux helper requires same-filesystem no-clobber rename, no-follow/single-link identity, preserved metadata/private journal and fsync. Uncertain host intents refuse automatic replay and require independent private terminal reconciliation. No file destruction is provided.

## Recovery and rollout checks

Ian owns browser QA. Local fixture tests do not establish deployed cache/SSH enforcement. Verify the actual installed command, migrations, UI and signed adapter, then the named database canary before wider rollout.

Unknown database commit outcomes compare current rows with immutable encrypted backups; they never blindly repeat a write. Cache pending and unknown operations pin their market and backup while releasing global writer capacity. Restore is an independent preview/approval/backup operation and refuses changed affected fields while allowing unrelated edits.

To stop new actions, disable switches and cancel waiting work. Keep code/workers, vault keys, journals, encrypted backups and host quarantine available for recovery. Do not drop operation tables or discard keys. Seven-day backup retention excludes pending/recovery-pinned operations and never destroys quarantined files.
