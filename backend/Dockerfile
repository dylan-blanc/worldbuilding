# syntax=docker/dockerfile:1

# Builds the PHP-Apache backend used by Docker Compose in development and production.
# The production dependency stage installs Composer packages before the application is copied into the runtime image.
FROM php:8.4-apache AS base

WORKDIR /var/www/html

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri -e "s!/var/www/html!/var/www/html/public!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!/var/www/html/public!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e "/<Directory \/var\/www\/html\/public>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

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
