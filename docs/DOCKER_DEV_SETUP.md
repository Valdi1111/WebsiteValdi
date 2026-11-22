# Docker Development Setup

An optional, local-only environment that follows the server layout: the `dunglas/mercure` image (Caddy + the Mercure module, the same binary [`scripts/setup-server.sh`](../scripts/setup-server.sh) installs) serves both the Mercure hub and the Symfony application, with PHP running in a PHP-FPM 8.5 container; MySQL 8.4 and a Webpack watcher complete it. Everything is defined in [`compose.yaml`](../compose.yaml) and [`docker/caddy/Caddyfile`](../docker/caddy/Caddyfile). It is not used in production (see [Server Setup](SERVER_SETUP_AND_DEPLOY.md)).

Prerequisites: Docker with the Compose plugin 2.24 or later (`env_file` with `required: false`). Run every command from the project root. The local domain below is `valdi.wip` (the default TLD of the Symfony CLI's local proxy, which `setup-server.sh` also treats as local) and HTTPS is published on port `8443`. The domain comes from `DOMAIN_NAME` in `.env.local`; for another port see [Changing the HTTPS port](#changing-the-https-port). If you also run the host setup on `valdi.wip`, give Docker another name: cookies are not isolated by port, so the two sessions would overwrite each other.

## 1. Host files

Local domain resolution (every subdomain of the application, plus the Mercure hub):

```bash
echo '127.0.0.1 valdi.wip mercure.valdi.wip anime.valdi.wip books.valdi.wip games.valdi.wip passwords.valdi.wip videos.valdi.wip' | sudo tee -a /etc/hosts
```

`.env.local` (minimal; it is read by the `dev` environment):

```dotenv
DOMAIN_NAME="valdi.wip"
DEFAULT_URI="https://valdi.wip:8443"
# The root password is the one in compose.yaml
DATABASE_URL="mysql://root:!ChangeMe!@mysql:3306/app?serverVersion=8.0.32&charset=utf8mb4"
MERCURE_URL="http://mercure/.well-known/mercure"
MERCURE_PUBLIC_URL="https://mercure.valdi.wip:8443/.well-known/mercure"
# A random secret (openssl rand -hex 32); the placeholder from .env would let anyone forge hub tokens
MERCURE_JWT_SECRET="<random secret>"
```

Everything else falls back to the placeholder values in [`.env`](../.env). The `mercure` container loads `.env` and then `.env.local` (the later file wins), so Caddy reads `DOMAIN_NAME` and `MERCURE_JWT_SECRET` from the same files as Symfony: they live only in `.env.local`. Caddy reads its environment at startup, so run `docker compose up -d` again after changing `.env.local`.

### File permissions

PHP-FPM and the `webpack` container run with your user id, so files created inside the containers (`var/`, `vendor/`, `public/build/`) belong to you on the host and the other way round. The default is `1000:1000`. If `id -u` and `id -g` differ, or you want to avoid exporting variables in every shell, pin the ids in a `compose.override.yaml` (git ignores it):

```yaml
services:
    php-fpm:
        user: "1000:1000"
    webpack:
        user: "1000:1000"
```

Alternatively, `export HOST_UID=$(id -u) HOST_GID=$(id -g)` before the `docker compose` commands (it lasts for the current shell only: containers recreated from a shell without it run as `1000:1000`).

With Docker rootless, or Docker Desktop for Linux, the host user is root inside the container: use `HOST_UID=0 HOST_GID=0` (or `user: "0:0"` in the override). This route has not been tried, and PHP-FPM started as root still runs its workers as `www-data`, which may not be able to write the files owned by the host user: if requests fail on `var/`, make it writable for everyone (`chmod -R a+rwX var`).

### Changing the HTTPS port

The port is a Compose variable, because `ports:` needs it, so it cannot go in `.env.local` or in the `environment` of an override alone (Caddy would move to the new port while Compose kept publishing `8443`). Put it in a `compose.override.yaml` together with the published port (the `!override` tag replaces the list instead of adding to it), and use the same number in `MERCURE_PUBLIC_URL` and `DEFAULT_URI`:

```yaml
services:
    mercure:
        environment:
            HTTPS_PORT: "9443"
        ports: !override
            - "127.0.0.1:9443:9443"
```

Exporting `HTTPS_PORT=9443` in the shell before every `docker compose` command also works, but it lasts for the current shell only.

## 2. Build and start

```bash
docker compose build
docker compose up -d
docker compose exec php-fpm composer install --no-interaction
docker compose exec php-fpm php bin/console doctrine:database:create --if-not-exists
docker compose exec php-fpm php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php-fpm php bin/console cache:pool:clear core.security_hierarchy.cache
```

The `webpack` service runs `npm run watch`, so the assets are built on `up` and rebuilt on every change. For a one-off production build: `docker compose run --rm webpack npm run build`. If `package-lock.json` changes, recreate its anonymous `node_modules` volume with `docker compose up -d --build -V webpack`.

Caddy issues the certificates from its own local CA. To make the browser trust them, copy the root certificate out once and import it into the browser or the OS trust store (`setup-server.sh` does the same with `caddy-root-ca.crt`):

```bash
docker compose cp mercure:/data/caddy/pki/authorities/local/root.crt var/caddy-root-ca.crt
```

Open <https://games.valdi.wip:8443/login>.

## 3. First user

There is no command to create users. Generate a password hash, then insert the user and its roles (replace the e-mail and the hash; the quoted `'SQL'` delimiter keeps the shell from expanding the `$` signs of the hash):

```bash
docker compose exec php-fpm php bin/console security:hash-password 'App\CoreBundle\Entity\User'
docker compose exec -T mysql mysql -uroot -p'!ChangeMe!' app <<'SQL'
INSERT INTO user (email, password) VALUES ('me@example.test', '<hash>');
INSERT INTO user_role (user_id, role_id) SELECT u.id, r.id FROM user u JOIN role r
  WHERE u.email = 'me@example.test' AND r.name IN ('ROLE_USER', 'ROLE_USER_GAMES', 'ROLE_USER_HOYOVERSE');
SQL
```

## 4. Tests

```bash
docker compose exec php-fpm php bin/phpunit
```

The suite needs no database and no extra variables: the `test` environment runs with the committed `.env` and `.env.test`.

## Notes

- HTTPS (`8443`) and MySQL (`3306`) are published on `127.0.0.1` only. `compose.yaml` ships default passwords, so widen the exposure only on a trusted network, with a `compose.override.yaml` (the `!override` tag replaces the list instead of adding to it):

  ```yaml
  services:
      mysql:
          ports: !override
              - "3306:3306"
  ```
- Caddy reads the files that PHP sends (X-Accel-Redirect), so folders outside the project (Books library paths, `ANIME_BASE_FOLDER`, `VIDEOS_BASE_FOLDER`) must be mounted in both `php-fpm` and `mercure`, at the same path as on the host, because the paths come from the environment and the database. In a `compose.override.yaml`:

  ```yaml
  services:
      php-fpm:
          volumes:
              - /path/to/media:/path/to/media
      mercure:
          volumes:
              - /path/to/media:/path/to/media:ro
  ```
- Not part of this environment: `node-services` (the AnimeBundle calls `http://localhost:3003`, which inside `php-fpm` is not the host), `yt-dlp`, `ffmpeg`, Messenger workers and the scheduler. For a worker, run `php bin/console messenger:consume <transport>` in the `php-fpm` container.
