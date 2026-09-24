#!/usr/bin/env bash
# Prepares the shared PostgreSQL server for Hamro Ward. Run once, as the server
# user, with the "postgres" container running. Same house style as
# ~/scripts/pg-create-db.sh, but Hamro Ward needs more than one database:
#
#   hw_owner         owns the schema, runs migrations
#   hw_app           application access (no UPDATE/DELETE on append-only tables)
#   hw_provisioner   CREATEDB; creates one database per municipality (CLI only),
#                    member of hw_owner so the new databases are owned by it
#
#   hamroward            central database: accounts, geography, persons, registries
#   template_hamroward   template every municipality database is copied from, so
#                        the provisioner never needs superuser rights and
#                        template1 stays untouched for your other apps
#
# Municipality databases are named hw_t_<8 hex>. The nightly backup finds them
# on its own, because it loops over every database on the server.
#
# Usage: ~/scripts/create-hamroward-db.sh
set -euo pipefail

CENTRAL_DB="hamroward"
TEMPLATE_DB="template_hamroward"
ROLES=(hw_owner hw_app hw_provisioner)
EXTENSIONS=(postgis pg_trgm btree_gist citext)

if [ "$(docker inspect -f '{{.State.Running}}' postgres 2>/dev/null)" != "true" ]; then
  echo "The postgres container isn't running. Start it: cd ~/stacks/postgres && docker compose up -d"
  exit 1
fi

psql_admin() {
  docker exec -i postgres psql -U postgres -d "${1:-postgres}" -v ON_ERROR_STOP=1 -qtA
}

# --- refuse to run twice -----------------------------------------------------
for ROLE in "${ROLES[@]}"; do
  if [ -n "$(echo "SELECT 1 FROM pg_roles WHERE rolname = '$ROLE';" | psql_admin)" ]; then
    echo "Role \"$ROLE\" already exists. Nothing changed."
    echo "If this is a re-run after a failure, drop what was created first, or ask before continuing."
    exit 1
  fi
done

for DB in "$CENTRAL_DB" "$TEMPLATE_DB"; do
  if [ -n "$(echo "SELECT 1 FROM pg_database WHERE datname = '$DB';" | psql_admin)" ]; then
    echo "Database \"$DB\" already exists. Nothing changed."
    exit 1
  fi
done

# Check PostGIS is available before creating anything.
if [ -z "$(echo "SELECT 1 FROM pg_available_extensions WHERE name = 'postgis';" | psql_admin)" ]; then
  echo "This PostgreSQL image has no PostGIS."
  echo "Swap ~/stacks/postgres to ghcr.io/novaprime-code/hamro-ward-postgres:pg17-<date> first (see PHASE-1-2.md)."
  exit 1
fi

OWNER_PASSWORD="$(openssl rand -hex 24)"
APP_PASSWORD="$(openssl rand -hex 24)"
PROVISIONER_PASSWORD="$(openssl rand -hex 24)"

# --- roles and databases -----------------------------------------------------
psql_admin <<SQL
CREATE ROLE hw_owner LOGIN PASSWORD '$OWNER_PASSWORD';
CREATE ROLE hw_app LOGIN PASSWORD '$APP_PASSWORD';
CREATE ROLE hw_provisioner LOGIN PASSWORD '$PROVISIONER_PASSWORD' CREATEDB;

-- The provisioner creates municipality databases owned by hw_owner, and drops
-- them when a municipality is archived. Both need the owner's privileges.
GRANT hw_owner TO hw_provisioner;

CREATE DATABASE "$CENTRAL_DB" OWNER hw_owner;
REVOKE ALL ON DATABASE "$CENTRAL_DB" FROM PUBLIC;
GRANT CONNECT ON DATABASE "$CENTRAL_DB" TO hw_app;

CREATE DATABASE "$TEMPLATE_DB" OWNER hw_owner;
REVOKE ALL ON DATABASE "$TEMPLATE_DB" FROM PUBLIC;
SQL

# --- extensions and grants, in both databases --------------------------------
for DB in "$CENTRAL_DB" "$TEMPLATE_DB"; do
  {
    for EXT in "${EXTENSIONS[@]}"; do
      echo "CREATE EXTENSION IF NOT EXISTS \"$EXT\";"
    done
    cat <<SQL
GRANT USAGE ON SCHEMA public TO hw_app;

-- Everything hw_owner creates later (migrations) is usable by hw_app.
-- Default privileges live in the database, so copies of the template inherit them.
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;
SQL
  } | psql_admin "$DB"
done

# --- mark the template -------------------------------------------------------
# datistemplate lets any CREATEDB role copy it, which is what keeps the
# provisioner away from superuser rights.
psql_admin <<SQL
UPDATE pg_database SET datistemplate = true WHERE datname = '$TEMPLATE_DB';
SQL

echo
echo "Created roles hw_owner, hw_app, hw_provisioner and databases $CENTRAL_DB, $TEMPLATE_DB."
echo "Extensions in both: ${EXTENSIONS[*]} (plus pgvector when the assistant needs it)."
echo
echo "Passwords (shown only now — put them in ~/stacks/hamroward/.env and your password manager):"
echo "  HW_OWNER_PASSWORD=$OWNER_PASSWORD"
echo "  HW_APP_PASSWORD=$APP_PASSWORD"
echo "  HW_PROVISIONER_PASSWORD=$PROVISIONER_PASSWORD"
