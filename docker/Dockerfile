FROM php:8.3-fpm-alpine

# System deps + PHP extensions
# postgresql-dev is only needed to compile pdo_pgsql; remove it after, keep runtime libpq
RUN apk add --no-cache nginx nodejs npm icu-dev curl \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql intl opcache \
    && apk del .build-deps \
    && apk add --no-cache libpq

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# pnpm via corepack (version pinned by packageManager field in package.json)
RUN corepack enable

WORKDIR /var/www/html

# PHP dependencies (layer-cached before copying full app)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-progress \
    --prefer-dist --classmap-authoritative --no-scripts

# Node dependencies (layer-cached before copying full app)
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

# Copy full app, run post-install scripts, build frontend assets
COPY . .
RUN composer run-script post-install-cmd --no-interaction
RUN pnpm build

# Nginx + PHP config
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Writable dirs owned by www-data (PHP-FPM user)
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/ \
    && chmod -R 775 var/

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000

CMD ["docker-entrypoint.sh"]
