# syntax=docker/dockerfile:1
#
# Production image for the Hamro Ward API (D-014).
# Base: serversideup/php fpm-nginx — nginx + PHP-FPM in one container, running as
# www-data on port 8080, with the AUTORUN startup automation and the
# healthcheck-queue / healthcheck-schedule helpers the stack's health checks use.
#
# Build context: apps/api
# Built for linux/arm64 by .github/workflows/image-api.yml

ARG PHP_VERSION=8.4
FROM serversideup/php:${PHP_VERSION}-fpm-nginx

USER root
# pgsql/pdo_pgsql: PostgreSQL. intl: Nepali text normalisation (Normalizer).
# bcmath: money and counters. redis: cache, queue and sessions. pcntl: queue worker signals.
RUN install-php-extensions bcmath intl pgsql pdo_pgsql redis pcntl

# Runs after the base image's own startup steps: central migrations as the schema
# owner, then one pass over every municipality database.
COPY --chmod=755 docker/entrypoint.d/90-hamroward-migrations.sh /etc/entrypoint.d/90-hamroward-migrations.sh
USER www-data

WORKDIR /var/www/html

# Dependencies first, so this layer stays cached when only application code changes
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# Application code (see .dockerignore), then the optimized autoloader.
# dump-autoload also runs the composer scripts, including package:discover.
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize --no-interaction
