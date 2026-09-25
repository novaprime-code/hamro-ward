#!/usr/bin/env bash
# =============================================================================
# create-central-database.sh — create one Hamro Ward CENTRAL database.
#
# The shared PostgreSQL server gets one central database per environment:
#
#     hamroward              the original
#     hamroward_staging      DB_DATABASE in the hamroward-staging stack
#     hamroward_production   DB_DATABASE in the hamroward-production stack
#
# ~/scripts/create-hamroward-db.sh (Phase 1–2) creates the roles, the first
# central database and template_hamroward. It runs once, ever. This script is
# the small sequel: one more central database, on a server that already has
# the roles and the template.
#
# Tenant (municipality) databases are NOT created here — the application makes
# those itself through `hw:tenant:create`.
#
# Usage, on the server:
#
#     ~/scripts/create-central-database.sh hamroward_staging
#     ~/scripts/create-central-database.sh hamroward_production
#
#     PG_CONTAINER=postgres ~/scripts/create-central-database.sh <name>
#
# Safe to run twice: an existing database is verified, never touched.
# =============================================================================
set -euo pipefail

DB_NAME="${1:-}"
PG_CONTAINER="${PG_CONTAINER:-postgres}"
PG_SUPERUSER="${PG_SUPERUSER:-postgres}"
TEMPLATE="${TEMPLATE:-template_hamroward}"
OWNER_ROLE="${OWNER_ROLE:-hw_owner}"
APP_ROLE="${APP_ROLE:-hw_app}"
# What template_hamroward carries. Overridable so the script can be exercised
# against a server whose template is built differently.
EXTENSIONS="${EXTENSIONS:-postgis pg_trgm btree_gist citext}"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '  %s\n' "$*"; }

die() { red "ERROR: $*"; exit 1; }

# psql -At: no headers, no padding — the output is parsed, not read.
psql_q() { docker exec -i "$PG_CONTAINER" psql -U "$PG_SUPERUSER" -d postgres -At -c "$1"; }
psql_x() { docker exec -i "$PG_CONTAINER" psql -U "$PG_SUPERUSER" -d postgres -v ON_ERROR_STOP=1 -q -c "$1"; }

# ---------------------------------------------------------------------------
# 1. Arguments
# ---------------------------------------------------------------------------
[ -n "$DB_NAME" ] || die "usage: $(basename "$0") <database-name>   (e.g. hamroward_staging)"

# A central database is created here; a tenant database is not. Refusing the
# tenant prefixes stops this script being used to hand-make one, which would
# skip the tenants row and produce a database the application does not know
# about.
case "$DB_NAME" in
    hw_t_*|hw_st_*|hw_pr_*)
        die "$DB_NAME looks like a municipality database. Those are created by 'php artisan hw:tenant:create'." ;;
    hamroward|hamroward_*) : ;;
    *)  die "$DB_NAME does not look like a central database name (expected hamroward or hamroward_<env>)." ;;
esac

echo
echo "Central database : $DB_NAME"
echo "Server           : container '$PG_CONTAINER' as '$PG_SUPERUSER'"
echo "Template         : $TEMPLATE"
echo

# ---------------------------------------------------------------------------
# 2. Preconditions
# ---------------------------------------------------------------------------
docker inspect "$PG_CONTAINER" >/dev/null 2>&1 \
    || die "no container named '$PG_CONTAINER'. Set PG_CONTAINER=<name>."

psql_q "SELECT 1" >/dev/null || die "cannot run psql inside '$PG_CONTAINER' as '$PG_SUPERUSER'."

for role in "$OWNER_ROLE" "$APP_ROLE"; do
    [ "$(psql_q "SELECT count(*) FROM pg_roles WHERE rolname = '$role'")" = "1" ] \
        || die "role '$role' does not exist. Run ~/scripts/create-hamroward-db.sh first."
done

[ "$(psql_q "SELECT count(*) FROM pg_database WHERE datname = '$TEMPLATE'")" = "1" ] \
    || die "template database '$TEMPLATE' does not exist. Run ~/scripts/create-hamroward-db.sh first."

[ "$(psql_q "SELECT datistemplate FROM pg_database WHERE datname = '$TEMPLATE'")" = "t" ] \
    || die "'$TEMPLATE' is not marked as a template; CREATE DATABASE ... TEMPLATE would need superuser."

# ---------------------------------------------------------------------------
# 3. Create — or skip, if it is already there
# ---------------------------------------------------------------------------
if [ "$(psql_q "SELECT count(*) FROM pg_database WHERE datname = '$DB_NAME'")" = "1" ]; then
    info "$DB_NAME already exists — verifying it, changing nothing."
else
    # Copying the template is what gives the new database PostGIS, pg_trgm,
    # btree_gist and citext, and the default privileges that let $APP_ROLE read
    # and write the tables $OWNER_ROLE will create in it. Creating the database
    # empty and adding extensions afterwards needs superuser and gets the
    # default privileges wrong in a way that only shows up at the first write.
    info "creating $DB_NAME from $TEMPLATE, owned by $OWNER_ROLE"
    psql_x "CREATE DATABASE \"$DB_NAME\" TEMPLATE \"$TEMPLATE\" OWNER \"$OWNER_ROLE\""
fi

# ---------------------------------------------------------------------------
# 4. Close it
#
# A database-level ACL is NOT copied from the template. A fresh database is
# open to PUBLIC, which on this server means every role — including the one
# n8n connects with. So this runs every time, including on an existing
# database, because it is the step most likely to have been missed.
# ---------------------------------------------------------------------------
info "revoking PUBLIC access, granting CONNECT to $OWNER_ROLE and $APP_ROLE"
psql_x "REVOKE ALL ON DATABASE \"$DB_NAME\" FROM PUBLIC"
psql_x "GRANT CONNECT, TEMPORARY ON DATABASE \"$DB_NAME\" TO \"$OWNER_ROLE\""
psql_x "GRANT CONNECT ON DATABASE \"$DB_NAME\" TO \"$APP_ROLE\""

if [ "$(psql_q "SELECT count(*) FROM pg_roles WHERE rolname = 'hw_backup'")" = "1" ]; then
    psql_x "GRANT CONNECT ON DATABASE \"$DB_NAME\" TO hw_backup"
    info "granted CONNECT to hw_backup"
fi

# ---------------------------------------------------------------------------
# 5. Verify — each line proves one thing, and prints what it found
# ---------------------------------------------------------------------------
echo
echo "Checks:"
failed=0
check() { # check <label> <expected> <sql>
    actual="$(psql_q "$3")"
    if [ "$actual" = "$2" ]; then
        printf '  ok    %-46s %s\n' "$1" "$actual"
    else
        printf '  FAIL  %-46s %s (expected %s)\n' "$1" "$actual" "$2"
        failed=1
    fi
}

check "database exists" "1" \
    "SELECT count(*) FROM pg_database WHERE datname = '$DB_NAME'"
check "owned by $OWNER_ROLE" "$OWNER_ROLE" \
    "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '$DB_NAME'"
check "encoding is UTF8" "UTF8" \
    "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = '$DB_NAME'"
# datacl IS NULL means untouched, which is open to PUBLIC — aclexplode of NULL
# returns no rows, so the NULL has to be tested separately or it reads as safe.
check "closed to PUBLIC" "f" \
    "SELECT (datacl IS NULL) OR EXISTS (SELECT 1 FROM aclexplode(datacl) a WHERE a.grantee = 0)
       FROM pg_database WHERE datname = '$DB_NAME'"
check "$APP_ROLE may connect" "t" \
    "SELECT has_database_privilege('$APP_ROLE', '$DB_NAME', 'CONNECT')"
check "$OWNER_ROLE may connect" "t" \
    "SELECT has_database_privilege('$OWNER_ROLE', '$DB_NAME', 'CONNECT')"

for ext in $EXTENSIONS; do
    actual="$(docker exec -i "$PG_CONTAINER" psql -U "$PG_SUPERUSER" -d "$DB_NAME" -At \
        -c "SELECT count(*) FROM pg_extension WHERE extname = '$ext'")"
    if [ "$actual" = "1" ]; then
        printf '  ok    %-46s present\n' "extension $ext"
    else
        printf '  FAIL  %-46s missing\n' "extension $ext"
        failed=1
    fi
done

echo
if [ "$failed" -ne 0 ]; then
    red "Some checks failed. Do not point a stack at this database yet."
    exit 1
fi

green "All checks passed."
echo
echo "Next:"
echo "  1. DB_DATABASE=$DB_NAME in the stack's environment variables"
echo "  2. Portainer → Stacks → the stack → Update the stack"
echo "  3. docker logs -f <stack>-app    # '[hamroward] central migrations' should now succeed"
echo
