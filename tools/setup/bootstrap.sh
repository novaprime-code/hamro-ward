#!/usr/bin/env bash
# Hamro Ward — one-time scaffold of apps/api (Laravel) and apps/web (Next.js).
# Safe to re-run: existing apps are left alone, kit files are always restored.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OVERLAY="$ROOT/tools/setup/overlay"

info()  { printf '\033[36m==>\033[0m %s\n' "$1"; }
warn()  { printf '\033[33m!  \033[0m %s\n' "$1"; }
need()  { command -v "$1" >/dev/null 2>&1 || { echo "Missing required tool: $1"; exit 1; }; }

need php; need composer; need node; need pnpm; need docker; need git

# ---------------------------------------------------------------------------
# 1. Keep a copy of the kit files, because the scaffolders overwrite some of them
# ---------------------------------------------------------------------------
if [ ! -d "$OVERLAY" ]; then
  info "Saving kit files to tools/setup/overlay"
  mkdir -p "$OVERLAY"
  cp -R "$ROOT/apps" "$OVERLAY/"
fi

# ---------------------------------------------------------------------------
# 2. Laravel
# ---------------------------------------------------------------------------
if [ -f "$ROOT/apps/api/artisan" ]; then
  info "apps/api already scaffolded, skipping"
else
  info "Creating Laravel app in apps/api"
  tmp="$(mktemp -d)"
  composer create-project laravel/laravel "$tmp/api" --no-interaction
  # keep kit files, take everything else from the scaffold
  cp -R "$tmp/api/." "$ROOT/apps/api/"
  rm -rf "$tmp"

  pushd "$ROOT/apps/api" >/dev/null
  info "Installing Pest, Larastan and Pint"
  composer require --dev --no-interaction \
    pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint
  php artisan pest:install --no-interaction || ./vendor/bin/pest --init || \
    warn "Pest init failed — run ./vendor/bin/pest --init yourself"
  rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
  popd >/dev/null
fi

# ---------------------------------------------------------------------------
# 3. Next.js
# ---------------------------------------------------------------------------
if [ -f "$ROOT/apps/web/package.json" ]; then
  info "apps/web already scaffolded, skipping"
else
  info "Creating Next.js app in apps/web"
  tmp="$(mktemp -d)"
  pnpm create next-app@latest "$tmp/web" \
    --ts --app --tailwind --eslint --src-dir --import-alias "@/*" --use-pnpm --no-git
  cp -R "$tmp/web/." "$ROOT/apps/web/"
  rm -rf "$tmp"

  pushd "$ROOT/apps/web" >/dev/null
  info "Installing Vitest and server-only"
  pnpm add -D vitest @vitejs/plugin-react
  pnpm add server-only
  # remove the scaffold's own root layout and page; ours live under [locale]
  rm -f src/app/layout.tsx src/app/page.tsx src/app/globals.css
  popd >/dev/null
fi

# ---------------------------------------------------------------------------
# 4. Restore kit files over the scaffolds
# ---------------------------------------------------------------------------
info "Restoring Hamro Ward files"
cp -R "$OVERLAY/apps/." "$ROOT/apps/"

# ---------------------------------------------------------------------------
# 5. Wire package scripts and workspace name
# ---------------------------------------------------------------------------
node - "$ROOT" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const root = process.argv[2];
const file = path.join(root, 'apps/web/package.json');
const pkg = JSON.parse(fs.readFileSync(file, 'utf8'));

pkg.name = 'web';
pkg.scripts = {
  ...pkg.scripts,
  dev: 'next dev',
  build: 'next build',
  start: 'next start',
  lint: pkg.scripts?.lint ?? 'next lint',
  test: 'vitest',
};

fs.writeFileSync(file, `${JSON.stringify(pkg, null, 2)}\n`);
console.log('apps/web/package.json updated');
NODE

info "Installing JS dependencies"
pnpm install

cat <<'DONE'

Next steps:

  cp apps/api/.env.example apps/api/.env
  cp apps/web/.env.example apps/web/.env.local
  (cd apps/api && php artisan key:generate)
  make up && make api      # API  → http://localhost:8000/api/v1/health
  make web                 # site → http://localhost:3000

DONE
