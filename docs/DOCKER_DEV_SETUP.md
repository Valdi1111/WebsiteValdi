# Docker Development Setup

An optional, local-only environment: Apache (HTTPS) + PHP-FPM 8.5, MySQL 8.0, a Mercure hub and a Webpack watcher, all defined in [`compose.yaml`](../compose.yaml). It is not used in production (see [Server Setup](SERVER_SETUP_AND_DEPLOY.md)).

Prerequisites: Docker with the Compose plugin and `openssl`. Run every command from the project root. The local domain below is `valdi.test`; any other name works if you use it consistently.

## 1. Host files

Self-signed certificate (the vhost expects `fullchain.pem` and `privkey.pem` in `docker/apache/certs`, which git ignores):

```bash
mkdir -p docker/apache/certs
openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
    -keyout docker/apache/certs/privkey.pem -out docker/apache/certs/fullchain.pem \
    -subj "/CN=games.valdi.test" -addext "subjectAltName=DNS:valdi.test,DNS:games.valdi.test"
```

Local domain resolution (the games application is served on the `games.` subdomain):

```bash
echo '127.0.0.1 valdi.test games.valdi.test' | sudo tee -a /etc/hosts
```

File permissions: PHP-FPM and the `webpack` container run with your user id, so files created inside the containers (`var/`, `vendor/`, `public/build/`) belong to you on the host and the other way round. The default is `1000:1000`; if `id -u` and `id -g` differ, export them before any `docker compose` command:

```bash
export HOST_UID=$(id -u) HOST_GID=$(id -g)
```

`.env.local` (minimal; it is read by the `dev` environment):

```dotenv
DOMAIN_NAME="valdi.test"
# The root password is the one in compose.yaml
DATABASE_URL="mysql://root:!ChangeMe!@mysql:3306/app?serverVersion=8.0.32&charset=utf8mb4"
MERCURE_URL="http://mercure/.well-known/mercure"
MERCURE_PUBLIC_URL="https://games.valdi.test:8443/mercure-hub"
# Must match MERCURE_PUBLISHER_JWT_KEY / MERCURE_SUBSCRIBER_JWT_KEY in compose.yaml
MERCURE_JWT_SECRET="!ChangeThisMercureHubJWTSecretKey!"
```

Everything else falls back to the placeholder values in [`.env`](../.env).

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

Open <https://games.valdi.test:8443/login> and accept the self-signed certificate.

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

- Ports `8443` (HTTPS), `3306` (MySQL) and `3000` (Mercure) are published on all interfaces, and `compose.yaml` ships default passwords. Use this setup on a trusted network only, or bind the ports to `127.0.0.1` in a `compose.override.yaml` (git ignores it).
- Messenger workers and the scheduler are not part of this setup; run `php bin/console messenger:consume <transport>` in the `php-fpm` container when you need one.
