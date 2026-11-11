# syntax=docker/dockerfile:1.7
#
# PREPARED, NEVER RUN.
# This file has not been built or executed in this round: no Docker engine was
# available and nothing was deployed. Treat it as a reviewed starting point and
# build it once on the target host before a real cut-over.
#
# Image layout
#   vendor   — composer install --no-dev, then the production asset build
#   runtime  — php-fpm + Caddy in one small image; Caddy serves /app/public and
#              proxies PHP to php-fpm on 127.0.0.1:9000 (see docker/entrypoint.sh)
#
# Secrets are NEVER baked in: .dockerignore keeps .env*, config/jwt and broker
# credentials out of the build context, and runtime gets them from .env.prod
# plus read-only host mounts (see compose.prod.yaml).

FROM composer:2 AS vendor

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

# 1) Dependency layer: only the lock files, so a source edit does not reinstall.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

# 2) Application layer.
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

# 3) Production assets. importmap:install fetches the pinned vendor assets, so
#    this step needs outbound network access ONCE at build time. APP_SECRET is
#    deliberately NOT provided: these commands must not need it, and the cache
#    is warmed at container start with the real runtime environment.
RUN php bin/console assets:install public --env=prod --no-debug \
 && php bin/console importmap:install --env=prod --no-debug \
 && php bin/console asset-map:compile --env=prod --no-debug


FROM php:8.4-fpm-alpine AS runtime

# pdo_pgsql talks to PostgreSQL; intl/opcache are production requirements;
# caddy is the public HTTP entrypoint. postgresql-client is not required at
# runtime (migrations run through Doctrine) and is intentionally absent.
RUN apk add --no-cache caddy icu-dev postgresql-dev ${PHPIZE_DEPS} \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache \
 && apk del ${PHPIZE_DEPS} \
 && rm -rf /var/cache/apk/*

# Production PHP limits: no error display, errors go to stderr for the Docker
# log driver, and opcache keeps the class map authoritative (composer built it).
RUN printf '%s\n' \
        'display_errors=Off' \
        'log_errors=On' \
        'error_log=/dev/stderr' \
        'expose_php=Off' \
        'memory_limit=256M' \
        'opcache.enable=1' \
        'opcache.validate_timestamps=0' \
        'opcache.memory_consumption=192' \
        'opcache.max_accelerated_files=20000' \
    > /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /app

COPY --from=vendor --chown=www-data:www-data /app /app
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
 && mkdir -p var/cache var/log var/run \
 && chown -R www-data:www-data var

# Caddy binds :80/:443 inside the container, so compose publishes those ports.
# php-fpm listens on 127.0.0.1:9000 only and is never reachable from outside.
EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/api/health || exit 1

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
