FROM php:8.3-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

FROM php-base AS app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

EXPOSE 80
