#!/usr/bin/env bash
# Deploys Hamro Ward: dumps every database first, swaps the images, waits for
# health, runs the built-in checks, and rolls back automatically if any of that
# fails.
#
#   ./deploy.sh                       # latest on both images
#   ./deploy.sh sha-a1b2c3d           # that API build, latest site
#   ./deploy.sh sha-a1b2c3d sha-9f8e7d6
#
# Migrations are never reversed automatically. Rolling the image back does not
# roll the schema back, which is why every migration must work with the previous
# release too (expand, migrate, contract) and why this script dumps first.
set -euo pipefail

STACK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_ROOT="${HOME}/backups"
CENTRAL_DB="hamroward"
PUBLIC_URL="${HAMROWARD_PUBLIC_URL:-https://hw.jayshyampatel.com.np}"
HEALTH_TIMEOUT=180

cd "$STACK_DIR"

say()  { printf '\n\033[36m==>\033[0m %s\n' "$1"; }
ok()   { printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

[ -f .env ] || { bad "no .env in $STACK_DIR"; exit 1; }
docker compose config >/dev/null || { bad "docker-compose.yml is not valid"; exit 1; }

API_TAG="${1:-latest}"
WEB_TAG="${2:-latest}"

API_REPO="$(grep -E '^HAMROWARD_API_IMAGE=' .env | cut -d= -f2- | cut -d: -f1)"
WEB_REPO="$(grep -E '^HAMROWARD_WEB_IMAGE=' .env | cut -d= -f2- | cut -d: -f1)"
PREVIOUS_API="$(grep -E '^HAMROWARD_API_IMAGE=' .env | cut -d= -f2-)"
PREVIOUS_WEB="$(grep -E '^HAMROWARD_WEB_IMAGE=' .env | cut -d= -f2-)"

say "Deploying ${API_REPO}:${API_TAG} and ${WEB_REPO}:${WEB_TAG}"
echo "  current: ${PREVIOUS_API} / ${PREVIOUS_WEB}"

# ---------------------------------------------------------------------------
# 1. Dump the central database and every municipality database
# ---------------------------------------------------------------------------
say "Backing up before the deploy"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/predeploy-${STAMP}"
mkdir -p "$BACKUP_DIR"

dump_database() {
  local db="$1"
  docker exec postgres pg_dump -U postgres -Fc "$db" > "${BACKUP_DIR}/${db}.dump"
  ok "dumped ${db} ($(du -h "${BACKUP_DIR}/${db}.dump" | cut -f1))"
}

dump_database "$CENTRAL_DB"

TENANT_DBS="$(docker exec postgres psql -U postgres -d "$CENTRAL_DB" -qtA \
  -c "SELECT database_name FROM tenants WHERE status <> 'archived' ORDER BY created_at" || true)"

if [ -n "$TENANT_DBS" ]; then
  while IFS= read -r db; do
    [ -n "$db" ] && dump_database "$db"
  done <<< "$TENANT_DBS"
else
  ok "no municipality databases yet"
fi

echo "  backups in ${BACKUP_DIR}"

# ---------------------------------------------------------------------------
# 2. Swap the image tags
# ---------------------------------------------------------------------------
say "Pulling images"
sed -i.bak \
  -e "s|^HAMROWARD_API_IMAGE=.*|HAMROWARD_API_IMAGE=${API_REPO}:${API_TAG}|" \
  -e "s|^HAMROWARD_WEB_IMAGE=.*|HAMROWARD_WEB_IMAGE=${WEB_REPO}:${WEB_TAG}|" \
  .env

restore_previous() {
  bad "Rolling back to ${PREVIOUS_API} / ${PREVIOUS_WEB}"
  sed -i \
    -e "s|^HAMROWARD_API_IMAGE=.*|HAMROWARD_API_IMAGE=${PREVIOUS_API}|" \
    -e "s|^HAMROWARD_WEB_IMAGE=.*|HAMROWARD_WEB_IMAGE=${PREVIOUS_WEB}|" \
    .env
  docker compose up -d
  echo
  echo "Rolled back. The database was NOT rolled back; dumps are in ${BACKUP_DIR}."
  echo "If this release migrated the schema, restore with:"
  echo "  ~/scripts/hamroward-restore-db.sh ${BACKUP_DIR}/${CENTRAL_DB}.dump ${CENTRAL_DB}"
  exit 1
}

docker compose pull || restore_previous

# ---------------------------------------------------------------------------
# 3. Start and wait for health
# ---------------------------------------------------------------------------
say "Starting containers"
docker compose up -d || restore_previous

printf '  waiting for hamroward-app to become healthy'
DEADLINE=$(( $(date +%s) + HEALTH_TIMEOUT ))
while [ "$(date +%s)" -lt "$DEADLINE" ]; do
  STATE="$(docker inspect -f '{{.State.Health.Status}}' hamroward-app 2>/dev/null || echo starting)"
  [ "$STATE" = "healthy" ] && break
  printf '.'
  sleep 3
done
echo

if [ "$(docker inspect -f '{{.State.Health.Status}}' hamroward-app 2>/dev/null)" != "healthy" ]; then
  bad "hamroward-app never became healthy"
  docker logs --tail 60 hamroward-app || true
  restore_previous
fi
ok "hamroward-app is healthy"

# ---------------------------------------------------------------------------
# 4. The application checks itself
# ---------------------------------------------------------------------------
say "Running hw:doctor"
docker exec hamroward-app php artisan hw:doctor || restore_previous

# ---------------------------------------------------------------------------
# 5. Smoke tests through the proxy
# ---------------------------------------------------------------------------
say "Smoke tests"
check_url() {
  local url="$1" expected="$2"
  local code
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$url" || echo 000)"
  if [ "$code" = "$expected" ]; then
    ok "${url} → ${code}"
  else
    bad "${url} → ${code} (expected ${expected})"
    return 1
  fi
}

check_url "${PUBLIC_URL}/" 200 || restore_previous
check_url "${PUBLIC_URL}/api/v1/health" 200 || restore_previous

if ! curl -sS --max-time 20 "${PUBLIC_URL}/api/v1/health" | grep -q '"status":"ok"'; then
  bad "health endpoint did not report ok"
  restore_previous
fi
ok "health endpoint reports ok"

rm -f .env.bak

say "Deployed"
echo "  API: ${API_REPO}:${API_TAG}"
echo "  Web: ${WEB_REPO}:${WEB_TAG}"
echo "  Pre-deploy dumps: ${BACKUP_DIR}"
echo
echo "Watch for a few minutes:  docker logs -f hamroward-app"
echo "Roll back if needed:      ./rollback.sh ${PREVIOUS_API##*:} ${PREVIOUS_WEB##*:}"
