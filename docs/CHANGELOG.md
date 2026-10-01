# Hamro Ward — Changelog

Project documentation and architecture changes. Newest first.

## 2026-10-01 — Phase C: citizen account schema and saved wards

The durable half of `HW-E30`. Not the authentication wiring — see "Not in this
batch", which is the more important half of this entry.

### Decided

* **D-022: the saved-ward caps are database constraints, not only action
  checks.** `12` §12.1 assigns the five-ward cap and the three-per-30-days cap
  to the action layer. They are in a trigger as well, because a cap that lives
  in one action is a cap the next code path does not have — an import, an admin
  tool, a fixture, a future bulk edit. The action stays, because a
  `QueryException` reaching a citizen as "something went wrong" is a cap that
  works attached to an interface that cannot explain itself. The trigger makes
  the rule true of the data; the action makes the refusal legible.
  * The row count in a `BEFORE INSERT` trigger is not serialisable: two
    concurrent inserts can each see four rows and proceed. Losing that race
    needs two requests in the same millisecond from one account and costs one
    extra saved ward. Noted in the migration rather than solved with row locks.
* **D-023: removing the primary ward promotes nothing.** The account is left
  with no primary. Promoting the next row would be the platform deciding where
  somebody is from, silently, on the strength of row order — and an account
  works perfectly well without a primary until its owner says.
* **D-024: only a lived-in ward can be primary.** `permanent_address` and
  `temporary_address` can; `workplace` and `other` cannot. A workplace is
  somewhere you have standing to report on, not where you are from, and
  defaulting someone's home ward to their office is wrong in a way they would
  have to notice in order to correct it.

### Code

* `users` (central): uuid key, `citext` email so one address is one account
  whatever the capitalisation, display name 2–60, locale, status
  (`active` / `locked` / `deleted_pending`), two-factor columns, and
  `password_reset_tokens` keyed by email — the request arrives before anyone is
  identified, and the response has to look the same whether or not the address
  exists (`12` §11.3).
* `user_wards` (central): relationship enum, `is_primary` with a partial unique
  index, `UNIQUE (user_id, ward_id, relationship)` so living and working in one
  ward is two legitimate rows, and a trigger enforcing that the unit is a
  **current ward** plus both caps.
  * **No published requirement, deliberately** (`12` §12.2). A ward can be saved
    before Hamro Ward opens in its municipality, and starts working on its own
    when the tenant goes live. Refusing would tell a citizen their ward does not
    exist, when the truth is that we have not got there yet.
  * A ward closed by a later restructure keeps the rows already saved against
    it; only new saves are refused. Removing them would quietly change what a
    citizen told us about themselves.
  * `created_at` is load-bearing, not bookkeeping: it is what the
    `saved_wards_only` cooldown is measured from (`12` §12.4 rule 1).
* `User` is deliberately **not** a `Person`. A Person holds or stood for office
  — public, published, sourced. A User is a member of the public who signed up.
  Separate tables with no link is what stops the platform ever quietly
  asserting that a given account belongs to a given councillor.
* **What the schema refuses to hold**, and must not grow: street, tole, house
  number, GPS home location, citizenship or national ID number, date of birth,
  phone. A ward is precise enough to route a report; anything finer turns a
  civic platform into an address registry of people who complained about their
  local government — a different and far more dangerous object, and one that a
  change of operator, a subpoena or a breach hands to somebody else.
* **Removed** Laravel's default `App\Models\User` and its factory stub, which
  had been left behind when the default users migration was dropped. They
  carried a `name` column that does not exist and sat outside
  `app/Modules/*/Models`, where the architecture test cannot see them.
  `config/auth.php` now points at the module model.

### Not in this batch

**None of the authentication.** `HW-E30-F01-T01` is a spike in the project's own
plan: running Fortify for two guards through runtime config switching, with a
documented fallback if it does not work (`12` §11.2). Fortify and Sanctum are
not installed, so `auth:sanctum` does not exist and `/api/v1/me` has no
middleware to sit behind.

That half is config — session cookie names, guard and broker switching per
host, `SANCTUM_STATEFUL_DOMAINS`, cookie scope. It fails in ways no syntax check
catches, and the failures are the kind that matter: a citizen session accepted
on the staff host, or a cookie scoped wide enough to travel. It needs someone
who can run it, which is why the spike exists. It should not be written blind
and handed over looking finished.

The schema is the part that is expensive to change once there is live data in
it, which is why it is the part that went first.

## 2026-10-01 — Phase A: the read path leads somewhere

Two dead ends on the ward page, closed. A held seat linked to an anchor on the
page the reader was already on, and the provenance badge said "official source"
without saying which one. Both were truthful placeholders; neither survives
contact with a reader who wants to check.

### Decided

* **D-020: the person page is scoped to one municipality.** Holdings live in
  per-municipality databases and PostgreSQL will not join across them, so a
  career spanning several needs a central index that does not exist yet
  (D-014). A reader always arrives from a ward, so the municipality is known.
  The page says plainly that it shows only this municipality and only current
  terms — a page that silently showed part of a career while looking complete
  would be making exactly the kind of unstated claim this platform exists to
  avoid.
* **D-021: evidence is a page, not a sheet.** `ProvenanceBadge` had reserved an
  `onOpen` hook for a dialog since `HW-E07-F03`. A page wins on every axis that
  matters here: it has an address a reader can share and a search engine can
  index (§18), it server-renders without JavaScript, and on a phone a full page
  beats a sheet anyway (§17). The `onOpen` prop stays for now but nothing uses
  it.

### Code — api

* `HW-E05-F02-T02`: `GET /api/v1/persons/{province}/{district}/{local_level}/{person}`.
  Returns the person, the seats they hold in this municipality, and the address
  of the evidence for the person record itself — which is a separate claim from
  evidence that they hold a seat, and kept separate so a verified holding cannot
  imply a verified identity. A published person who holds nothing here is a 404,
  not an empty page.
* `HW-E04-F02-T01`: `GET /api/v1/evidence/{province}/{district}/{local_level}/{subject_type}/{subject_id}`,
  and `EvidenceForSubject` behind it. Three things a naive "select source_links
  where subject" would not do:
  * **Refuses subjects not on an allowlist, and checks each one is public.**
    subject_type and subject_id arrive from a URL; without this the endpoint
    reads evidence for unpublished wards and unpublished people straight back
    out of the database, one guessed uuid at a time. The allowlist is stated
    twice — a route constraint and a const in the query — so a subject added to
    one and forgotten in the other fails closed.
  * **Resolves both scopes.** A tenant link points at a local source in the
    tenant database or a national one in central (D-014, `12` §4.1), and the two
    cannot be joined. Two lookups, not one per link.
  * **Groups by field and surfaces disagreement.** Conflict is defined narrowly
    as two or more DISTINCT asserted values for one field: corroboration dressed
    up as a dispute is its own way of misleading a reader.
* An unknown source type sorts LAST (rank 99), so a row missing from a tenant's
  replica cannot quietly outrank the Election Commission.
* An empty result is a 200, not a 404. "This record exists and nothing backs it
  yet" is the same answer the seat list already gives as `not_verified`.
* `SeatRow` carries `officeHoldingId` and `vacancyId`; `SeatResource` exposes
  them as `evidence`. The row previously carried the state a source implies but
  not the id of the thing the source is about, which is why the badge had
  nowhere to go.

### Code — web

* `/[locale]/person/…/[slug]` and `/[locale]/source/…/[subjectType]/[subjectId]`.
* The person page has no biography, no photo gallery, no achievements. The
  Person record holds no gender, caste, ethnicity, religion, date of birth or
  address, so there is nothing to render — and a page that grew those fields
  would become a profile, which invites judgement of the person rather than
  scrutiny of the record (§2).
* `SeatRow` is no longer one link wrapping everything. The row now has two
  destinations — the name goes to the person, the badge to the sources — and an
  anchor inside an anchor is invalid HTML that browsers recover from by dropping
  the inner one, which would have been precisely the badge's link.
* `SeatList` takes `localLevelPath` instead of `basePath` and builds both links
  itself, so they cannot drift out of step and send a reader to one
  municipality's person and another's sources.
* `SourceCard` orders its fields as the argument runs: source type first,
  because that is how a reader should weigh everything under it. Two dates, not
  one — `published_at` is when the document said it, `retrieved_at` is when we
  looked, and the gap is the window in which a page could have changed under us
  (§13).
* A field with no label renders "About one detail" rather than
  `evidence.field.some_column`. The translator returns the key when it has no
  entry, and a database column name on screen is not legible to a citizen.
* `messages.test.ts`: the two catalogues must carry the same keys and the same
  placeholders. `getMessages` merges Nepali underneath English, so a key missing
  from English silently renders Nepali — visible only to whoever is reading in
  the language nobody on the team is checking, which here is the language most
  readers use.

### Still open

* Term history and cross-municipality careers both need a central index.
* Recorded `fact_conflicts` rows are not read yet; conflict is derived from the
  links, which is what the demonstration dataset actually populates. Joining the
  table arrives with the verification workflow (`HW-E17`).

## 2026-09-30 — launch-visible gaps

Three things a visitor or a crawler meets before they meet anything the schema
guarantees. None of them touched the domain model.

### Decided

* **D-018: trusted proxies are named, not `*`.** `bootstrap/app.php` trusted
  every caller's `X-Forwarded-*` headers. That is harmless only while nothing
  reads the client address — and rate limiting reads it, the audit log will read
  it, and issue reports will record it against a citizen's submission (`12`
  §12). Under `*`, each of those values is chosen by whoever it identifies. The
  trusted set is now the private ranges the container network is allocated from
  (`TRUSTED_PROXIES`), Cloudflare's ranges are deliberately absent while it is
  DNS-only, and `AWS_ELB` is out of the header set because no load balancer
  exists in this deployment.
* **D-019: the per-visitor rate limit belongs in the web tier, not in Laravel.**
  The browser never reaches Laravel — server components fetch the API over the
  private network — so every request Laravel sees comes from one address. An
  IP-keyed limiter there would put every visitor into a single bucket and the
  first busy minute would take the site down for all of them. Laravel keeps two
  ceilings keyed by address (a public backstop, and a much higher runaway-loop
  ceiling for the web tier); the per-visitor limit sits in the Next middleware,
  which is the last layer that can still tell visitors apart. Deliberately not
  forwarding the visitor's address to the API instead: reading request headers
  in a server component makes the route dynamic and would switch off the ISR
  caching that shields the API from nearly all of this traffic.

### Code — web

* `not-found.tsx` and `[...rest]/page.tsx`. Every `notFound()` in the
  application, plus the three footer links to pages that did not exist,
  previously fell through to the framework's built-in 404 — unstyled, outside
  the layout, in English. A `not-found.tsx` inside a segment only renders for
  `notFound()` raised *within* that segment, so the catch-all is what puts an
  unmatched address inside the segment in the first place.
* **`not-found.tsx` must stay a server component.** A `'use client'` version
  type-checks, builds, returns the right status code, and server-renders
  nothing: the copy appears only after hydration, which on a slow Android
  connection is a blank page and to a crawler is a blank page permanently. The
  locale therefore arrives on the `x-hw-locale` request header, set by the
  middleware, because Next never passes route params to `not-found.tsx`.
* `error.tsx` for the segment. Next renders error boundaries on the client by
  design, so the status is server-side and the copy appears after hydration.
* `about`, `sources` and `privacy`. Sources is written in full — the hierarchy,
  the three seat states and the conflict rule are the platform's own policy and
  already implemented. About and Privacy render an honest "not published yet"
  state until `HW_OPERATOR_NAME`, `HW_OPERATOR_REGISTRATION` and
  `HW_CORRECTIONS_EMAIL` are set: a company name with no registration number to
  check it against is exactly the unverifiable claim this site tells readers not
  to accept (D-003 G1–G2, G5).
* Share cards (`opengraph-image`) for ward, municipality and locale root, with
  Noto Sans Devanagari subsets vendored under `public/og/fonts` (SIL OFL 1.1).
  Satori has no access to `next/font`, and without the bytes every Devanagari
  glyph renders as an empty box — a failure only the people the link was shared
  with would ever see. The card carries the coverage line and nothing else
  quantitative: it is the easiest surface on which to start optimising for
  outrage (§7, §18).
  * The Devanagari and Latin subsets are separate Satori font families. Satori
    keys a font by family, weight and style, so registering both as one family
    silently shadowed the Latin subset and every comma, hyphen and slash — the
    `7/9` in the coverage badge included — rendered as a box.
  * `lib/share-metadata.ts`, because a page's `openGraph` object **replaces**
    the layout's rather than merging: the ward page was losing `og:site_name`
    and `og:locale`, and dropping to `twitter:card=summary`, which is the small
    square preview rather than the wide card the 1200×630 image is drawn for.
  * `lib/og/palette.ts` is the second and only other file holding a colour
    value, because Satori resolves no custom properties. A test parses
    `tokens.css` and fails if the two ever disagree.
* `middleware.ts` enforces a per-address ceiling (`HW_RATE_LIMIT_PER_MINUTE`,
  default 120/min) before routing. The address is the **last** entry of
  `X-Forwarded-For` — the one the proxy observed. Everything before it is
  supplied by the caller, so a limiter keyed on the first entry is defeated by
  one forged header.
* `vitest.config.ts` declares an empty PostCSS plugin list. Vite was finding
  `postcss.config.mjs`, failing to load the Tailwind 4 plugin, and killing the
  whole run in an unhandled rejection before a single test was collected — so
  `pnpm --filter web test` had not actually been running any tests.

### Code — api

* `config/security.php`, `SecurityServiceProvider` (the `public-read` limiter),
  and `throttle:public-read` on every public route. `/api/v1/health` is
  deliberately exempt: a monitor that gets a 429 reports an outage that is not
  happening, and an orchestrator that gets one restarts a healthy container.
* `TrustedProxies` and `InternalClients` in `Modules\Support`, both tested.
* **Fixed:** `HealthController::detailAllowed()` admitted any address starting
  `172.` — that is 172.0.0.0/8, mostly public space, including ranges Google
  routes on. The private block is 172.16.0.0/12, which a dotted-string prefix
  cannot express. It now uses the same range matcher as the limiter.

### Not done

* `sitemap.ts` and `robots.ts` still do not exist; the middleware matcher
  already reserves both paths.
* RFC 9457 `problem+json` is still a comment in `bootstrap/app.php`, while `06`
  describes it as the API's error format.
* `privacy` must be **rewritten, not extended**, before v0.2 opens citizen
  accounts and issue reporting.


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
