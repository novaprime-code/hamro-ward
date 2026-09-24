#!/usr/bin/env bash
# Fills in the stock Laravel skeleton under apps/api without overwriting anything
# Hamro Ward already provides (bootstrap/app.php, config/*, app/Modules/*,
# database/migrations/*, tests/*, .env.example, Dockerfile …).
#
#   bash tools/setup/scaffold-api.sh            # uses local PHP + Composer
#   bash tools/setup/scaffold-api.sh --docker   # no local PHP needed
#
# Safe to re-run: existing files are never replaced.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API="$ROOT/apps/api"
LARAVEL_VERSION="12.*"
USE_DOCKER=0
[ "${1:-}" = "--docker" ] && USE_DOCKER=1

info() { printf '\n\033[36m==>\033[0m %s\n' "$1"; }
ok()   { printf '  \033[32mok\033[0m   %s\n' "$1"; }

composer_run() {
  # $1 = working directory, rest = composer arguments
  local workdir="$1"; shift
  if [ "$USE_DOCKER" = "1" ]; then
    mkdir -p "${HOME}/.cache/composer"
    docker run --rm -u "$(id -u):$(id -g)" \
      -v "$workdir":/app -w /app \
      -v "${HOME}/.cache/composer":/tmp/composer \
      -e COMPOSER_HOME=/tmp/composer \
      composer:2 "$@"
  else
    (cd "$workdir" && composer "$@")
  fi
}

if [ "$USE_DOCKER" = "0" ] && ! command -v composer >/dev/null 2>&1; then
  echo "Composer not found. Either install PHP 8.3+ and Composer, or run:"
  echo "  bash tools/setup/scaffold-api.sh --docker"
  exit 1
fi

[ -f "$API/composer.json" ] || {
  echo "apps/api/composer.json is missing — copy it from this fix bundle first."
  exit 1
}

# ---------------------------------------------------------------------------
# 1. Generate a stock Laravel app in a temporary folder
# ---------------------------------------------------------------------------
info "Creating a stock Laravel ${LARAVEL_VERSION} app to copy the missing files from"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

composer_run "$TMP" create-project laravel/laravel skeleton "$LARAVEL_VERSION" \
  --no-interaction --prefer-dist --no-scripts

# ---------------------------------------------------------------------------
# 2. Copy only what is missing
# ---------------------------------------------------------------------------
info "Copying the files apps/api does not have yet"
COPIED=0
KEPT=0

cd "$TMP/skeleton"
while IFS= read -r -d '' file; do
  rel="${file#./}"
  case "$rel" in
    vendor/*|node_modules/*|.git/*|composer.json|composer.lock) continue ;;
  esac

  dest="$API/$rel"
  if [ -e "$dest" ]; then
    KEPT=$((KEPT + 1))
  else
    mkdir -p "$(dirname "$dest")"
    cp "$file" "$dest"
    COPIED=$((COPIED + 1))
  fi
done < <(find . -type f -print0)
cd "$ROOT"

ok "copied ${COPIED} files, kept ${KEPT} of ours"

# ---------------------------------------------------------------------------
# 3. Remove the skeleton parts Hamro Ward replaces
# ---------------------------------------------------------------------------
info "Removing skeleton files we do not use"
rm -f "$API"/database/migrations/0001_01_01_000000_create_users_table.php \
      "$API"/database/migrations/0001_01_01_000001_create_cache_table.php \
      "$API"/database/migrations/0001_01_01_000002_create_jobs_table.php \
      "$API"/tests/Feature/ExampleTest.php \
      "$API"/tests/Unit/ExampleTest.php \
      "$API"/routes/api.php
ok "central migrations live in database/migrations/central"

# ---------------------------------------------------------------------------
# 4. Dependencies (from our pinned composer.json)
# ---------------------------------------------------------------------------
info "Resolving dependencies — this writes composer.lock, which the image build needs"
composer_run "$API" update --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# 5. Environment
# ---------------------------------------------------------------------------
if [ ! -f "$API/.env" ]; then
  cp "$API/.env.example" "$API/.env"
  ok ".env created from .env.example"
fi

if [ "$USE_DOCKER" = "1" ]; then
  docker run --rm -u "$(id -u):$(id -g)" -v "$API":/app -w /app php:8.4-cli \
    php artisan key:generate
else
  (cd "$API" && php artisan key:generate)
fi

for required in phpunit.xml tests/Pest.php bootstrap/app.php config/tenancy.php; do
  [ -f "$API/$required" ] || echo "  missing: apps/api/$required (ships with an earlier bundle)"
done

cat <<'DONE'

Done. Next:

  git add apps/api
  git status                  # composer.lock must appear — the Docker build copies it
  git commit -m "chore(api): add the Laravel skeleton so the image can build"
  git push

  make up
  cd apps/api && php artisan migrate && ./vendor/bin/pest

DONE
