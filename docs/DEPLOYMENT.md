# ExoticCRM Deployment Guide — cPanel Shared Hosting

## Server Details

| Item | Value |
|------|-------|
| Host | `d9410.lon1.stableserver.net` |
| cPanel user | `d9410` |
| cPanel URL | `https://d9410.lon1.stableserver.net:2083` |
| CRM domain | `crm.exotic-online.com` |
| CRM path | `/home/d9410/crm.exotic-online.com` |
| PHP version | 8.2 (`/opt/cpanel/ea-php82/root/usr/bin/php`) |
| Node version | 22.x (via nvm: `~/.nvm/versions/node/`) |
| Database | `d9410_ExoticManagementDB` (shared with Ads API) |
| GitHub repo | `github.com/iankuria-png/exotic-crm` (private) |

---

## Initial Server Setup (One-Time)

### 1. Create the subdomain in cPanel

Go to **cPanel > Domains** and add `crm.exotic-online.com` pointing to `/home/d9410/crm.exotic-online.com`.

### 2. Set PHP version

Go to **cPanel > MultiPHP Manager**, select `crm.exotic-online.com`, set to **PHP 8.2 (ea-php82)**, click Apply.

### 3. Clone the repository

cPanel pre-creates the directory, so clone to temp and copy:

```bash
git clone https://github.com/iankuria-png/exotic-crm.git /tmp/exotic-crm-clone
cp -a /tmp/exotic-crm-clone/. /home/d9410/crm.exotic-online.com/
rm -rf /tmp/exotic-crm-clone
```

GitHub will ask for credentials. Use your username and a **Personal Access Token** (not your password). Generate one at https://github.com/settings/tokens with `repo` scope.

### 4. Install PHP dependencies

```bash
cd /home/d9410/crm.exotic-online.com
/opt/cpanel/ea-php82/root/usr/bin/php $(which composer) install --no-dev --optimize-autoloader
```

Always use the full PHP 8.2 path. The server default is PHP 8.1 which is incompatible.

### 5. Install Node.js (via nvm)

The server has no Node by default. Install nvm:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
source ~/.bashrc
nvm install 22
nvm use 22
```

### 6. Configure environment

```bash
cp .env.production.example .env
```

Fill in database credentials (same as the existing Ads API):

```bash
# Get credentials from the existing deployment
cat /home/d9410/testing.exotic-ads.com/management/api/.env | grep DB_
```

Then set them:

```bash
sed -i 's/^DB_USERNAME=.*/DB_USERNAME=d9410_ExoticSSP/' .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD='0.S3pWFm@4'/" .env
```

Generate the app key:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan key:generate
```

### 7. Point web root to `public/`

cPanel's document root points to the project root, not `public/`. Add an `.htaccess` in the project root:

```bash
cat > /home/d9410/crm.exotic-online.com/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
EOF
```

### 8. Laravel setup commands

```bash
PHP=/opt/cpanel/ea-php82/root/usr/bin/php
$PHP artisan storage:link
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

### 9. Run migrations

Back up the database first:

```bash
mysqldump -u d9410_ExoticSSP -p'0.S3pWFm@4' d9410_ExoticManagementDB > /home/d9410/db_backup_$(date +%Y%m%d).sql
```

Then run migrations:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --force
```

### 10. Set up the scheduler cron

Go to **cPanel > Cron Jobs**, use **Common Settings → "Once Per Minute"** or type `*` in each time field. Command:

```
* * * * * cd /home/d9410/crm.exotic-online.com && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Notes:**

- Do NOT paste cron syntax directly into the terminal — it must go through cPanel Cron Jobs UI or `crontab -e`.
- Keep exactly one scheduler cron on the server.
- Do **not** add direct `php artisan crm:*` cron entries; all CRM automation must flow through `schedule:run`.
- Keep exactly one queue worker process path for the app. If you use a direct `queue:work` cron or Supervisor entry, do not add a second competing queue worker cron path elsewhere.

### 10.1 Set up Laravel Pulse server monitoring

Pulse itself is available at `/pulse` once the package is installed and migrated. To populate the **Servers** card, run `pulse:check` under Supervisor on each production app server:

```ini
[program:exotic-pulse-check]
command=/opt/cpanel/ea-php82/root/usr/bin/php /home/d9410/crm.exotic-online.com/artisan pulse:check
directory=/home/d9410/crm.exotic-online.com
autostart=true
autorestart=true
user=d9410
redirect_stderr=true
stdout_logfile=/home/d9410/logs/pulse-check.log
stopasgroup=true
killasgroup=true
```

After each deployment, gracefully restart the Pulse daemon so it picks up new code:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan pulse:restart
```

**Notes:**

- Pulse is an operations dashboard, not part of the CRM SPA. Open it directly at `/pulse` or via the link in **Settings → System Health**.
- Keep one `pulse:check` process per server. Do not run multiple competing Pulse daemons on the same app node.

### 11. Fix deploy script for production

The `deploy.sh` needs the correct PHP path and HOME variable:

```bash
# Set HOME for composer
sed -i '1a export HOME=/home/d9410\nexport COMPOSER_HOME=/home/d9410/.config/composer' deploy.sh

# Replace bare 'php' with PHP 8.2 path
sed -i 's|    php |    /opt/cpanel/ea-php82/root/usr/bin/php |g' deploy.sh
sed -i 's|^php |/opt/cpanel/ea-php82/root/usr/bin/php |g' deploy.sh

# Replace bare 'composer' with PHP 8.2 composer
sed -i 's|^composer install|/opt/cpanel/ea-php82/root/usr/bin/php $(which composer) install|' deploy.sh

# Make executable
chmod +x deploy.sh
```

### 12. Configure GitHub integration (for Settings changelog)

Add to `.env`:

```bash
echo "GITHUB_REPO_OWNER=iankuria-png" >> .env
echo "GITHUB_REPO_NAME=exotic-crm" >> .env
echo "GITHUB_TOKEN=<your-github-pat>" >> .env
/opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
```

This enables the **Pending Changelog** and **GitHub Compare** features in the CRM Settings page.

---

## Deploying Updates (Routine)

### Option A: Deploy Button (Recommended)

1. Push changes to `main` on GitHub
2. Go to **CRM Settings** → **Updates** section
3. Click **Deploy Update**
4. Watch the deploy output for success/failure

### Option B: Manual Terminal

After pushing changes to GitHub from your local machine:

```bash
cd /home/d9410/crm.exotic-online.com
git pull
/opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
/opt/cpanel/ea-php82/root/usr/bin/php artisan route:cache
/opt/cpanel/ea-php82/root/usr/bin/php artisan view:cache
```

If composer dependencies changed:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php $(which composer) install --no-dev --optimize-autoloader
```

If database migrations are needed:

```bash
# Always backup first
mysqldump -u d9410_ExoticSSP -p'0.S3pWFm@4' d9410_ExoticManagementDB > /home/d9410/db_backup_$(date +%Y%m%d_%H%M).sql
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --force
```

---

## Local Development Workflow

### Building frontend assets

The server cannot run `npm run build` (Bus error due to memory limits on shared hosting). **Always build locally and push built assets:**

```bash
# On your Mac
cd ~/Projects/exotic-crm
npm run build          # Outputs to public/build/
git add public/build/
git commit -m "Build frontend assets"
git push origin main
```

The `public/build/` directory is tracked in git specifically because the server cannot build.

### Running locally

```bash
# Terminal 1: Laravel API
cd ~/Projects/exotic-crm
/usr/local/opt/php@8.2/bin/php artisan serve

# Terminal 2: Vite dev server
cd ~/Projects/exotic-crm
npm run dev
```

---

## Key Learnings and Gotchas

### PHP version mismatch

The server default PHP is 8.1. Always use the full path:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php
```

Never just `php` in commands — it runs 8.1 and composer/artisan will fail.

### npm run build crashes on server

Shared hosting has strict memory limits. Vite build fails with "Bus error (core dumped)". Solution: build locally, commit `public/build/`, push.

### Shared database with Ads API

The CRM and Ads API (`testing.exotic-ads.com`) share `d9410_ExoticManagementDB`. Any migration that changes existing table columns or renames data **affects both systems**.

Before running migrations that modify existing data:
1. Check what the Ads API code expects (especially PaymentController.php)
2. Patch the Ads API first if needed
3. Back up the database
4. Then run migrations

Example: The CRM migration renamed payment `status = 'success'` to `status = 'completed'`. We had to patch the Ads API's PaymentController to accept both statuses before running the migration.

### Ads API is not a git repo

The production Ads API at `/home/d9410/testing.exotic-ads.com/management/api/` was deployed manually (no `.git` directory). Changes must be applied via file copy or `sed` commands. Always back up files first:

```bash
cp /path/to/file.php /home/d9410/filename.php.bak
```

### cPanel terminal timeouts

The cPanel web terminal disconnects after ~2 minutes of inactivity. For long-running commands, use `nohup`:

```bash
nohup npm install > /tmp/npm-install.log 2>&1 &
tail -f /tmp/npm-install.log
```

### GitHub authentication

The repo is private. Git clone/pull requires a Personal Access Token (PAT):
- Generate at: https://github.com/settings/tokens
- Scope: `repo` (read access is sufficient for pulls)
- Username: `iankuria-png`
- Password: paste the token

### Document root redirect

cPanel sets the document root to the project directory, not `public/`. The root `.htaccess` handles the redirect. Do not delete it.

### Deploy script failures

Common deploy script issues:
- **"HOME or COMPOSER_HOME must be set"** — The web server doesn't set HOME. Fix: add `export HOME=/home/d9410` to top of `deploy.sh`
- **PHP version errors during deploy** — All `php` calls in `deploy.sh` must use `/opt/cpanel/ea-php82/root/usr/bin/php`
- **"Deploy script is not executable"** — Run `chmod +x deploy.sh`

### Data baseline feature

The CRM has a "Data Baseline" setting (**Settings > Data Baseline**) that controls whether legacy data from before the CRM launch date appears in dashboards and reports. When set to "Fresh Start" with a cutoff date, records before that date are hidden in the Dashboard (revenue, recovery queue, KPIs) and Payments page. The baseline affects:
- Dashboard default date range (FROM defaults to cutoff date)
- Payment Recovery Queue stats (awaiting, failed, unmatched counts)
- Payment Review Queue listing
- Payments page listing and summary stats

To change the baseline mode, go to **Settings > Data Baseline** and toggle between "Fresh Start" and "Include Legacy".

---

## Directory Structure on Server

```
/home/d9410/
  crm.exotic-online.com/          # CRM (git-managed)
    .htaccess                      # Redirects to public/
    public/                        # Web root (Laravel + Vite build)
      build/                       # Compiled React assets
    storage/
      app/deployment/              # Deploy script status/logs
  testing.exotic-ads.com/
    management/api/                # Ads API (manually deployed, NOT git)
  db_backup_YYYYMMDD.sql          # Database backups
  PaymentController.php.bak       # Ads API controller backup
```

---

## Rollback Procedures

### Revert a bad deployment

```bash
cd /home/d9410/crm.exotic-online.com
git log --oneline -5              # Find the last good commit
git checkout <commit-hash> -- .   # Restore files
/opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
/opt/cpanel/ea-php82/root/usr/bin/php artisan route:cache
```

### Restore database from backup

```bash
mysql -u d9410_ExoticSSP -p'0.S3pWFm@4' d9410_ExoticManagementDB < /home/d9410/db_backup_YYYYMMDD.sql
```

### Revert Ads API patch

```bash
cp /home/d9410/PaymentController.php.bak /home/d9410/testing.exotic-ads.com/management/api/app/Http/Controllers/API/PaymentController.php
```
