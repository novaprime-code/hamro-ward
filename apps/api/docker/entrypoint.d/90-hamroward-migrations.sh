#!/usr/bin/env sh
# Runs on container start, after the base image's own automation.
# Only in the "app" container: queue and scheduler run with AUTORUN_ENABLED=false.
#
# Central migrations run as the schema owner (hw_owner) through the
# "central_owner" connection, while normal requests keep using hw_app.
# Tenant migrations then run once per municipality database, followed by the
# reference-data sync that gives each tenant its positions catalogue and
# geography subtree.
#
# ---------------------------------------------------------------------------
# HW_MIGRATE_ON_BOOT decides whether any of this happens (D-015).
#
# On staging it is true: a broken migration should be found by a deploy, not by
# a citizen. On production it is false, because this script runs under `set -e`
# inside a container Docker restarts — a failing migration there is not one
# error, it is an unbounded restart loop with the site down throughout. That
# has already happened once on this project, at restart number 29.
#
# With it off, run migrations as a deliberate step before rolling the app:
#   docker exec <stack>-app php artisan migrate --force --database=central_owner \
#       --path=database/migrations/central
#   docker exec <stack>-app php artisan hw:tenant:migrate
#   docker exec <stack>-app php artisan hw:tenant:sync-reference
# ---------------------------------------------------------------------------
set -e

if [ "${AUTORUN_ENABLED:-false}" != "true" ]; then
    exit 0
fi

if [ "${HW_MIGRATE_ON_BOOT:-false}" != "true" ]; then
    echo "[hamroward] HW_MIGRATE_ON_BOOT is not true — skipping migrations on boot."
    echo "[hamroward] Run them deliberately before rolling the app container."
    exit 0
fi

cd /var/www/html

echo "[hamroward] central migrations"
php artisan migrate --force --database=central_owner --path=database/migrations/central

# A tenant failure must not stop the boot: one municipality going into
# maintenance is not a reason to take the other 752 offline. The failure is
# visible in the command's output and in the tenants table.
echo "[hamroward] tenant migrations"
if ! php artisan hw:tenant:migrate; then
    echo "[hamroward] WARNING: at least one municipality database was not migrated."
    echo "[hamroward] That municipality is in maintenance; the rest of the site is unaffected."
fi

echo "[hamroward] tenant reference data"
if ! php artisan hw:tenant:sync-reference; then
    echo "[hamroward] WARNING: at least one municipality did not receive its reference data."
    echo "[hamroward] Its ward pages will be missing positions until hw:tenant:sync-reference succeeds."
fi
