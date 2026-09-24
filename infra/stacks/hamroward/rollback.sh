#!/usr/bin/env bash
# Puts Hamro Ward back on earlier images.
#
#   ./rollback.sh sha-a1b2c3d              # that API build, site stays
#   ./rollback.sh sha-a1b2c3d sha-9f8e7d6
#
# The schema is NOT rolled back. Migrations are written so the previous release
# still works against the new schema; if that isn't true for a particular
# release, restore the pre-deploy dump with hamroward-restore-db.sh as well.
set -euo pipefail

STACK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$STACK_DIR"

API_TAG="${1:-}"
WEB_TAG="${2:-}"

[ -n "$API_TAG" ] || { echo "Usage: ./rollback.sh <api-tag> [web-tag]"; exit 1; }

API_REPO="$(grep -E '^HAMROWARD_API_IMAGE=' .env | cut -d= -f2- | cut -d: -f1)"
WEB_REPO="$(grep -E '^HAMROWARD_WEB_IMAGE=' .env | cut -d= -f2- | cut -d: -f1)"

sed -i -e "s|^HAMROWARD_API_IMAGE=.*|HAMROWARD_API_IMAGE=${API_REPO}:${API_TAG}|" .env
[ -n "$WEB_TAG" ] && sed -i -e "s|^HAMROWARD_WEB_IMAGE=.*|HAMROWARD_WEB_IMAGE=${WEB_REPO}:${WEB_TAG}|" .env

docker compose pull
docker compose up -d

sleep 10
docker compose ps
docker exec hamroward-app php artisan hw:doctor || true

echo
echo "Rolled back to ${API_REPO}:${API_TAG}${WEB_TAG:+ and ${WEB_REPO}:${WEB_TAG}}."
echo "If the release had migrations that the old code cannot read, restore the"
echo "matching pre-deploy dump from ~/backups/predeploy-*/ as well."
