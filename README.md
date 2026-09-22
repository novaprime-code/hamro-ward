# Hamro Ward

**तपाईंको वडा, तपाईंको जानकारी, तपाईंको आवाज** — your ward, your information, your voice.

A neutral civic information platform for Nepal, organized around local levels and wards. Every public fact carries its source.

## Repository

```
apps/api      Laravel API (modular monolith, PHP 8.3+)
apps/web      Next.js site and staff dashboard (TypeScript, Tailwind)
infra/        Docker Compose, Postgres init, deploy scripts
docs/         Requirements, architecture, data model, UX, plan, decisions
tools/backlog Backlog builder and tracker importers
```

Start with `docs/README.md` for the document index and `SETUP.md` for the local setup.

## Quick start

```bash
bash tools/setup/bootstrap.sh     # once: generate the app scaffolds
cp apps/api/.env.example apps/api/.env
cp apps/web/.env.example apps/web/.env.local
cd apps/api && php artisan key:generate && cd ../..
make up && make api               # API on :8000
make web                          # site on :3000 (second terminal)
```

## Principles

* **Provenance first.** A fact without a verified source shows as "not yet verified" rather than as a value.
* **Neutral by construction.** No scores, no rankings, no party colours, identical templates for everyone.
* **Mobile first.** Low bandwidth, Nepali first, small pages.
* **One tenant per local level.** Each municipality's data lives in its own database.

## Licence

To be decided (`docs/01_CONSTRAINTS_AND_TEAM.md`, open item).
