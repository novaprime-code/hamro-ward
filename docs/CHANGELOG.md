# Hamro Ward — Changelog

Project documentation and architecture changes. Newest first.

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

* Production host and domain are not decided. The production workflow tags images and skips the deploy step until `PORTAINER_WEBHOOK_PRODUCTION` is set.

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
