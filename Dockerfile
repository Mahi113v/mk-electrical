FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# development: always read the PHP files from disk, never cache them
RUN { echo "opcache.enable=0"; echo "opcache.enable_cli=0"; } > /usr/local/etc/php/conf.d/dev.ini

WORKDIR /var/www/html
EXPOSE 8000