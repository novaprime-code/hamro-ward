#!/usr/bin/env bash
# Fills in the stock Next.js skeleton under apps/web without overwriting anything
# Hamro Ward already provides (next.config.ts, src/**, messages/**, Dockerfile,
# .env.example, package.json …).
#
#   bash tools/setup/scaffold-web.sh            # uses local Node + pnpm
#   bash tools/setup/scaffold-web.sh --docker   # no local Node needed
#
# Safe to re-run: existing files are never replaced.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB="$ROOT/apps/web"
NEXT_VERSION="15"
USE_DOCKER=0
[ "${1:-}" = "--docker" ] && USE_DOCKER=1

info() { printf '\n\033[36m==>\033[0m %s\n' "$1"; }
ok()   { printf '  \033[32mok\033[0m   %s\n' "$1"; }

node_run() {
  local workdir="$1"; shift
  if [ "$USE_DOCKER" = "1" ]; then
    docker run --rm -u "$(id -u):$(id -g)" \
      -v "$workdir":/work -w /work -e HOME=/tmp \
      node:22-alpine sh -lc "corepack enable >/dev/null 2>&1; $*"
  else
    (cd "$workdir" && eval "$*")
  fi
}

if [ "$USE_DOCKER" = "0" ] && ! command -v pnpm >/dev/null 2>&1; then
  echo "pnpm not found. Run 'corepack enable', or use:"
  echo "  bash tools/setup/scaffold-web.sh --docker"
  exit 1
fi

[ -f "$WEB/package.json" ] || {
  echo "apps/web/package.json is missing — copy it from this fix bundle first."
  exit 1
}

info "Creating a stock Next.js ${NEXT_VERSION} app to copy the missing files from"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

node_run "$TMP" "pnpm dlx create-next-app@${NEXT_VERSION} skeleton \
  --ts --app --tailwind --eslint --src-dir --import-alias '@/*' --use-pnpm --no-git --yes"

info "Copying the files apps/web does not have yet"
COPIED=0
KEPT=0

cd "$TMP/skeleton"
while IFS= read -r -d '' file; do
  rel="${file#./}"
  case "$rel" in
    node_modules/*|.next/*|.git/*|package.json|pnpm-lock.yaml) continue ;;
    src/app/layout.tsx|src/app/page.tsx|src/app/globals.css|src/app/favicon.ico) continue ;;
  esac

  dest="$WEB/$rel"
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
ok "our src/app/[locale]/{layout,page,globals.css} were not touched"

info "Installing dependencies — this writes pnpm-lock.yaml, which the image build needs"
node_run "$ROOT" "pnpm install"

cat <<'DONE'

Done. Next:

  git add apps/web pnpm-lock.yaml
  git status                  # pnpm-lock.yaml must appear
  git commit -m "chore(web): add the Next.js skeleton so the image can build"

  pnpm --filter web dev       # http://localhost:3000 redirects to /ne

DONE
