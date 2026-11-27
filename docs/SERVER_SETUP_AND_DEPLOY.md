# Infrastructure, First Installation, Updates & Shared Folder

This guide describes the application stack architecture, first-time server setup (Ubuntu/Debian or WSL), the production update/deploy workflow, and how the shared media folder is configured and used.

---

## 1. How the Stack Works

The project is a modular **Symfony 8.1** application with a **React 19 + Ant Design 6** frontend and real-time updates powered by **Mercure (SSE)**:

| Layer | Technology | Role |
| :--- | :--- | :--- |
| **Web Server & TLS** | **Caddy + Mercure Hub** (`mercure` binary) | Automatic HTTPS reverse proxy (Let's Encrypt in production, internal CA `local_certs` for `.wip`/`.local` domains), PHP-FPM through FastCGI with files (videos, EPUBs, downloads) handed back to Caddy via `X-Accel-Redirect`, so a stream does not keep a PHP-FPM worker busy, and built-in SSE hub on `mercure.<domain>`. |
| **PHP Backend** | **PHP 8.5 (`php8.5-fpm` & CLI)** + **Symfony 8.1** | REST APIs, database-backed security & permissions, Doctrine ORM 3, Messenger (async queues), and Scheduler (recurring jobs). |
| **Database** | **MySQL / MariaDB** | Application entities, Symfony Messenger `doctrine://default` queues, and sessions. |
| **Frontend Build** | **Webpack Encore** + **React 19** + **Ant Design 6** | Compiles per-bundle JS/CSS entry points into `public/build/`. |
| **Media Processing** | **FFmpeg / FFprobe** + **yt-dlp** | Video stream extraction, muxing, and asynchronous anime episode downloads. |
| **Node Microservice** | **Node.js 20 LTS** + **Express** + **Playwright** (`node-services/`) | Local HTTP service (port `NODE_SERVICES_PORT`, e.g. `3003`) using `p-queue` and headless Chromium for AnimeWorld scraping/extraction. |
| **Process Manager** | **systemd** (`caddy.service`) + **Supervisor** | `systemd` manages Caddy/Mercure; Supervisor keeps `node-services`, Messenger workers, and Schedulers running. |

### Subdomain Routing
With `DOMAIN_NAME=valdi.ovh` (or a local development domain), Caddy routes traffic to the Symfony application across the subdomains listed in `SUBDOMAINS`:
- `anime.<domain>` — Anime & Manga tracker + episode downloader
- `books.<domain>` — E-Book library & EPUB reader
- `games.<domain>` — Game automation and tracking (one tab per provider: HoYoverse / HoYoLAB, Gryphline / SKPort, Perfect World / NTE), see [`GamesBundle`](../src/GamesBundle/README.md)
- `videos.<domain>` — Video file manager & streaming
- `mercure.<domain>` — Mercure SSE hub (with a status landing page at `/`)

---

## 2. First Installation

### 2.1 Clone the Repository
Clone the repository to your target root directory (e.g. `/var/www/valdi.ovh`):

```bash
git clone <repository-url> /var/www/valdi.ovh
cd /var/www/valdi.ovh
```

### 2.2 Automated Web Server, PHP, Caddy/Mercure & Media Tools Setup
The [`scripts/setup-server.sh`](../scripts/setup-server.sh) script automates the core server provisioning:

```bash
sudo ./scripts/setup-server.sh
```

What it interactively configures:
1. **Local domain resolution & ports:** for local domains (`.wip`, `.local`, `.test`, etc.) it updates `/etc/hosts`; stops and disables conflicting web servers (`apache2`, `nginx`, `lighttpd`) listening on ports `80`/`443`.
2. **User groups:** adds `root` and the current user (`$SUDO_USER`) to the `www-data` group.
3. **PHP 8.5 (`FPM` + `CLI`):** detects or installs via the `ondrej/php` PPA `php8.5-fpm`, `php8.5-cli`, `php8.5-common` (it brings `exif`, which applies the orientation of uploaded profile images), and required extensions (`ldap`, `imap`, `readline`, `imagick`, `mbstring`, `mysql`, `curl`, `zip`, `xml`, `intl`), sets the default CLI binary, and writes `/etc/php/8.5/{fpm,cli}/conf.d/99-custom.ini` (`Europe/Rome`, `memory_limit = 4G`, `max_execution_time = 300`; FPM also gets `upload_max_filesize = 256M` and `post_max_size = 260M`).
4. **External tools:** installs or updates `composer` in `/usr/local/bin/composer`, `ffmpeg`/`ffprobe`, and `yt-dlp` in `/usr/local/bin/yt-dlp`.
5. **Caddy + Mercure Hub:** downloads the latest `mercure` release to `/var/www/caddy/mercure`, grants `CAP_NET_BIND_SERVICE` for non-root binding on ports `80`/`443`, generates HS256 JWT keys (`MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY`), generates `/var/www/caddy/Caddyfile` (the hub, plus the application through PHP-FPM; the files PHP sends with `X-Accel-Redirect` are served by Caddy, which runs as `www-data` and so reads the media folders as PHP-FPM does; every response of the application and of the hub gets the security headers of the `security_headers` snippet, `Strict-Transport-Security` only on public domains), and registers `/etc/systemd/system/caddy.service`.
6. **Project permissions:** sets `owner:www-data`, enables the `setgid` bit (`g+s`) on directories, and configures **default POSIX ACLs** (`setfacl`) on the project root so new files created by PHP/Symfony or the user keep `rwX` permissions.
7. **Final summary & WSL notes:** prints the `MERCURE_*` environment variables to add to `.env.local` and, when running inside WSL, prints `netsh portproxy` / `.wslconfig` instructions for Windows.

### 2.3 Install Node.js 20 LTS
To install Node.js 20 LTS, `npm`, and `npx` under `/opt/node` (with shared permissions for `www-data` and the local user):

```bash
sudo ./scripts/node-install.sh
```
*(Alternatively, you can install NVM under the deploy user's `$HOME/.nvm`, as expected by `website-deploy.sh` and by the `node-services` Supervisor program generated by `setup-supervisor.sh`.)*

### 2.4 Configure `.env.local`
Create `.env.local` at the project root based on [`.env`](../.env) and configure at least:
- `APP_ENV=prod` and `APP_SECRET=<random-secret>`
- `DATABASE_URL="mysql://..."`
- `DOMAIN_NAME="valdi.ovh"` (or your local domain)
- `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` (printed at the end of `setup-server.sh`)
- Shared folder paths: `ANIME_BASE_FOLDER`, `ANIME_TEMP_FOLDER`, `VIDEOS_BASE_FOLDER`
- External API credentials (MyAnimeList, AniList, AnimeWorld) and notification transports (`TELEGRAM_DSN`, `DISCORD_DSN`)
- Discord application for "Link Discord" on the profile page *(optional, disabled while empty)*: `DISCORD_CLIENT_ID` and `DISCORD_CLIENT_SECRET` (the secret preferably in the secrets vault). In the [Discord Developer Portal](https://discord.com/developers/applications), OAuth2 > Redirects, register `${DEFAULT_URI}/profile/discord/callback` exactly (e.g. `https://valdi.ovh/profile/discord/callback`): the callback is on `DEFAULT_URI` whatever subdomain the user starts from. A development installation registers its own URI (e.g. `https://valdi.wip:8443/profile/discord/callback`), in the same application or, better, in a separate one
- Discord chat bot (slash commands of the same application) *(optional, disabled while empty)*: `DISCORD_PUBLIC_KEY` (General Information > Public Key, hex) and `DISCORD_BOT_TOKEN` (Bot > Token, preferably in the secrets vault). The public key verifies the signature of every interaction: while it is empty, `/discord/interactions` answers 404. The bot token is only used by `chat:commands:sync`, which the deploy runs (see [§3.2](#32-deployment-pipeline-steps)): while it is empty, the commands are not registered. Then, in General Information > Interactions Endpoint URL, set `${DEFAULT_URI}/discord/interactions` (e.g. `https://valdi.ovh/discord/interactions`). Discord checks that URL when it is saved, with a signed PING and with an invalid signature, and refuses it unless the site answers both correctly: deploy the release and set `DISCORD_PUBLIC_KEY` before saving it (it is read at runtime; if the environment was compiled with `composer dump-env prod`, run it again). The answers are sent by the `core_chat` workers (see [§2.7](#27-configure-supervisor-services)). A development installation needs a public HTTPS URL for its endpoint (e.g. a tunnel) and, better, a separate application, since an application has one endpoint and one set of global commands
- Telegram bot for "Link Telegram" on the profile page *(optional, disabled while empty)*: `TELEGRAM_CLIENT_ID` and `TELEGRAM_CLIENT_SECRET` (the secret preferably in the secrets vault), from the BotFather mini app > the bot > Login Widget. It must be the bot of `TELEGRAM_DSN`, which sends the notifications: the client id is the part of its token before `:`, otherwise linking stays disabled. In the same Login Widget section, add `${DEFAULT_URI}/profile/telegram/callback` to the Allowed URLs (e.g. `https://valdi.ovh/profile/telegram/callback`). A development installation uses its own bot, with its own `TELEGRAM_DSN` and its own Allowed URL
- Node microservice port: `NODE_SERVICES_PORT=3003` (and `node-services/.env.local` if overriding its default port `3000`)

`DATABASE_ENCRYPTION_KEY` *(optional)* encrypts the `encrypted_text` columns, today the game account credentials and the API token values. It belongs in the secrets vault, with `.env.local` as the alternative: see [§3.4](#34-database-encryption-optional), also for an installation that already has data in those columns.

### 2.5 Setup Shared Folder
Configure the shared folder used for anime downloads, videos, and book libraries (see [Section 4](#4-shared-folder-media--file-managers)):

```bash
sudo ./scripts/setup-shared-folder.sh /media/xyz
```

### 2.6 Application Build & Database Initialization
```bash
# PHP dependencies & database migrations
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:warmup --env=prod
php bin/console assets:install public

# Frontend build (Webpack Encore)
npm ci
npm run build

# Node microservice dependencies & Playwright browser
cd node-services
npm ci --omit=dev
npx playwright install chromium
cd ..
```

### 2.7 Configure Supervisor Services
All background processes (Messenger workers, schedulers and the `node-services` server) run under Supervisor, in a single group `website-valdi` defined by one generated file, `/etc/supervisor/conf.d/website-valdi.conf`:

```bash
sudo scripts/setup-supervisor.sh
```

The script ([`scripts/setup-supervisor.sh`](../scripts/setup-supervisor.sh)):
1. installs and enables Supervisor if it is missing;
2. asks for the project path (default: the parent folder of `scripts/`) and the deploy user (default: the user running `sudo`, home read with `getent`);
3. renders one program per line of [`scripts/supervisor/programs.list`](../scripts/supervisor/programs.list) from the templates in [`scripts/supervisor/`](../scripts/supervisor/), then appends a `[group:website-valdi]` section listing them;
4. keeps a copy of the previous file in `website-valdi.conf.bak`;
5. offers to move the other `/etc/supervisor/conf.d/website-*.conf` files (the old per-program files, including hand-made `*_pulls` workers) to `/etc/supervisor/conf.d.backup-<date>/`, so no program is defined twice;
6. creates `var/log` if missing, then runs `supervisorctl reread`, `update` and `status`.

It can be run again at any time: unchanged settings produce the same file. `--output FILE` only renders the configuration into `FILE` (no root needed), useful to review it.

**Program list** — one line per program, `type|name|queue|options|numprocs`:

| Type | Template | Program name | Example |
| :--- | :--- | :--- | :--- |
| `node` | `node.conf.tpl` | `website-<name>` | `node\|node-services\|\|\|` (runs as the deploy user, through NVM in `<deploy home>/.nvm`) |
| `messenger` | `messenger.conf.tpl` | `website-messenger-<name>` | `messenger\|core\|core_async\|--limit=100 --time-limit=3600\|` |
| `messenger-multi` | `messenger-multi.conf.tpl` | `website-messenger-<name>` (processes `_00`, `_01`, …) | `messenger-multi\|anime-episode-download\|anime_episode_download\|--limit=1 --time-limit=3600\|12` |
| `scheduler` | `scheduler.conf.tpl` | `website-scheduler-<name>` | `scheduler\|default\|scheduler_default\|--time-limit=86400\|` |

Templates use the placeholders `{{NAME}}`, `{{QUEUE}}`, `{{OPTIONS}}`, `{{NUMPROCS}}`, `{{APP_DIR}}`, `{{WORKER_USER}}` (`www-data`), `{{DEPLOY_USER}}` and `{{DEPLOY_HOME}}`. Logs go to `var/log/<program>.log` (`var/log/<program>_<NN>.log` for `messenger-multi`).

The list currently covers `node-services`; 2 `core_chat` workers (the chat bot: slash commands and buttons, on workers of their own so that an answer never waits behind the notifications); the `core_async`, `hoyoverse`, `hoyoverse_diary`, `hoyoverse_redeem`, `hoyoverse_pulls`, `gryphline`, `gryphline_pulls`, `perfectworld` and `perfectworld_pulls` workers; 12 `anime_episode_download` workers; and the `default`, `hoyoverse`, `gryphline` and `perfectworld` schedulers.

**Adding a worker**: add a line to `programs.list` (e.g. `messenger|my-bundle|my_bundle|--limit=100 --time-limit=3600|`) and run `sudo scripts/setup-supervisor.sh` again.

The deploy script does not run it: after deploying the release that adds the `core_chat` workers, run `sudo scripts/setup-supervisor.sh` once, otherwise the chat bot acknowledges the commands ("thinking…") but never answers them.

**Useful commands** (in a group, programs are addressed as `website-valdi:<program>`):

```bash
sudo supervisorctl status website-valdi:*
sudo supervisorctl restart website-valdi:*
sudo supervisorctl restart website-valdi:website-node-services
sudo supervisorctl tail -f website-valdi:website-messenger-core
```

After the first deploy also run `php bin/console perfectworld:nte-catalog:update` once to build the NTE item catalog (then refreshed weekly by the `perfectworld` schedule).

The HoYoverse banner catalogs (rate-up flags and banner phases, from irminsul.gg) are built the same way, once, then refreshed weekly by the `hoyoverse` schedule:

```bash
php bin/console hoyoverse:gi-catalog:update
php bin/console hoyoverse:hsr-catalog:update
php bin/console hoyoverse:zzz-catalog:update
```

The Endfield and NTE catalogs also read the irminsul.gg banner schedule (banner dates, Endfield rate-up items): after deploying that change, rebuild both once instead of waiting for their weekly task:

```bash
php bin/console gryphline:ake-catalog:update
php bin/console perfectworld:nte-catalog:update
```

---

## 3. Updates & Deployment

### 3.1 Automated Deployment (`website-deploy.sh`)
Production updates are handled by [`scripts/website-deploy.sh`](../scripts/website-deploy.sh) (which can also be triggered automatically via GitHub Actions on release publication using [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml)).

To allow the deploy user to restart Supervisor processes without a password prompt, add the following line via `sudo visudo`:
```text
YOURUSER ALL=(ALL) NOPASSWD:/usr/bin/supervisorctl
```

The script runs from its own folder outside the repository, `/var/www/website-valdi-deploy/`, owned by the deploy user, together with its local, non-versioned configuration `website-deploy.env`; the script itself is never edited on the server. Prepare it once, as the deploy user:

```bash
sudo mkdir -p /var/www/website-valdi-deploy
sudo chown "$USER": /var/www/website-valdi-deploy
install -m 0755 /var/www/valdi.ovh/scripts/website-deploy.sh /var/www/website-valdi-deploy/website-deploy.sh
cp /var/www/valdi.ovh/scripts/website-deploy.env.dist /var/www/website-valdi-deploy/website-deploy.env
nano /var/www/website-valdi-deploy/website-deploy.env   # APP_DIR, BACKUP_SCRIPT, PHP_FPM_SERVICE (optional: NODE_SERVICES_DIR, SUPERVISOR_NODE_SERVICE, GIT_BRANCH)
```

- The GitHub workflow runs `/var/www/website-valdi-deploy/website-deploy.sh` over SSH. The `visudo` rule for `supervisorctl` above stays the same.
- Values not set in `website-deploy.env` keep the defaults at the top of the script. `DEPLOY_ENV_FILE=/path/to/file` selects another configuration file.
- Before the backup or any git command, the script stops with an error if `APP_DIR` is not a git checkout or `BACKUP_SCRIPT` is not executable.
- **Self-update:** the last step copies `scripts/website-deploy.sh` from the freshly pulled code over the copy in `/var/www/website-valdi-deploy/` (written next to it, then `mv`). The running deploy finishes with the old version; the new one applies from the next deploy. This works only while the folder is writable by the deploy user; otherwise the step prints a warning and the copy has to be done manually.
- **A release uses the workflow of its tagged commit.** Prepare `/var/www/website-valdi-deploy/` before publishing the release that introduces this path, otherwise that release's deploy fails because the script is not found.
- **First switch from an older script** (`/var/www/website-deploy.sh`, with the variables edited at the top of the file): an older script cannot update itself, so prepare the folder manually once as above, reusing the values of the old script in `website-deploy.env`. Once the first release with the new path has been deployed, the old `/var/www/website-deploy.sh` can be removed; from then on every deploy keeps the script up to date.

### 3.2 Deployment Pipeline Steps
1. **Database backup** via `$BACKUP_SCRIPT`.
2. **Pull latest code:** `git fetch origin` and `git reset --hard origin/$GIT_BRANCH` (`main` by default).
3. **Purge stale Symfony cache:** `rm -rf var/cache/prod var/cache/dev` before running Composer to prevent `ClassNotFound` errors when bundles, namespaces, or packages are updated or removed.
4. **PHP dependencies & migrations:** `composer install --no-dev --optimize-autoloader --no-interaction` and `php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`, followed by `php bin/console cache:pool:clear core.security_hierarchy.cache`: the database role hierarchy is cached in an application pool under `var/share/`, which step 3 does not remove.
5. **Warmup cache & assets:** `php bin/console cache:warmup --env=prod` and `php bin/console assets:install public --no-interaction`.
6. **Sync the Discord commands:** `php bin/console chat:commands:sync --platform=discord --env=prod` replaces every global slash command of the Discord application with the commands of the deployed version. It only prints a note while `DISCORD_BOT_TOKEN` is empty, and a failure (Discord unreachable, token revoked, payload refused) prints a warning without stopping the deploy: run the same command again by hand once fixed. `--dry-run` prints the payload without calling Discord.
7. **Frontend build:** `npm ci` and `npm run build`.
8. **Update `node-services`:** `cd node-services && npm ci --omit=dev && npx playwright install chromium`.
9. **Restart background services:**
   - `sudo supervisorctl restart website-valdi:website-node-services`
   - `php bin/console messenger:stop-workers` (Messenger workers stop gracefully and Supervisor restarts them with the new code)
   - *(optional)* `sudo systemctl reload php8.5-fpm` to flush OPcache without dropping active HTTP requests.
10. **Update the deploy script:** copies `scripts/website-deploy.sh` over the running copy (effective from the next deploy, see §3.1).

### 3.3 Quick Caddy / Mercure Maintenance
- **Check service status and live logs:**
  ```bash
  sudo systemctl status caddy
  tail -f /var/log/caddy.log
  ```
- **Reload `Caddyfile` with zero downtime:**
  ```bash
  sudo systemctl reload caddy
  ```
  The unit's `ExecReload` runs `mercure reload`, which sends the `Caddyfile` to the admin API (`localhost:2019`) of the running process.
  - Requests in flight, video streams and Mercure subscriptions included, carry on through the reload.
  - An invalid `Caddyfile` is refused: the command fails and the previous config keeps running.

  On a server whose `caddy.service` was generated before the `ExecReload` line, systemd answers `Job type reload is not applicable`. To fix it, add the line under `ExecStart` by hand, then reload the unit (do not re-run `setup-server.sh`):
  ```ini
  ExecReload=/var/www/caddy/mercure reload --config /var/www/caddy/Caddyfile --force
  ```
  ```bash
  sudo systemctl daemon-reload
  ```
  Until then, run the reload directly:
  ```bash
  /var/www/caddy/mercure reload --config /var/www/caddy/Caddyfile
  ```
- **Adding or renaming a sub-domain on an existing server:** edit `/var/www/caddy/Caddyfile` by hand (the site host list, plus `cors_origins` and `publish_origins` of the Mercure hub), then reload Caddy. Do **not** re-run `setup-server.sh`: it regenerates the Mercure JWT keys. Without the new origin in `cors_origins`, the browser blocks the Mercure `EventSource` and live progress silently falls back to polling.
- **Security headers on an existing server:** a `Caddyfile` generated before the `security_headers` snippet has none of them. Copy the `(security_headers)` block from `setup-server.sh` after the global options, add `import security_headers` at the top of the application and `mercure.<domain>` site blocks, and reload Caddy. On a local domain, leave out the `Strict-Transport-Security` line.
  - The headers are set with `?`, so a header sent by Symfony wins: a response that needs a different value sets it itself, as `GeetestService` does with `Referrer-Policy: no-referrer`, and the Mercure hub endpoint keeps its own `X-Frame-Options: DENY` and `Content-Security-Policy`.
  - `Strict-Transport-Security` has no `includeSubDomains`, because the apex domain shares the site block and other sub-domains of the domain (such as the mail provider's `mail.<domain>`) may only answer over HTTP.

### 3.4 Database Encryption (optional)
Columns mapped with the `CoreBundle` type `encrypted_text` are stored in plain text unless `DATABASE_ENCRYPTION_KEY` is set. Today these are the game account credentials (HoYoverse cookies, Gryphline account token and SKPort session, Perfect World token, listed in the [GamesBundle guide](../src/GamesBundle/README.md#credentials-encryption-at-rest)) and the values of the API tokens of the profile page (`token.access_token`, migration `Version20261123180127`); format, behaviour and what is not encrypted are in the [Encrypted Columns guide](../src/CoreBundle/src/Service/Encryption/README.md). Existing values are converted by a command, not by a migration.

**Deploying the release** that introduces encryption changes no stored data while the key is not set. One transient effect, shared by any mapping change with this deploy script: until `messenger:stop-workers` restarts them, workers still running the previous code may load the new Doctrine metadata (rebuilt by the cache warmup) and fail on messages touching the encrypted columns (the game accounts), because they do not know the `encrypted_text` type. Those messages are retried with the transport's retry strategy, and dropped if the worker is restarted only after the last attempt (no failure transport is configured); no data is changed.

**Before deploying a version that does not know this encryption** (an older release, or a revert of this feature), go back to plain text as described below: older code would use the `enc:v1:…` values as they are, for example sending them to the game services as if they were tokens.

**Where it fails, and how much.** A value that cannot be decrypted makes the request or the message fail instead of being used ("fails closed"), and an operation that loads many rows fails as a whole: one unreadable game account stops, for example, the HoYoverse cookie refresh of every account, not only the one of that account. This happens in two windows of the procedures below, so run their steps one after the other and then check the worker logs (`sudo supervisorctl tail website-valdi:<program>`, `var/log/`) for errors:
- enabling: between setting the key and the restart of every worker (step 4), workers without the key cannot read the values written meanwhile with it;
- going back: between removing the key and the last `--decrypt` (step 3), the values encrypted meanwhile cannot be read.

**Enabling it**, in this order:
1. **Deploy first, key later.** Set the key only after the release containing this feature is deployed and its workers have restarted, never in the same deploy: workers still running the older code would take encrypted values for plain ones.
2. **Generate the key** and keep a copy outside the server, **apart from the database backups**: a backup together with its key gives the values away, and without the key the encrypted values cannot be recovered (for the game accounts: they have to be logged in again).
   ```bash
   openssl rand -base64 32
   ```
3. **Configure it in the secrets vault**:
   ```bash
   php bin/console secrets:set DATABASE_ENCRYPTION_KEY --env=prod
   ```
   This adds a file to `config/secrets/prod/` and edits the versioned `prod.list.php`: run it on a development checkout, commit the result and deploy it (a second deploy, after the one of step 1). Do not run it on the server: the next deploy (`git reset --hard`) would revert `prod.list.php` and the key would no longer be loaded.

   Alternative: `DATABASE_ENCRYPTION_KEY="<key>"` in `.env.local` on the server, with no second deploy.
4. **Make every process see the key.** It is read at runtime (`%env()%`), so the container cache does not need to be cleared (unless the environment was compiled with `composer dump-env prod`: run it again). The deploy of step 3 already restarts the workers; PHP-FPM is not reloaded by the deploy script:
   ```bash
   php bin/console messenger:stop-workers      # Supervisor restarts workers and schedulers
   sudo systemctl reload <php-fpm service>     # PHP_FPM_SERVICE in website-deploy.env
   ```
   From now on values are written encrypted; the existing plain values are still read.
5. **Check, convert, verify:**
   ```bash
   php bin/console core:database:encrypt --dry-run   # per column: plain, encrypted, unreadable; writes nothing
   php bin/console core:database:encrypt             # encrypts the values still in plain text
   php bin/console core:database:encrypt --dry-run   # "Plain text" must be 0 everywhere
   ```
   Then check the features that use the encrypted columns: for the game accounts, open the games page, check that the accounts load and that an account action (e.g. a profile sync) works.

The command prints counts only, never values, and can be run again at any time. Rows changed by a worker while it runs are left alone and reported as skipped: run it again. It writes nothing if no valid key is configured or if an encrypted value cannot be decrypted with the configured key (wrong key). Run it with `APP_ENV=prod`: in debug environments the SQL log records query parameters, that is the secrets.

**Going back** (decrypt first, then remove the key):
1. `php bin/console core:database:encrypt --decrypt`, with the key still configured. Values whose plain text starts with `enc:v1:` are kept encrypted and reported (stored in plain text they could not be read back): replace them (for a game account, log it in again), then run it again.
2. Remove the key: `php bin/console secrets:remove DATABASE_ENCRYPTION_KEY --env=prod` on a development checkout, then commit and deploy (or delete it from `.env.local`, if it was set there), then repeat step 4 above.
3. `php bin/console core:database:encrypt --dry-run`: without a key it only counts and ends with an error, as expected; "Encrypted" must be 0 everywhere. Values encrypted between steps 1 and 2 (for example a login or a token refresh) are decrypted by giving the key to that one command, since a real environment variable wins over `.env.local` and the vault:
   ```bash
   read -rs DATABASE_ENCRYPTION_KEY && export DATABASE_ENCRYPTION_KEY
   php bin/console core:database:encrypt --decrypt
   unset DATABASE_ENCRYPTION_KEY
   ```

- **Changing the key**: go back with the old key, then enable the new one (there is no key rotation).
- **Reverting migration `Version20261121183000`** (Gryphline session columns back to `VARCHAR(255)`): decrypt first, encrypted values do not fit.
- **API tokens already in the database** when `Version20261123180127` is deployed: the migration computes their `token_hash`, so they keep logging in; their values stay in plain text until `core:database:encrypt` runs (with a key already set, run it after that deploy). Reverting that migration refuses to run while some token value is encrypted: decrypt first.

---

## 4. Shared Folder (Media & File Managers)

### 4.1 Role in the Project
The shared folder (typically mounted under `/media/...`) acts as a centralized local storage area shared across the Symfony web app, background workers, external media servers (such as Plex), and LAN clients (via Samba). It is primarily used for:
- **Anime Downloads (`AnimeBundle`):** Messenger workers download and store anime episodes and season folders under `ANIME_BASE_FOLDER` inside the shared folder, making them immediately available for in-browser playback and external media servers like Plex.
- **Web File Managers (`BooksBundle`, `AnimeBundle`, `VideosBundle`):** all three bundles embed the shared `CoreBundle` `FileManager` (`FileManagerTrait`) to browse, upload, move, rename, edit, and stream files from their configured root paths (`Library::$basePath`, `ANIME_BASE_FOLDER`, `VIDEOS_BASE_FOLDER`).

### 4.2 Setup with `setup-shared-folder.sh`
```bash
sudo ./scripts/setup-shared-folder.sh /media/xyz
```

What [`scripts/setup-shared-folder.sh`](../scripts/setup-shared-folder.sh) does:
1. **`condivisi` system group:** creates the `condivisi` group if missing and adds `root`, `www-data`, `plex` (when present on the system), and the local user (`$SUDO_USER`).
2. **Ownership & permissions:**
   - Sets recursive ownership to `<real_user>:condivisi`.
   - Applies `u=rwX,g=rwX,o=rX`: directories receive `2775` (with **`setgid` `g+s`** so new subdirectories inherit the `condivisi` group), regular files receive `0664`, and `.sh` scripts keep executable permissions (`ug+x`, `0775`).
3. **Automatic inheritance (POSIX ACLs):** installs `acl` if missing and applies `setfacl -R -d -m u::rwx,g::rwx,o::rx` so any new files or folders created by PHP-FPM/Symfony, Messenger workers, Plex, or Samba automatically remain readable and writable by the `condivisi` group.
4. **Service reload & Samba config:** restarts `php*-fpm` and `plexmediaserver` (if active) so group membership takes effect immediately, and if `smbd` is installed, offers to append the share block to `/etc/samba/smb.conf`:

```ini
[xyz]
   comment = xyz
   path = /media/xyz
   browsable = yes
   writable = yes
   guest ok = no

   force group = condivisi
   create mask = 0664
   directory mask = 2775
   inherit permissions = yes
```

> **Note:** to use the `condivisi` group in your current shell session without logging out, run `newgrp condivisi`.
