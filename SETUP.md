# Hamro Ward — Setup Guide (Sprint 0)

This kit covers **W00 tasks only** (`HW-E01-F01-T01` … `HW-E01-F04-T01`, plus design tokens and locale routing): a working monorepo with a Laravel API, a Next.js site, a local Postgres/PostGIS stack, and CI.

Tenancy, accounts, geography and everything else come in the tasks after this, one session at a time.

**What you end up with after 45 minutes:**

* `http://localhost:8000/api/v1/health` → JSON with database status
* `http://localhost:3000` → redirects to `/ne`, renders the Nepali home page and shows the API health it fetched
* `make test` green, CI green on your first PR

---

## 0. Prerequisites

| Tool | Version | Check |
|---|---|---|
| PHP | 8.3+ with `pdo_pgsql`, `mbstring`, `intl`, `gd` | `php -v && php -m \| grep pgsql` |
| Composer | 2.x | `composer -V` |
| Node | 22 LTS | `node -v` |
| pnpm | 9+ | `pnpm -v` (else `corepack enable`) |
| Docker + Compose | current | `docker compose version` |
| make, git | any | `make -v` |

---

## 1. Create the repository

```bash
mkdir hamro-ward && cd hamro-ward
git init -b main
```

Copy the whole contents of this kit into that folder (the `apps/`, `infra/`, `tools/`, `.github/` directories and the root files). Then copy your existing docs:

```bash
mkdir -p docs tools/backlog
# copy the docs/ and tools/backlog/ folders from the planning zip into place
```

---

## 2. Scaffold the two applications

The kit contains only the files that differ from the stock scaffolds. Generate the scaffolds first, then let the kit files overwrite.

```bash
# from the repo root
bash tools/setup/bootstrap.sh
```

That script runs, in order:

1. `composer create-project laravel/laravel apps/api` (skipped if `apps/api/artisan` exists)
2. `composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint` inside `apps/api`
3. `php artisan pest:install`
4. `pnpm create next-app@latest apps/web --ts --app --tailwind --eslint --src-dir --import-alias "@/*" --use-pnpm --no-turbopack`
5. Restores the kit's own files over both apps (they are kept in `tools/setup/overlay/` by the script)

If you prefer to run the steps yourself, run them in that order and then copy the kit's `apps/` files over the generated ones.

---

## 3. Environment files

```bash
cp apps/api/.env.example apps/api/.env
cp apps/web/.env.example apps/web/.env.local
cd apps/api && php artisan key:generate && cd ../..
```

---

## 4. Start the local stack

```bash
make up        # postgres/postgis + mailpit in Docker
make api       # Laravel on http://localhost:8000
make web       # Next.js on http://localhost:3000  (second terminal)
```

Check:

```bash
curl -s http://localhost:8000/api/v1/health | jq
open http://localhost:3000
```

Mailpit UI: `http://localhost:8025`.

---

## 5. Verify

```bash
make lint      # pint --test, phpstan, eslint, tsc
make test      # pest + vitest
```

Commit and open your first PR:

```bash
git add -A
git commit -m "chore: bootstrap monorepo [HW-E01-F01-T01] [HW-E01-F02-T01] [HW-E01-F02-T02]"
git remote add origin git@github.com:<ORG>/hamro-ward.git
git push -u origin main
```

Then in GitHub: Settings → Branches → protect `main` (require a PR and the `api` and `web` checks).

---

## 6. What each file is for

| File | Task | Purpose |
|---|---|---|
| `Makefile`, `pnpm-workspace.yaml`, `.editorconfig`, `.gitignore` | `HW-E01-F01-T02` | One-command workflows and workspace wiring |
| `README.md`, `CONTRIBUTING.md`, `CODEOWNERS`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/*` | `HW-E01-F01-T02` | Branch naming, commit format, review rules |
| `infra/compose/compose.dev.yml`, `infra/docker/postgres/init/00-roles.sql` | `HW-E01-F03-T01` | Postgres 17 + PostGIS with the four database roles from `12` §7, plus Mailpit |
| `apps/api/bootstrap/app.php`, `routes/api_v1.php`, `app/Modules/Support/Http/Controllers/HealthController.php` | `HW-E01-F02-T01` | `/api/v1/health` and the module namespace |
| `apps/api/config/database.php` | `HW-E01-F02-T01`, prepares `HW-E29-F01-T02` | `central` (default) and `tenant` connections; the tenant database name is set at runtime |
| `apps/api/pint.json`, `phpstan.neon`, `tests/Arch/ArchTest.php`, `tests/Feature/HealthEndpointTest.php` | `HW-E01-F02-T01` | Style, static analysis, architecture rules, first test |
| `apps/web/next.config.ts` | `HW-E01-F02-T02`, prepares `HW-E13-F01-T02` | Same-origin `/api` and `/sanctum` proxy to Laravel, standalone output |
| `apps/web/src/middleware.ts` | `HW-E07-F01-T01` | `/` → `/ne`, locale validation, admin-host placeholder |
| `apps/web/src/i18n/*`, `messages/{ne,en}.json` | `HW-E07-F01-T01` | Minimal locale loader (no library yet — `next-intl` is evaluated when the message count grows) |
| `apps/web/src/app/[locale]/{layout,page,globals.css}` | `HW-E07-F01-T02` | Design tokens from `09` §3, self-hosted fonts, ward plate, beta banner |
| `.github/workflows/ci-api.yml`, `ci-web.yml` | `HW-E01-F04-T01` | CI with a PostGIS service, audits included |

---

## 7. Known version drift

Check these once; they change between releases:

* **Tailwind v4** uses CSS-first config (`@import "tailwindcss"` plus `@theme`), which is what `globals.css` uses. If `create-next-app` gives you v3, keep its `tailwind.config.ts` and move the tokens from `@theme` into `theme.extend`.
* **Pest 4** changes the `pest:install` command name in some versions; if it fails, `./vendor/bin/pest --init`.
* **Larastan** ships `extension.neon` under `vendor/larastan/larastan/`; older versions use `nunomaduro/larastan`. Fix the path in `phpstan.neon` if the include errors.
* **Laravel 12+** `bootstrap/app.php` supports `apiPrefix`. On Laravel 11 the same file works.

---

## 8. Next sessions

In backlog order, with your current files pasted in:

```
HW-E29-F01-T01  tenancy spike        (Tue 22 Sep)
HW-E29-F01-T02  tenancy foundation   (Wed 23 Sep)
HW-E03-F01-T01  admin_units schema   (Thu 24 Sep)
```

Friend A in parallel: `HW-E07-F03-T01` (ProvenanceBadge, SourceSheet) on Thu 24 Sep.
