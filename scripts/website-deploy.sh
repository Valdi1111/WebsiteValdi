#!/bin/bash

set -eo pipefail  # Exit immediately if a command or pipeline exits with a non-zero status
umask 002

# ==========================================
# Configuration Variables
# ==========================================
APP_DIR="/path/to/your/app"
BACKUP_SCRIPT="/path/to/your/script/mysql-backup.sh"
NODE_SERVICES_DIR="node-services"
SUPERVISOR_NODE_SERVICE="website-node-services"
PHP_FPM_SERVICE="php8.4-fpm"
GIT_BRANCH="main"

echo "=============================="
echo "START DEPLOY"
echo "=============================="

cd "$APP_DIR"

echo "Load NVM"
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use default

echo "1) Backup database"
# Execute database backup script
"$BACKUP_SCRIPT"

echo "2) Pull latest code"
git fetch origin
git reset --hard "origin/$GIT_BRANCH"

echo "3) Purge stale Symfony cache"
# Physically remove cache before composer install to avoid ClassNotFound errors
# when bundles, namespaces, or packages have been removed or updated
rm -rf var/cache/prod var/cache/dev

echo "4) Install PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "5) Run Doctrine migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "6) Warmup cache"
# Warmup production cache directly (stale cache was already cleared in step 3)
php bin/console cache:warmup --env=prod

echo "7) Install Symfony assets"
php bin/console assets:install public --no-interaction

echo "8) Install Node dependencies (frontend)"
npm ci

echo "9) Build frontend"
npm run build

echo "10) Install Node dependencies (node-services)"
cd "$NODE_SERVICES_DIR"
npm ci --omit=dev
npx playwright install chromium
cd "$APP_DIR"

echo "11) Restart node services"
sudo supervisorctl restart "$SUPERVISOR_NODE_SERVICE"

echo "12) Restart messenger workers"
php bin/console messenger:stop-workers

#echo "13) Reset OPcache & PHP-FPM"
# Reload PHP-FPM to flush OPcache without dropping in-flight HTTP requests
#sudo systemctl reload "$PHP_FPM_SERVICE"

echo "=============================="
echo "DEPLOY COMPLETED"
echo "=============================="
