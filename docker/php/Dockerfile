# PHP-FPM base image (same PHP version as CI and production)
FROM php:8.5-fpm

# Tools needed by Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: the ones required by composer.json and CI, plus the database driver.
# The installer also pulls in the system libraries and never prompts.
COPY --from=ghcr.io/mlocati/php-extension-installer:2.13.2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions exif gd imagick intl mysqli pdo_mysql zip

COPY --from=composer:2.11.2 /usr/bin/composer /usr/local/bin/composer

# Composer cache volume (see compose.yaml): writable by the host user the container runs as
RUN mkdir -p /tmp/composer && chmod 777 /tmp/composer

# The container runs as the host user (see compose.yaml), who does not own the mounted project
RUN git config --system --add safe.directory /var/www/html

# Development php.ini overrides
COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/
