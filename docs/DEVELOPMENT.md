# Development guide

How to run Hamro Ward locally, how the code is organised, and the specific
traps this codebase has already sprung. The last section is the one to read
before writing a seeder, a model or a route — every item in it cost real
debugging time.

---

## 1. Prerequisites

| | |
|---|---|
| PHP | 8.3+ with `pdo_pgsql`, `intl`, `gd` |
| Composer | 2.x |
| Node | 20+ |
| pnpm | 9.x (the repo is a pnpm workspace; npm will not resolve it) |
| Docker | for PostgreSQL + PostGIS and Mailpit |

PostgreSQL runs in Docker rather than natively because the application needs
PostGIS, `pg_trgm`, `btree_gist` and `citext`, and because tenant databases are
created from a template that carries all four.

---

## 2. First run

```bash
git clone git@github.com:novaprime-code/hamro-ward.git
cd hamro-ward
pnpm install
(cd apps/api && composer install)

make up          # postgres (with PostGIS) + mailpit
make reset-db    # roles, central database, template_hamroward
```

Then the API:

```bash
cd apps/api
cp .env.example .env
php artisan key:generate
php artisan migrate --database=central_owner --path=database/migrations/central
php artisan db:seed --force
php artisan hw:demo:seed
php artisan serve
```

And the web app:

```bash
cd apps/web
pnpm dev
```

`http://localhost:3000/ne` should list four municipalities.

**Order matters.** `db:seed` loads the source types and the positions catalogue.
`hw:demo:seed` copies the positions catalogue into each tenant database it
creates, so running it first produces four municipalities with no seats and no
error.

---

## 3. The databases

There are two kinds, and which one a model uses is part of its definition.

**Central** (`hamroward`) holds identity, the administrative hierarchy, persons,
parties, catalogues and registries — anything national, shared between local
levels, or about a person.

**Tenant** (`hw_t_<8 hex>`, `hw_st_…` on staging) holds what is produced or
operated inside one municipality: office holdings, vacancies, ward offices,
issues, and read-only replicas of the catalogues and of that municipality's own
slice of geography.

Migrations are split to match:

```
apps/api/database/migrations/central/   → php artisan migrate --path=…/central
apps/api/database/migrations/tenant/    → php artisan hw:tenant:migrate
```

Four connections exist: `central` (app role), `central_owner` (schema owner, for
migrations), `tenant` / `tenant_owner` (pointed at a database at runtime by
`TenantManager`), and `provisioner` (`CREATEDB` only, aimed at the `postgres`
maintenance database, used by nothing except tenant creation).

Useful commands:

```bash
php artisan hw:tenant:create koshi/sunsari/koshara   # onboard one local level
php artisan hw:tenant:migrate                        # every tenant, sequentially
php artisan hw:tenant:sync-reference                 # refresh catalogue replicas
php artisan hw:demo:seed                             # idempotent, re-runnable
php artisan hw:demo:drop                             # drops the demo tenants
php artisan hw:import ./sheet --dry-run              # check a CSV sheet; report in storage/app/imports/
php artisan hw:import ./sheet --tenant=koshi/sunsari/koshara   # load one municipality's rows
php artisan hw:outbox:dispatch                       # drain every tenant's outbox (scheduled each minute)
php artisan hw:index:rebuild                         # recompute the person-page index (hourly; after a restore)
```

`hw:demo:seed` is safe to run repeatedly: every identifier is a UUIDv5 over a
fixed namespace, so a re-seed updates the same rows and yesterday's screenshots
still match. It refuses to run when `APP_ENV=production` unless
`HW_ALLOW_DEMO_DATA=true`, and refuses if a demonstration name ever collides
with a real published local level.

---

## 4. Backend layout

```
app/Modules/<Module>/
  Actions/        one class, one job; owns its transaction and audit event
  Queries/        read paths that don't belong to a model
  Models/         Eloquent, with the connection trait that says which database
  Http/           Controllers (thin), Requests, Resources
  Enums/ Casts/ Exceptions/ Providers/
```

Rules, enforced by Pest architecture tests:

* a controller validates, calls **one** action or query, and returns a Resource;
* a module may use another module's actions, queries, resources and read-only
  models — never write to another module's tables;
* `Audit`, `Provenance`, `Calendar` and `Support` are foundation modules that
  anyone may use and that depend on no feature module;
* central models declare the central connection, tenant models the tenant one;
* raw `DB::` calls name their connection.

Public API routes go in **`routes/api_v1.php`**. The `api/v1` prefix is applied
in `bootstrap/app.php`, so routes inside that file are declared without it.
There is no `routes/api.php` — a file by that name is not loaded and anything in
it silently does nothing.

---

## 5. Frontend layout

Next.js 15, App Router, React Server Components by default. Tailwind v4, with
shadcn/ui primitives.

```
src/
  app/[locale]/…        pages; every one is a server component
  components/ui/        shadcn primitives — owned, editable, not a dependency
  components/civic/     the product's own vocabulary: seats, provenance, states
  components/layout/    header, footer, beta banner
  i18n/                 locale config and the message loader
  lib/                  api client, theme resolution, cn()
  styles/tokens.css     THE only file containing a colour value
```

**Colour.** Five palettes (`himal` default, `sal`, `aaba`, `raat`, `kagaj`) live
in `tokens.css` as CSS custom properties, selected by `data-theme` on `<html>`
from the `HW_THEME` environment variable at request time. `globals.css` maps
shadcn's token names onto them inside `@theme inline`, so `bg-primary` compiles
to `background-color: var(--accent)` and an unmodified shadcn component follows
whichever palette is active. Adding a palette is a block in `tokens.css` plus a
name in `lib/theme.ts`.

**shadcn.** `components.json` is configured, so `pnpm dlx shadcn@latest add
dialog` works. Never run `shadcn init` — it rewrites `globals.css` and would
flatten the palette mapping.

**Client components.** There is currently exactly one (`MunicipalityPicker`),
and that is a target, not an accident. The audience is on Android phones and
inconsistent connections.

---

## 6. Tests and checks

```bash
cd apps/api && ./vendor/bin/pest          # creates and drops real tenant databases
cd apps/api && ./vendor/bin/pint --test   # formatting
cd apps/api && ./vendor/bin/phpstan analyse

cd apps/web && pnpm test                  # vitest
cd apps/web && pnpm build                 # ← run this before every push
```

`pnpm --filter web build` takes about twenty seconds and has so far caught
unresolved imports, a type-predicate error, a server/client boundary violation
and a missing component — every one of which would otherwise have surfaced
inside a Docker build several minutes later.

---

## 7. Branches and deployment

`staging` builds and tags `:staging`; `main` builds and tags `:production`. The
pipelines **build only** — deployment is Portainer → Stacks → *Update the stack*
with *Re-pull image* ticked. The reasoning, including why there is no webhook,
is `D-016`.

Feature branches are `type/HW-ID-slug` and merge into `staging`.

---

## 8. Traps

Every one of these has already cost a debugging session. They are here so the
second occurrence costs nothing.

### Eloquent

**`Person`'s table is `persons`.** Laravel's pluraliser turns `Person` into
`people`. The model declares `protected $table = 'persons'`. Any new model
whose plural is irregular needs the same.

**A uuid primary key must be declared.** `Model::getCasts()` merges
`[keyName => keyType]` into the casts when `$incrementing` is true — and both
defaults apply unless overridden, so `id` gets cast to `int`. A uuid then reads
back as `81014` or `0`. Use the `HasUuids` trait, or declare
`public $incrementing = false;` and `protected $keyType = 'string';` explicitly.
`TenantAdminUnit` does the latter because it is a replica and never generates
ids.

**Mass assignment silently drops what isn't fillable — including `id`.**
`updateOrCreate(['id' => $uuid], […])` on a model with a restrictive `$fillable`
creates a row with a *different*, generated id and no error. Seeders and
importers that own their identifiers write with `forceFill` instead. This
produced a foreign-key violation three tables away from the actual cause.

**A self-referencing foreign key cannot be declared inside `Schema::create`.**
Laravel appends the commands implied by fluent modifiers — including the primary
key from `uuid('id')->primary()` — *after* everything the closure added, so the
foreign key is emitted before the key it points at and PostgreSQL rejects it.
Add it in a following `Schema::table` call, or with `DB::statement`.

### Database

**Cross-database foreign keys do not exist.** `office_holdings.person_id` is a
plain uuid referencing a central row, validated on write and batched on read
(`D-014`). Don't "fix" it by adding a constraint.

**A new database copied from a template is open to `PUBLIC`.** The
database-level ACL is not inherited. `infra/postgres/create-central-database.sh`
revokes and grants explicitly; anything else creating a database must too. The
server also runs n8n, so "every role on the server" is not hypothetical.

**Creating a database needs the provisioner role.** `hw_owner` owns schemas;
`hw_provisioner` holds `CREATEDB`. Its credentials go to the `app` container
only, never to queue or scheduler, and are read through the `provisioner`
connection.

### Frontend

**Functions cannot cross into a client component.** Props are serialised.
Resolve labels on the server and pass strings or plain records — a function prop
fails the whole render, it does not degrade.

**`text-muted` is a background colour now.** In shadcn's vocabulary `muted` is a
surface and `muted-foreground` is the quiet text on it. Using `text-muted` for
text produces nearly invisible text.

**shadcn's `accent` is not the brand.** It is the subtle hover wash. The brand
is `primary`.

**A card rendered through `asChild` must be `block`.** `Card` takes `asChild`,
so a whole card can *be* the link rather than containing one. An `<a>` is
inline by default, so without `block` in the card's base class the background
and border collapse to a sliver: the markup type-checks, the build is green,
and the cards are simply not there on the page. `block` is in the base class
for that reason and is a no-op on a `div`.

**Card padding is set in `card.tsx`, not at call sites.** The registry's
`px-6 py-6` assumes a desktop dashboard; these are lists on a phone, so the
scale is `p-4`, with `CardHeader`/`CardFooter` trimming the edge they sit
against. Section rhythm is `space-y-3` inside a section, `space-y-4` when it
holds a grid, `space-y-8` between sections. Override it in one place or the
lists drift apart.

**A local level's name already contains its type.** "कोशारा उपमहानगरपालिका"
*is* the name and the type, and the English form behaves the same way, so
printing the type beside it says the same word twice. The picker and the
municipality header print it only when the name does not already carry it —
case-folded, because the English labels are sentence case and the names are
title case, so a literal comparison matches in Nepali and silently never
matches in English.

### Docker

**The two images have different build contexts.** The API builds from
`apps/api`; the web builds from the repository root, because `apps/web` is a
pnpm workspace member and the build needs `pnpm-workspace.yaml` and the root
lockfile. Both workflows set `context` per matrix entry.

---

## 9. Where to look next

| Question | Document |
|---|---|
| What is the product for | `00_PROJECT_CONTEXT.md`, `03_SRS.md` |
| Nepal's civic structure, positions, dates | `02_NEPAL_CIVIC_DOMAIN.md` |
| Tables and constraints | `05_DOMAIN_DATA_MODEL.md` |
| Services, deployment, revisit triggers | `06_TECHNICAL_ARCHITECTURE.md` |
| Tenancy, identity, connections, roles | `12_TENANCY_AND_IDENTITY.md` |
| Screens, tokens, copy rules | `09_UX_UI_SPEC.md` (partly out of date) |
| The demonstration dataset | `13_DEMO_DATASET.md` |
| What is running right now | `14_CURRENT_STATE.md` |
| Why something is the way it is | `../DECISIONS.md` |
