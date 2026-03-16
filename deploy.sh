#!/bin/bash
set -e
cd /home/d9410/crm.exotic-online.com

git pull origin main
composer install --no-dev --optimize-autoloader

# Backup database before migrations — uses Laravel's env parser (handles quoted/special chars)
echo "Backing up database..."
mkdir -p /home/d9410/backups
php artisan tinker --execute="
    \$u = config('database.connections.mysql.username');
    \$p = config('database.connections.mysql.password');
    \$d = config('database.connections.mysql.database');
    echo \"\$u|\$p|\$d\";
" | while IFS='|' read -r DB_USER DB_PASS DB_NAME; do
    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "/home/d9410/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql"
done
echo "Backup saved to /home/d9410/backups/"

php artisan migrate --force
php artisan config:cache
# NOTE: route:cache intentionally omitted — app has closure routes
php artisan view:cache

# Restart queue worker if running (graceful — finishes current job first)
php artisan queue:restart

echo "Deploy complete."
