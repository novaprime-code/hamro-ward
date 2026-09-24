#!/usr/bin/env bash
# Copies the Hamro Ward bundles over the repository in the right order.
# Later bundles intentionally replace files from earlier ones (config/database.php,
# TenancyServiceProvider, AdminUnit, tests/Pest.php …), so the order matters.
#
#   bash tools/setup/apply-bundles.sh ~/Downloads/hamro-ward-bundles
#
# That folder must contain the unzipped bundles, each keeping its own name:
#   FIX-API-SCAFFOLD  HW-E29-F01  HW-E03-F01  HW-E04-F01  PHASE-1-2  PHASE-3  PHASE-4  PHASE-5
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="${1:-}"

ORDER=(
  "FIX-API-SCAFFOLD:pinned manifests, scaffold scripts, fixed web CI"
  "HW-E29-F01:tenancy foundation"
  "HW-E03-F01:administrative hierarchy"
  "HW-E04-F01:provenance schema"
  "PHASE-1-2:PostgreSQL image and database bootstrap"
  "PHASE-3:production images and the hamroward stack"
)

[ -n "$SRC" ] || { echo "Usage: $0 <folder with the unzipped bundles>"; exit 1; }
[ -d "$SRC" ] || { echo "No such folder: $SRC"; exit 1; }

echo "Repository: $ROOT"
echo "Bundles:    $SRC"
echo

MISSING=0
for entry in "${ORDER[@]}"; do
  name="${entry%%:*}"
  [ -d "$SRC/$name" ] || { echo "  missing bundle: $name"; MISSING=1; }
done
[ "$MISSING" = "0" ] || { echo; echo "Unzip the missing bundles into $SRC and run again."; exit 1; }

for entry in "${ORDER[@]}"; do
  name="${entry%%:*}"
  label="${entry#*:}"
  printf '\033[36m==>\033[0m %-18s %s\n' "$name" "$label"

  # Copy everything except the guides that live beside the files
  (cd "$SRC/$name" && find . -type f \
      ! -name 'APPLY.md' ! -name 'RECOVER.md' ! -name 'PHASE-*.md' -print0) |
  while IFS= read -r -d '' file; do
    rel="${file#./}"
    mkdir -p "$ROOT/$(dirname "$rel")"
    cp "$SRC/$name/$rel" "$ROOT/$rel"
    printf '    %s\n' "$rel"
  done
done

chmod +x "$ROOT"/tools/setup/*.sh 2>/dev/null || true
chmod +x "$ROOT"/infra/scripts/*.sh "$ROOT"/infra/stacks/hamroward/*.sh 2>/dev/null || true

cat <<'DONE'

All bundles applied. Now create the two skeletons, which is the step that was missed:

  bash tools/setup/scaffold-api.sh      # --docker if you have no local PHP
  bash tools/setup/scaffold-web.sh      # --docker if you have no local Node

Then check the two lock files exist before committing:

  git status --short | grep -E "composer.lock|pnpm-lock.yaml"

DONE
