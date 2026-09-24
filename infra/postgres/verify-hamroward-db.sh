#!/usr/bin/env bash
# Checks that the Hamro Ward database setup is correct and isolated.
# Read-only except for one throwaway database it creates and drops again.
# Usage: ~/scripts/verify-hamroward-db.sh
set -euo pipefail

CENTRAL_DB="hamroward"
TEMPLATE_DB="template_hamroward"
PROBE_DB="hw_t_0000test"

pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }
FAILED=0

admin() { docker exec -i postgres psql -U postgres -d "${1:-postgres}" -qtA -v ON_ERROR_STOP=1; }

echo "Roles and databases"
for ROLE in hw_owner hw_app hw_provisioner; do
  [ -n "$(echo "SELECT 1 FROM pg_roles WHERE rolname='$ROLE';" | admin)" ] \
    && pass "role $ROLE exists" || fail "role $ROLE is missing"
done

[ "$(echo "SELECT rolcreatedb FROM pg_roles WHERE rolname='hw_provisioner';" | admin)" = "t" ] \
  && pass "hw_provisioner may create databases" || fail "hw_provisioner has no CREATEDB"

[ "$(echo "SELECT datistemplate FROM pg_database WHERE datname='$TEMPLATE_DB';" | admin)" = "t" ] \
  && pass "$TEMPLATE_DB is marked as a template" || fail "$TEMPLATE_DB is not a template"

echo "Extensions"
for DB in "$CENTRAL_DB" "$TEMPLATE_DB"; do
  MISSING="$(echo "SELECT string_agg(e, ', ') FROM unnest(ARRAY['postgis','pg_trgm','btree_gist','citext']) AS e
              WHERE e NOT IN (SELECT extname FROM pg_extension);" | admin "$DB")"
  [ -z "$MISSING" ] && pass "$DB has every extension" || fail "$DB is missing: $MISSING"
done

echo "Isolation"
if docker exec -i postgres psql -U hw_app -d n8n -c 'SELECT 1' >/dev/null 2>&1; then
  fail "hw_app can connect to the n8n database"
else
  pass "hw_app cannot reach other apps' databases"
fi

echo "Provisioning a throwaway municipality database"
docker exec -i postgres psql -U postgres -qtA -v ON_ERROR_STOP=1 <<SQL >/dev/null
DROP DATABASE IF EXISTS "$PROBE_DB" WITH (FORCE);
SQL

if docker exec -i postgres psql -U hw_provisioner -d postgres -qtA -v ON_ERROR_STOP=1 \
     -c "CREATE DATABASE \"$PROBE_DB\" OWNER hw_owner TEMPLATE $TEMPLATE_DB" >/dev/null 2>&1; then
  pass "hw_provisioner created $PROBE_DB from the template"

  [ "$(echo "SELECT count(*) FROM pg_extension WHERE extname='postgis';" | admin "$PROBE_DB")" = "1" ] \
    && pass "the copy inherited PostGIS" || fail "the copy has no PostGIS"

  docker exec -i postgres psql -U hw_provisioner -d postgres -qtA \
    -c "DROP DATABASE \"$PROBE_DB\" WITH (FORCE)" >/dev/null \
    && pass "hw_provisioner dropped it again" || fail "could not drop $PROBE_DB"
else
  fail "hw_provisioner could not create a database (run this inside the container as the postgres user, or check GRANT hw_owner TO hw_provisioner)"
fi

echo
[ "$FAILED" -eq 0 ] && echo "All checks passed." || { echo "Some checks failed."; exit 1; }
