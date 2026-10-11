# PHP-FPM image for Laravel (the old Dockerfile was a Node image, so `php-fpm` could never start)
FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
