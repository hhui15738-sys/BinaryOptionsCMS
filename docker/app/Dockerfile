FROM node:18-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY webpack.mix.js ./
COPY resources ./resources
COPY public ./public
RUN npm run production \
    && test -s public/app/app.js \
    && test -s public/app/admin.js \
    && grep -q '"/app/admin.js"' public/mix-manifest.json

FROM composer:2.8 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
COPY app/Helpers/Helper.php app/Helpers/Helper.php
COPY database/factories database/factories
COPY database/seeds database/seeds
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libonig-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install bcmath mbstring pcntl pdo_mysql pdo_pgsql sockets zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public /opt/app-public
COPY --from=frontend /app/public ./public
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
