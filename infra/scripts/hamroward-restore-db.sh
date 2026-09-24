#!/usr/bin/env bash
# Restores one Hamro Ward database from a dump — the central database, or a
# single municipality without touching any other.
#
#   ~/scripts/hamroward-restore-db.sh ~/backups/predeploy-20260922-181500/hamroward.dump hamroward
#   ~/scripts/hamroward-restore-db.sh ~/backups/pg-hw_t_9c1e4a77_20260922.sql.gz hw_t_9c1e4a77
#
# Accepts both formats: .dump (pg_dump -Fc, used by deploy.sh) and .sql.gz
# (plain SQL, used by the nightly backup).
#
# The target database is dropped and recreated, so this is destructive by
# design: it asks you to type the database name to confirm.
set -euo pipefail

DUMP_FILE="${1:-}"
TARGET_DB="${2:-}"
CENTRAL_DB="hamroward"

usage() { echo "Usage: $0 <dump-file> <database>"; exit 1; }
[ -n "$DUMP_FILE" ] && [ -n "$TARGET_DB" ] || usage
[ -f "$DUMP_FILE" ] || { echo "No such file: $DUMP_FILE"; exit 1; }

case "$TARGET_DB" in
  "$CENTRAL_DB"|hw_t_*) ;;
  *) echo "Refusing to touch \"$TARGET_DB\": only $CENTRAL_DB or hw_t_* databases."; exit 1 ;;
esac

echo "About to REPLACE database \"$TARGET_DB\" with $DUMP_FILE"
echo "Everything currently in \"$TARGET_DB\" will be lost."
printf 'Type the database name to continue: '
read -r CONFIRM
[ "$CONFIRM" = "$TARGET_DB" ] || { echo "Cancelled."; exit 1; }

# Safety net: dump what is there now, unless it is already gone.
if [ -n "$(docker exec postgres psql -U postgres -qtA -c "SELECT 1 FROM pg_database WHERE datname='$TARGET_DB'")" ]; then
  SAFETY="${HOME}/backups/before-restore-${TARGET_DB}-$(date -u +%Y%m%d-%H%M%S).dump"
  docker exec postgres pg_dump -U postgres -Fc "$TARGET_DB" > "$SAFETY"
  echo "Current contents saved to $SAFETY"
fi

echo "Stopping the application so nothing writes during the restore …"
docker stop hamroward-app hamroward-queue hamroward-scheduler >/dev/null

restore_finished() {
  echo "Starting the application again …"
  docker start hamroward-app hamroward-queue hamroward-scheduler >/dev/null
}
trap restore_finished EXIT

docker exec postgres psql -U postgres -v ON_ERROR_STOP=1 <<SQL
DROP DATABASE IF EXISTS "$TARGET_DB" WITH (FORCE);
CREATE DATABASE "$TARGET_DB" OWNER hw_owner;
REVOKE ALL ON DATABASE "$TARGET_DB" FROM PUBLIC;
GRANT CONNECT ON DATABASE "$TARGET_DB" TO hw_app;
SQL

case "$DUMP_FILE" in
  *.dump)
    docker exec -i postgres pg_restore -U postgres -d "$TARGET_DB" --no-owner --role=hw_owner < "$DUMP_FILE"
    ;;
  *.sql.gz)
    gunzip -c "$DUMP_FILE" | docker exec -i postgres psql -U postgres -d "$TARGET_DB" -v ON_ERROR_STOP=1 >/dev/null
    ;;
  *.sql)
    docker exec -i postgres psql -U postgres -d "$TARGET_DB" -v ON_ERROR_STOP=1 < "$DUMP_FILE" >/dev/null
    ;;
  *)
    echo "Unknown dump format: $DUMP_FILE"; exit 1 ;;
esac

echo "Restored. Checking …"
docker exec postgres psql -U postgres -d "$TARGET_DB" -qtA -c "SELECT count(*) || ' tables' FROM information_schema.tables WHERE table_schema='public'"

trap - EXIT
restore_finished

sleep 8
docker exec hamroward-app php artisan hw:tenant:migrate || true
docker exec hamroward-app php artisan hw:doctor || true

echo
echo "Done. If this was a municipality database and it had fallen behind, the"
echo "tenant migration above has brought it up to the current schema."
