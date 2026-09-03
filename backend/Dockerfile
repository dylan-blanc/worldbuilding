# syntax=docker/dockerfile:1

# Builds the PHP-Apache backend used by Docker Compose in development and production.
# The production dependency stage installs Composer packages before the application is copied into the runtime image.
FROM php:8.4-apache AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg libavif-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev libwebp-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-avif --with-jpeg --with-webp \
    && docker-php-ext-install gd intl mbstring pdo_mysql zip \
    && /usr/bin/ffprobe -version > /dev/null \
    && php -r "exit(defined(\"PASSWORD_ARGON2ID\") ? 0 : 1);" \
    && rm -rf /var/lib/apt/lists/* \
    && install -d -m 700 -o www-data -g www-data /var/lib/php/sessions \
    && a2enmod rewrite \
    && sed -ri -e "s!/var/www/html!/var/www/html/public!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!/var/www/html/public!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e "/<Directory \/var\/www\/html\/public>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY php/sessions.ini /usr/local/etc/php/conf.d/sessions.ini

EXPOSE 80

FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .

FROM composer:2 AS prod-dependencies

WORKDIR /app

COPY . .

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM base AS prod

COPY . .
COPY --from=prod-dependencies /app/vendor ./vendor
