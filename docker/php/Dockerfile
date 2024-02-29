# Add PHP-FPM base image
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y libpng-dev libmagickwand-dev libzip-dev zip && pecl install imagick

# Install your extensions
# To connect to MySQL add mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql gd zip && docker-php-ext-enable pdo_mysql imagick

RUN sed -e 's/max_execution_time = 30/max_execution_time = 300/' -i /etc/php/8.3/fpm/php.ini
RUN sed -e 's/post_max_size = 8M/post_max_size = 256M/' -i /etc/php/8.3/fpm/php.ini
RUN sed -e 's/upload_max_filesize = 2M/upload_max_filesize = 128M/' -i /etc/php/8.3/fpm/php.ini