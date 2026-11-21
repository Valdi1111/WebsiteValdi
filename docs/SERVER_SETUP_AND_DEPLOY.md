# Infrastructure, First Installation, Updates & Shared Folder

This guide describes the application stack architecture, first-time server setup (Ubuntu/Debian or WSL), the production update/deploy workflow, and how the shared media folder is configured and used.

---

## 1. How the Stack Works

The project is a modular **Symfony 8.1** application with a **React 19 + Ant Design 6** frontend and real-time updates powered by **Mercure (SSE)**:

| Layer | Technology | Role |
| :--- | :--- | :--- |
| **Web Server & TLS** | **Caddy + Mercure Hub** (`mercure` binary) | Automatic HTTPS reverse proxy (Let's Encrypt in production, internal CA `local_certs` for `.wip`/`.local` domains), `X-Sendfile` support for video/EPUB streaming, and built-in SSE hub on `mercure.<domain>`. |
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
3. **PHP 8.5 (`FPM` + `CLI`):** detects or installs via the `ondrej/php` PPA `php8.5-fpm`, `php8.5-cli`, and required extensions (`ldap`, `imap`, `readline`, `imagick`, `mbstring`, `mysql`, `curl`, `zip`, `xml`, `intl`), sets the default CLI binary, and writes `/etc/php/8.5/{fpm,cli}/conf.d/99-custom.ini` (`Europe/Rome`, `memory_limit = 4G`, `max_execution_time = 300`; FPM also gets `upload_max_filesize = 256M` and `post_max_size = 260M`).
4. **External tools:** installs or updates `composer` in `/usr/local/bin/composer`, `ffmpeg`/`ffprobe`, and `yt-dlp` in `/usr/local/bin/yt-dlp`.
5. **Caddy + Mercure Hub:** downloads the latest `mercure` release to `/var/www/caddy/mercure`, grants `CAP_NET_BIND_SERVICE` for non-root binding on ports `80`/`443`, generates HS256 JWT keys (`MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY`), generates `/var/www/caddy/Caddyfile` (with `X-Sendfile` enabled for PHP-FPM), and registers `/etc/systemd/system/caddy.service`.
6. **Project permissions:** sets `owner:www-data`, enables the `setgid` bit (`g+s`) on directories, and configures **default POSIX ACLs** (`setfacl`) on the project root so new files created by PHP/Symfony or the user keep `rwX` permissions.
7. **Final summary & WSL notes:** prints the `MERCURE_*` environment variables to add to `.env.local` and, when running inside WSL, prints `netsh portproxy` / `.wslconfig` instructions for Windows.

### 2.3 Install Node.js 20 LTS
To install Node.js 20 LTS, `npm`, and `npx` under `/opt/node` (with shared permissions for `www-data` and the local user):

```bash
sudo ./scripts/node-install.sh
```
*(Alternatively, you can install NVM under the deploy user's `$HOME/.nvm`, as expected by `website-deploy.sh` and `website-node-services.conf`.)*

### 2.4 Configure `.env.local`
Create `.env.local` at the project root based on [`.env`](../.env) and configure at least:
- `APP_ENV=prod` and `APP_SECRET=<random-secret>`
- `DATABASE_URL="mysql://..."`
- `DOMAIN_NAME="valdi.ovh"` (or your local domain)
- `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` (printed at the end of `setup-server.sh`)
- Shared folder paths: `ANIME_BASE_FOLDER`, `ANIME_TEMP_FOLDER`, `VIDEOS_BASE_FOLDER`
- External API credentials (MyAnimeList, AniList, AnimeWorld) and notification transports (`TELEGRAM_DSN`, `DISCORD_DSN`)
- Node microservice port: `NODE_SERVICES_PORT=3003` (and `node-services/.env.local` if overriding its default port `3000`)

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
Install Supervisor and copy the unit files from `scripts/` into `/etc/supervisor/conf.d/`:

```bash
sudo apt install supervisor
```

Available configurations in [`scripts/`](../scripts/):
- [`website-node-services.conf`](../scripts/website-node-services.conf) — Express/Playwright server (`node-services`)
- [`website-messenger-core.conf`](../scripts/website-messenger-core.conf) — `core_async` queue (emails, Discord & Telegram notifications)
- [`website-messenger-anime-episode-download.conf`](../scripts/website-messenger-anime-episode-download.conf) — `anime_episode_download` queue (2 workers with `--limit=1`)
- [`website-messenger-hoyoverse.conf`](../scripts/website-messenger-hoyoverse.conf) — `hoyoverse` queue (notes, check-ins, reminders)
- [`website-messenger-hoyoverse-diary.conf`](../scripts/website-messenger-hoyoverse-diary.conf) — `hoyoverse_diary` queue
- [`website-messenger-hoyoverse-redeem.conf`](../scripts/website-messenger-hoyoverse-redeem.conf) — `hoyoverse_redeem` queue
- [`website-messenger-gryphline.conf`](../scripts/website-messenger-gryphline.conf) — `gryphline` queue
- [`website-scheduler-default.conf`](../scripts/website-scheduler-default.conf) — `scheduler_default`
- [`website-scheduler-hoyoverse.conf`](../scripts/website-scheduler-hoyoverse.conf) — `scheduler_hoyoverse`
- [`website-scheduler-gryphline.conf`](../scripts/website-scheduler-gryphline.conf) — `scheduler_gryphline`
- [`website-messenger-perfectworld.conf`](../scripts/website-messenger-perfectworld.conf) — `perfectworld` queue (NTE catalog update)
- [`website-scheduler-perfectworld.conf`](../scripts/website-scheduler-perfectworld.conf) — `scheduler_perfectworld`
- A worker for the `perfectworld_pulls` queue (`php bin/console messenger:consume perfectworld_pulls -vv --limit=100 --time-limit=3600`), configured like the other `*_pulls` workers: Perfect World pull imports wait in that queue. After the first deploy also run `php bin/console perfectworld:nte-catalog:update` once to build the NTE item catalog (then refreshed weekly by the `perfectworld` schedule).

Replace `YOURUSER` and `/path/to/your/app` in the copied files under `/etc/supervisor/conf.d/`, then reload and start the services:

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

---

## 3. Updates & Deployment

### 3.1 Automated Deployment (`website-deploy.sh`)
Production updates are handled by [`scripts/website-deploy.sh`](../scripts/website-deploy.sh) (which can also be triggered automatically via GitHub Actions on release publication using [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml)).

To allow the deploy user to restart Supervisor processes without a password prompt, add the following line via `sudo visudo`:
```text
YOURUSER ALL=(ALL) NOPASSWD:/usr/bin/supervisorctl
```

Before deploying, place/configure the script on the server and adjust the variables at the top of the file (`APP_DIR`, `BACKUP_SCRIPT`, `PHP_FPM_SERVICE`, `GIT_BRANCH`).

### 3.2 Deployment Pipeline Steps
1. **Database backup** via `$BACKUP_SCRIPT`.
2. **Pull latest code:** `git fetch origin` and `git reset --hard origin/main`.
3. **Purge stale Symfony cache:** `rm -rf var/cache/prod var/cache/dev` before running Composer to prevent `ClassNotFound` errors when bundles, namespaces, or packages are updated or removed.
4. **PHP dependencies & migrations:** `composer install --no-dev --optimize-autoloader --no-interaction` and `php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`, followed by `php bin/console cache:pool:clear core.security_hierarchy.cache`: the database role hierarchy is cached in an application pool under `var/share/`, which step 3 does not remove.
5. **Warmup cache & assets:** `php bin/console cache:warmup --env=prod` and `php bin/console assets:install public --no-interaction`.
6. **Frontend build:** `npm ci` and `npm run build`.
7. **Update `node-services`:** `cd node-services && npm ci --omit=dev && npx playwright install chromium`.
8. **Restart background services:**
   - `sudo supervisorctl restart website-node-services`
   - `php bin/console messenger:stop-workers` (Messenger workers stop gracefully and Supervisor restarts them with the new code)
   - *(optional)* `sudo systemctl reload php8.5-fpm` to flush OPcache without dropping active HTTP requests.

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
- **Adding or renaming a sub-domain on an existing server:** edit `/var/www/caddy/Caddyfile` by hand (the site host list, plus `cors_origins` and `publish_origins` of the Mercure hub), then reload Caddy. Do **not** re-run `setup-server.sh`: it regenerates the Mercure JWT keys. Without the new origin in `cors_origins`, the browser blocks the Mercure `EventSource` and live progress silently falls back to polling.

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
