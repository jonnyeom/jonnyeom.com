# syntax=docker/dockerfile:1

FROM composer:2 AS composer_deps
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM node:22-alpine AS frontend_builder
WORKDIR /app

COPY package.json pnpm-lock.yaml ./
RUN corepack enable && pnpm install --frozen-lockfile

COPY assets ./assets
COPY public ./public
COPY templates ./templates
COPY src ./src
COPY config ./config
COPY vite.config.js ./
RUN pnpm build

FROM php:8.3-apache AS app
WORKDIR /var/www/html

ENV APP_ENV=prod \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-install intl pdo pdo_pgsql \
    && a2enmod rewrite \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_deps /app/vendor ./vendor
COPY . .
COPY --from=frontend_builder /app/public/build ./public/build

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

EXPOSE 10000

CMD ["sh", "-c", "sed -ri -e \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -ri -e \"s/:80>/:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
