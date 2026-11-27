#!/bin/bash

set -eo pipefail  # Exit immediately if a command or pipeline exits with a non-zero status
umask 002

# ==========================================
# Configuration
# ==========================================
# On the server this script and its local, non-versioned configuration (website-deploy.env, see
# scripts/website-deploy.env.dist) live together in a folder owned by the deploy user, /var/www/website-valdi-deploy/.
# That folder must be writable by the deploy user: the last step replaces this script with the repository version
# (self-update), so the script itself is never edited on the server. Set DEPLOY_ENV_FILE to use another file.
SELF="$(readlink -f "${BASH_SOURCE[0]}")"
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-$(dirname "$SELF")/website-deploy.env}"
if [ -f "$DEPLOY_ENV_FILE" ]; then
  # shellcheck source=/dev/null
  source "$DEPLOY_ENV_FILE"
fi

APP_DIR="${APP_DIR:-/path/to/your/app}"
BACKUP_SCRIPT="${BACKUP_SCRIPT:-/path/to/your/script/mysql-backup.sh}"
NODE_SERVICES_DIR="${NODE_SERVICES_DIR:-node-services}"
SUPERVISOR_NODE_SERVICE="${SUPERVISOR_NODE_SERVICE:-website-valdi:website-node-services}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"
GIT_BRANCH="${GIT_BRANCH:-main}"

# Stop before the backup and the git reset when the configuration is missing or wrong
if [ ! -e "$APP_DIR/.git" ]; then
  echo "[ERROR] APP_DIR '$APP_DIR' is not a git checkout: create $DEPLOY_ENV_FILE (see scripts/website-deploy.env.dist)." >&2
  exit 1
fi
if [ ! -x "$BACKUP_SCRIPT" ]; then
  echo "[ERROR] BACKUP_SCRIPT '$BACKUP_SCRIPT' is not an executable file: create $DEPLOY_ENV_FILE (see scripts/website-deploy.env.dist)." >&2
  exit 1
fi

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
# The DB role hierarchy is cached in an application pool under var/share (not removed in step 3):
# clear it so role/hierarchy data migrations take effect immediately.
php bin/console cache:pool:clear core.security_hierarchy.cache --env=prod

echo "6) Warmup cache"
# Warmup production cache directly (stale cache was already cleared in step 3)
php bin/console cache:warmup --env=prod

echo "7) Sync the chat bot commands with Discord"
# Replaces every global slash command of the Discord application with the commands of this version. Skipped with a
# note while DISCORD_BOT_TOKEN is empty; a failure (Discord unreachable, token revoked) only warns: the site itself is
# deployed, and the commands can be synced again by hand with the same command.
php bin/console chat:commands:sync --platform=discord --env=prod || echo " - [WARNING] The Discord commands were not synced: run \"php bin/console chat:commands:sync --platform=discord --env=prod\" again." >&2

echo "8) Install Symfony assets"
php bin/console assets:install public --no-interaction

echo "9) Install Node dependencies (frontend)"
npm ci

echo "10) Build frontend"
npm run build

echo "11) Install Node dependencies (node-services)"
cd "$NODE_SERVICES_DIR"
npm ci --omit=dev
npx playwright install chromium
cd "$APP_DIR"

echo "12) Restart node services"
sudo supervisorctl restart "$SUPERVISOR_NODE_SERVICE"

echo "13) Restart messenger workers"
php bin/console messenger:stop-workers

#echo "14) Reset OPcache & PHP-FPM"
# Reload PHP-FPM to flush OPcache without dropping in-flight HTTP requests
#sudo systemctl reload "$PHP_FPM_SERVICE"

echo "15) Update the deploy script"
# Replace this script with the version just pulled, so changes to it are deployed too.
# The new file is written next to it and moved over it: mv swaps the directory entry to a new inode, while the
# running bash keeps reading the old file it has open. This run therefore finishes with the old code, and the
# new version applies from the next deploy. (Writing over the file in place would change it under the running bash.)
REPO_DEPLOY_SCRIPT="$APP_DIR/scripts/website-deploy.sh"
if [ "$SELF" = "$(readlink -f "$REPO_DEPLOY_SCRIPT")" ]; then
  echo " - Running from the repository copy: nothing to update."
elif install -m 0755 "$REPO_DEPLOY_SCRIPT" "$SELF.new" && mv "$SELF.new" "$SELF"; then
  echo " - $SELF updated (effective from the next deploy)."
else
  # The application is already deployed: report the problem without failing the whole deploy
  rm -f "$SELF.new"
  echo " - [WARNING] Could not update $SELF: its folder $(dirname "$SELF") must be writable by $(id -un) (e.g. /var/www/website-valdi-deploy/ owned by the deploy user). Copy $REPO_DEPLOY_SCRIPT there manually." >&2
fi

echo "=============================="
echo "DEPLOY COMPLETED"
echo "=============================="
