# syntax=docker/dockerfile:1

# ---------- Base: PHP-FPM with the extensions the app needs ----------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs libpq libzip libpng libjpeg-turbo freetype \
        fcgi tini \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev libpng-dev \
        libjpeg-turbo-dev freetype-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

WORKDIR /var/www/html

# ---------- Development: code is bind-mounted, deps installed at runtime ----------
FROM base AS dev

# Match www-data to the host user so bind-mounted files stay writable.
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache nodejs npm git shadow \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
USER www-data

# ---------- Production build stages ----------
FROM base AS vendor

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install
COPY . .
# Filament's theme imports CSS from vendor/.
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# ---------- Production image ----------
FROM base AS prod

ENV APP_ENV=production
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# post-autoload-dump also runs package:discover and filament:upgrade.
RUN composer dump-autoload --optimize --no-dev

USER www-data
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]
