#!/usr/bin/env sh
# Runs on container start, after the base image's own automation.
# Only in the "app" container: queue and scheduler run with AUTORUN_ENABLED=false.
#
# Central migrations run as the schema owner (hw_owner) through the
# "central_owner" connection, while normal requests keep using hw_app.
# Tenant migrations then run once per municipality database; a failure there puts
# that one municipality into maintenance and must not stop the container, so the
# boot continues and the problem is visible in hw:tenant:migrate's output and in
# the tenants table.
set -e

if [ "${AUTORUN_ENABLED:-false}" != "true" ]; then
    exit 0
fi

cd /var/www/html

echo "[hamroward] central migrations"
php artisan migrate --force --database=central_owner --path=database/migrations/central

echo "[hamroward] tenant migrations"
if ! php artisan hw:tenant:migrate; then
    echo "[hamroward] WARNING: at least one municipality database was not migrated."
    echo "[hamroward] That municipality is in maintenance; the rest of the site is unaffected."
fi
