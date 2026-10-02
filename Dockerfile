# Bookly API — one image, three roles (CONTAINER_ROLE = web | worker | scheduler).
# Build:  docker build -t bookly-api .
# Run:    docker run -p 8080:8080 --env-file .env bookly-api

# --- 1. PHP dependencies (no dev packages) -------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- 2. Runtime: FrankenPHP (Caddy web server with PHP built in) ---------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql pgsql pcntl opcache

# The base image gives frankenphp the cap_net_bind_service file capability (to use ports below 1024).
# We listen on $PORT (8080/10000), and hosts that drop all capabilities (Render) refuse to start a binary
# carrying one ("exec: frankenphp: Operation not permitted"), so remove it.
RUN setcap -r /usr/local/bin/frankenphp

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-bookly.ini

WORKDIR /app
COPY --from=vendor /app /app

# Laravel's list of package service providers (composer scripts were skipped in stage 1).
RUN php artisan package:discover

# Run as an unprivileged user; only storage/ and bootstrap/cache/ are writable.
RUN useradd --create-home --uid 10001 bookly \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R bookly:bookly storage bootstrap/cache /data/caddy /config/caddy \
    && chmod +x docker/start.sh
USER bookly

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json_stdout \
    CONTAINER_ROLE=web \
    PORT=8080

EXPOSE 8080
CMD ["docker/start.sh"]
