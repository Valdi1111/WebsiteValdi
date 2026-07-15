#!/bin/bash

set -e  # stop se qualcosa fallisce
umask 022

echo "=============================="
echo "START DEPLOY"
echo "=============================="

cd /path/to/your/app

echo "Load NVM"
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use default

echo "1) Backup database"
# Lancia script backup
/path/to/your/script/mysql-backup.sh

echo "2) Pull latest code"
git fetch origin
git reset --hard origin/main

echo "3) Install PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "4) Run Doctrine migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "5) Clear and warmup cache"
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

echo "6) Install Symfony assets"
php bin/console assets:install --no-interaction

echo "7) Install Node dependencies (frontend)"
npm ci

echo "8) Build frontend"
npm run build

echo "9) Install Node dependencies (node-services)"
cd node-services
npm ci --production
npx playwright install chromium
cd ..

echo "10) Restart node services"
sudo supervisorctl restart website-node-services

echo "11) Restart messenger workers"
php bin/console messenger:stop-workers

#echo "12) Restart PHP-FPM"
#systemctl restart php8.4-fpm

echo "=============================="
echo "DEPLOY COMPLETED"
echo "=============================="