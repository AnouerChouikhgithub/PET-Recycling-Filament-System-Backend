#!/bin/sh
#
# PREPARED, NEVER RUN.
# Container entrypoint: warm the production cache with the REAL runtime
# environment, start php-fpm in the background, then hand the foreground to
# Caddy so container logs and signals go to the right process.
set -eu

# The cache is warmed here, not at image build time, so APP_SECRET and the
# other runtime values are the ones actually used. `--no-debug` keeps the
# container free of the debug tooling.
php bin/console cache:warmup --env=prod --no-debug

php-fpm -D

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
