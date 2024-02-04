# Add PHP-FPM base image
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y libpng-dev libmagickwand-dev && pecl install imagick

# Install your extensions
# To connect to MySQL add mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql gd && docker-php-ext-enable pdo_mysql imagick