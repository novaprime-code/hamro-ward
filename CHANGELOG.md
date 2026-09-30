# Hamro Ward — Changelog

Project documentation and architecture changes. Newest first.

## 2026-09-26 (frontend)

### Decided

* **D-017:** shadcn/ui adopted, with its token names mapped onto the five palettes inside `@theme inline` rather than defined as colours. `tokens.css` remains the only file in the application containing a colour value, and an unmodified registry component renders correctly in all five palettes with no per-palette CSS. Full entry in `claude/D-017-shadcn.md`, to be pasted into `DECISIONS.md`.

### Code

* `components.json`, `src/lib/utils.ts` and seven primitives in `src/components/ui/` (button, input, card, badge, alert, separator, skeleton). `shadcn init` is never run — it would overwrite `globals.css` and flatten the palette mapping.
* `@custom-variant dark (&:is([data-theme="raat"] *))` binds shadcn's dark variant to the existing palette mechanism; no `next-themes`, no `.dark` class that can disagree with `data-theme`.
* Badge gains `verified`, `unverified`, `neutral` and `ai` variants; Alert gains `unverified` and `paused`. The registry has no vocabulary for what this product reports.
* The seven civic components and three layout components rebuilt on those primitives. Ward tiles are now `buttonVariants` on a real `<Link>`.
* `tokens.css` gains `--on-danger` per palette — `raat`'s is dark, because its danger colour is a light red.
* Token vocabulary swept across all pages: `text-muted` → `text-muted-foreground`, `border-line` → `border-border`, `bg-surface-2` → `bg-card`, `text-accent-ink` → `text-accent-foreground`.

### Documentation

* **`claude/14_CURRENT_STATE.md`** — new, and the document to read first when resuming work: what is deployed, what is not built, open decisions, known debt, what to do next.
* **`claude/DEVELOPMENT.md`** — local setup, code layout, conventions, and a "traps" section recording every failure mode this codebase has already produced.
* **`claude/SETUP.md`** — server setup end to end, with a troubleshooting table of the exact errors hit during the staging build.
* **`claude/REPO_README.md`** — the repository root README.
* `README.md` (documentation index) updated; `09_UX_UI_SPEC.md` marked out of date.

### Note

* `text-muted` now means a **background** colour. Any file still using it for text renders nearly invisible text — relevant to the duplicate components still possibly present in `apps/web/src/components/`.

## 2026-09-26 (deployment)

### Code

* `.github/workflows/image-{staging,production}.yml`: per-image build context. `apps/api` for the API, whose `COPY` paths are relative to that directory; the repository root for the web, which needs `pnpm-workspace.yaml` and the root lockfile.
* `infra/postgres/create-central-database.sh`: creates one central database per environment, copied from `template_hamroward` so it inherits PostGIS, `pg_trgm`, `btree_gist`, `citext` and the `hw_app` default privileges. A database-level ACL is **not** copied from a template, so the script also revokes `PUBLIC` and grants `CONNECT` explicitly — without it a new central database is readable by every role on a server shared with n8n.
* `90-hamroward-migrations.sh`: a failed central migration now prints the cause and the remedy after the stack trace.
* The web half of `HW-E07-F01` shipped in full after an image build found five of its modules missing from the repository.
* `persons` migration: the self-referencing foreign key moved out of `Schema::create`. Laravel appends fluent-index commands — including the primary key — after everything the closure added, so the key was emitted before the column it references and PostgreSQL rejected it.
* `Person` declares `protected $table = 'persons'`; Laravel's pluraliser produces `people`.
* `TenantAdminUnit` declares `$incrementing = false` and `$keyType = 'string'`. Without them Eloquent casts the uuid primary key to `int`, and `81014cad-…` reads back as `81014`.
* `SeedDemoTenant` writes every row with `forceFill` through one `put()` helper. `updateOrCreate` runs attributes through `fill()`, which silently drops anything outside `$fillable` — including `id` — so rows were created under generated identifiers and a later foreign key pointed at nothing.
* `SeedDemoGeography` calls `PublishAdminUnit::publish()`, not `handle()`, and writes with `forceFill` so mass assignment cannot drop names.
* Public API routes moved into `routes/api_v1.php`. `routes/api.php` was never loaded — `bootstrap/app.php` registers the former.
* `MunicipalityPicker` receives a `typeLabels` record instead of a function. Props crossing into a client component are serialised; a function fails the whole render.
* Provisioner credentials (`TENANT_PROVISIONER_*`) added to both stacks, on the `app` service only.

### Note

* `create-hamroward-db.sh` (Phase 1–2) runs once and creates **one** central database, `hamroward`. Each further environment needs `create-central-database.sh` before its stack is deployed.
* Tenant provisioning needs a role with `CREATEDB` — `hw_provisioner`, not `hw_owner`.

## 2026-09-25 (later)

### Decided

* **D-016:** two literal stack files, one build per branch, deployment by hand. Supersedes D-015 items 4 and 5. Portainer stack webhooks are a Business Edition feature — greyed out on this install — and the Community webhook has silently no-opped since 2.39.7, which would have produced green deploys against an unchanged server.

### Code

* `image-staging.yml` and `image-production.yml` reduced to build-and-tag. Each prints the image reference to deploy and the pinned `sha-` tag for rollback in the job summary.
* `infra/stacks/hamroward-staging/` and `infra/stacks/hamroward-production/`, each a literal compose file plus a fully commented `.env.example`. Replaces the single `HW_STACK`-parameterised file.

### Unchanged

* Environment-agnostic images and the `HW_SITE_URL` runtime read (D-015 items 1–2) stand, and are what make a `sha-` tag a valid rollback target.
* `HW_MIGRATE_ON_BOOT` stays true on staging, false on production.

## 2026-09-25

### Decided

* **D-015:** build once, promote the artifact. `staging` branch deploys staging, `main` deploys production, and `main` re-tags the already-built image when it can rather than building a second one from the same source.

### Code

* Delivery pipeline split into `image-staging.yml` and `image-production.yml`, replacing `image-api.yml` and `image-web.yml`. The production workflow promotes an existing `sha-<commit>` image without rebuilding, and marks the summary when it cannot.
* `apps/web/Dockerfile` no longer takes `NEXT_PUBLIC_SITE_URL` or `NEXT_PUBLIC_ADMIN_HOST`; the image is environment-agnostic and the build fails if an environment hostname reaches the browser bundle. `API_INTERNAL_URL` now names the Compose service rather than a container.
* `apps/web/src/lib/site.ts` reads `HW_SITE_URL` at request time.
* `docker-compose.yml` parameterised by `HW_STACK`, so staging and production can share a host without colliding on container, volume or project names.
* `90-hamroward-migrations.sh` gated behind `HW_MIGRATE_ON_BOOT` and extended with `hw:tenant:sync-reference`.

### Open

* Production host and domain are not decided.

## 2026-09-29

### Decided

* **D-014:** persons and parties stay central; `office_holdings` references them by id across databases, validated on write and batched on read. Resolves the contradiction between `05` §5.4 (foreign keys) and `05` §12 (different databases).

### Code

* `HW-E05-F01-T01`: positions catalogue. Central `positions` with a trigger that keeps `seats_per_constituency` in step with `applies_to_local_level_types`, seeded with all ten local-level positions — the seven a voter fills directly, under both urban and rural names, plus the two assembly-elected executive member categories.
* `HW-E05-F01-T02`: persons, parties, office holdings, vacancies and `v_current_seats`. Central `persons`, `person_aliases`, `person_merges`, `parties`, `party_aliases`; tenant `office_holdings` with a `btree_gist` exclusion constraint that makes two simultaneous holders of one seat impossible, `vacancies` with the mirror constraint and a cross-table check, and the derived seat view returning `held` / `vacant` / `not_verified`.
* `HW-E29-F02-T02`: tenant reference replicas. `SyncTenantReferenceData` now copies source types, the positions catalogue and each tenant's geography subtree (its local level, every ancestor, every ward) in one transaction, prunes what central no longer has, and bumps `reference_version`. Adds the tenant `admin_units` replica, `ward_offices`, and the `hw:tenant:sync-reference` command.

### Changed

* `05` §5.4: `person_id` and `party_id` documented as central references rather than foreign keys, per D-014.
* `05` §5.1: the catalogue key is the primary key, so a materially redefined position takes a new key rather than a second row; `valid_to` retires a position instead.
* `tests/Pest.php` gains `expectRejectedByTenantDatabase`, `tenantWithPublishedWards` and `tenantSeatContext`.

### Open

* Nepali position titles in `PositionSeeder` are drafts pending native review (blocker B7).
* `bs_calendar_months` and `issue_categories` replicas join `SyncTenantReferenceData` when their central tables arrive.

## 2026-09-26

### Code

* `HW-E04-F01-T01`: provenance schema in both databases. Adds `source_types` (seeded, ranks 1–10) with a read-only tenant replica, `sources`, `source_links` (with `source_scope` and a trigger for local sources in tenants), `fact_conflicts` and `fact_conflict_links`; central/tenant model pairs on shared abstract bases; the `HasSourceLinks` trait, now on `AdminUnit`; a morph map of short subject keys; and the first slice of `SyncTenantReferenceData`.

### Changed

* `05` §4: `source_scope`, the per-database `subject_type` lists, and the stricter verified-link check documented.
* Shared test helpers (`expectRejectedByDatabase`, `withTenantDatabase`) moved into `tests/Pest.php`; the architecture test now allows abstract model bases.

## 2026-09-24

### Code

* `HW-E03-F01-T01` + `T02`: administrative hierarchy. Adds `admin_units` with a hierarchy trigger (parent level, immutable level/parent, closed-parent rule, `ancestor_ids`), one current country, the ward slug = number rule, names, slug paths, trigram-indexed aliases, external codes and lineage. Also the `PublishAdminUnit` and `RefreshSlugPaths` actions, and the tenants → local level foreign key.

### Changed

* `05` §3: `ancestor_ids`, immutability and publication rules documented; `ward_offices` moved to the tenant database (arrives with the tenant geography replica, `HW-E29-F02-T02`); `admin_unit_names.source_link_id` dropped in favour of central `source_links`.
* `12` §3: `source_links` / `fact_conflicts` exist in **both** databases — central for central subjects (geography, persons, parties), tenant for tenant subjects. The earlier "all source_links are tenant" row could not provide provenance for central facts.

## 2026-09-22

### Decided

* **D-013:** tenancy spike outcome. A thin in-house tenancy layer is used instead of `stancl/tenancy`. Tenant keys are random 8-hex values, and PostgreSQL extensions are installed in `template1`.

### Changed

* `12` v0.2: the tenancy implementation row, tenant key naming, and spikes S1/S3 marked resolved.
* `08`: blocker B6 resolved.

### Code

* `HW-E29-F01-T01` + `HW-E29-F01-T02`: tenancy foundation. This adds the Tenancy module, central and tenant migration streams, the `tenants` registry, the tenant `settings` table, the `hw:tenant:migrate` command, queue tenancy, tests, the updated Postgres init script (test database, provisioner membership, template extensions), and the CI role setup.

## 2026-09-17

### Added

* `03_SRS.md` v0.1 — functional and non-functional requirements with IDs, priorities, target versions, traceability to PRD acceptance criteria.
* `05_DOMAIN_DATA_MODEL.md` v0.1 — PostgreSQL/PostGIS model for geography, provenance, offices, issues, media, moderation, staff, audit; import format.
* `06_TECHNICAL_ARCHITECTURE.md` v0.1 — **replaces** the architecture prompt previously stored under that filename.
* `08_ROADMAP_AND_DELIVERY_PLAN.md` v0.1 — versions, weeks, days, capacity scenarios, election-critical cuts, tracking setup.
* `09_UX_UI_SPEC.md` v0.1 — design direction, tokens, IA, screens, components, copy rules.
* `tools/backlog/` — backlog builder (28 epics, 84 features, 87 tasks), generated `BACKLOG.md`, `backlog.json`, `jira_import.csv`, and `github_import.py`.
* `docs/README.md` — document index.

### Changed

* `01` v0.4 — §9 launch plan superseded by `08`.
* Document numbering: 03 is now the SRS; legal context is folded into `04_EDITORIAL_AND_LEGAL.md` (planned).
* All project documents moved under `docs/`.

### Decided: configurable reporting scope (D-012)

* Citizens can report in any published ward by default; operator admins can limit a local level or the whole platform to saved wards only, with a cooldown for newly saved wards.
* Updated `12` §12.1–12.4, `03` v0.4 (FR-ACC-05, new FR-ACC-09), `05` settings keys, `09` report flow and staff settings, `08` v0.4. Backlog: tasks `HW-E30-F02-T03`, `HW-E30-F02-T04` (106 tasks).

### Decided: tenancy and citizen accounts (D-010, D-011)

* New `12_TENANCY_AND_IDENTITY.md`: tenant per local level with a separate database; central database; citizen accounts with saved wards (permanent/temporary address); Fortify + Sanctum cookie auth, same-origin API; tenant-scoped staff memberships.
* **Changed earlier decisions:** "no citizen accounts before v2.0" (old `03` NFR-PRV-01, `06` §11) and the single-database model (D-007 items 4–5) are replaced. Reason: product owner decision for isolation per local level and accountable, multi-ward reporting.
* `03` v0.3 (FR-TEN, FR-ACC, updated FR-ISS/STF and privacy), `05` v0.2 (central/tenant placement, reporter fields, memberships, migration streams), `06` v0.2 (auth, API, deploy, backups, diagram), `09` v0.2 (account screens, report flow, staff tenant switcher), `01` v0.6.
* Backlog: epics HW-E29 Multi-Tenancy and HW-E30 Citizen Accounts; 104 tasks. Dates: v0.1 Tue 3 Nov 2026, v0.2 Tue 8 Dec 2026, v1.0 Tue 9 Mar 2027. `08` v0.3 rewritten.

### Replanned (normal pace, D-009)

* Backlog switched to normal-pace, festival-aware dates: v0.1 Tue 13 Oct 2026, v0.2 Tue 17 Nov 2026, v1.0 Tue 23 Feb 2027.
* AI assistant and OCR moved from v0.7 to v1.2 (proposed). `03`, `05`, `06` version references updated.
* Corrections form moved to v0.3; slug redirects to v0.4; several tasks reassigned to balance Nova's load.
* Pilot decision deadline is now Fri 25 Sep 2026.
* `08` v0.2 rewritten; sprint scenario removed.

### Proposed (awaiting approval)

* D-007 — architecture baseline.
* D-008 — planning and tracking method.

### Known gaps

* `04_EDITORIAL_AND_LEGAL.md`, `07_DATA_SOURCES.md`, `10_AI_EVALUATION.md`, `11_THREAT_MODEL.md` not yet written.
* Dashain (11–25 Oct) and Tihar (8–11 Nov) dates checked against published 2026 calendars; TU Dresden exam dates and Chhath still need verification.
* Nepali copy in `09` needs native review.

## 2026-09-15

### Added

* `02_NEPAL_CIVIC_DOMAIN.md` v0.1:
  * administrative hierarchy
  * local government structure
  * local election positions
  * Itahari pilot facts, with source conflicts
  * date, number and language rules
  * terminology glossary
  * domain rules R1–R16
  * verification backlog
* `01_CONSTRAINTS_AND_TEAM.md` v0.1, then v0.2
* `DECISIONS.md` with entries D-001 to D-005
* `CHANGELOG.md`

### Decided

* **D-001:** Next.js + Laravel confirmed. The single Laravel + Filament proposal was rejected.
* **D-002:** Volunteer affiliation rules.
* **D-003:** A friend's company acts as operator.

### Reopened

* **D-006:** Pilot local level is open; Nova will choose. Decision needed by Thu 17 Sep for the 22 Sep launch. `01` updated to v0.3.

### Proposed (awaiting approval)

* **D-004:** Two-milestone v0 launch — 22 Sep (read-only) and 29 Sep (participation).
* **D-005:** Infrastructure simplifications P3–P8.

### Known gaps

* `06_TECHNICAL_ARCHITECTURE.md` is still a prompt, not an architecture. Generate it after D-004 and D-005 are resolved.
* `05_DOMAIN_DATA_MODEL.md` is not yet written. It must implement `02` rules R1–R16 and the D-002 data requirements.
