# Current state

**As of:** 2026-10-04
**Purpose:** what actually exists and runs today, so work can resume without
rereading the history. Updated whenever the answer to "what is deployed?"
changes.

---

## 1. In one paragraph

Staging is live at `https://hw.jayshyampatel.com.np` with the demonstration
dataset seeded: four invented municipalities, 63 wards, and the four ward states
the product exists to distinguish. The public read path works end to end —
Next.js → REST API → tenant resolution → per-municipality database. The
frontend runs on shadcn/ui primitives mapped onto five switchable palettes.
Person pages, evidence pages, search and the sitemap exist, and `hw:import` can
load a real sheet with an audit trail. There is still no real data, no staff
dashboard, no citizen sign-in, no issue reporting, and no production
environment.

---

## 2. What is running

| | |
|---|---|
| Host | single Oracle A1 server, Docker + Portainer CE |
| Stack | `hamroward-staging` — app, queue, scheduler, redis, web, mailpit |
| Images | `ghcr.io/novaprime-code/hamro-ward-{api,web}:staging` |
| Central database | `hamroward_staging` |
| Tenant databases | four, prefixed `hw_st_` |
| Palette | `himal` via `HW_THEME` |
| Production | does not exist yet |

Branches: `staging` builds `:staging`, `main` builds `:production`. Both
pipelines build only; deployment is a button in Portainer (`D-016`).

---

## 3. What is built

**Foundations** — monorepo, both scaffolds, CI, Docker images, two Portainer
stacks, the shared PostgreSQL image with PostGIS.

**Tenancy** (`HW-E29`) — in-house layer (`D-013`): `TenantManager`,
`CreateTenantDatabase`, `MigrateTenant`, `DropTenantDatabase`,
`SyncTenantReferenceData`, the `tenants` registry, `hw:tenant:create`,
`hw:tenant:migrate`, `hw:tenant:sync-reference`, and `ResolveTenant` middleware
that turns a slug path into a database connection.

**Geography** (`HW-E03`) — `admin_units` for the whole hierarchy with a trigger
enforcing parent level, immutability, the closed-parent rule and `ancestor_ids`;
names, slug paths with history, trigram-indexed aliases, external codes;
`PublishAdminUnit`, `RefreshSlugPaths`.

**Provenance** (`HW-E04-F01`) — source types with authority ranks 1–10 in both
databases, `sources`, `source_links` with `field_path` and `asserted_value`,
`fact_conflicts`, and a trigger keeping tenant-scoped links honest.

**Offices** (`HW-E05-F01`) — the positions catalogue with a trigger keeping seat
counts in step with local-level types; central `persons` and `parties` with
aliases and merges; tenant `office_holdings` with a `btree_gist` exclusion
constraint that makes two simultaneous holders of one seat impossible;
`vacancies`; and `v_current_seats`, which returns `held` / `vacant` /
`not_verified`.

**Demonstration** (`HW-E29-F02`) — `hw:demo:seed` and `hw:demo:drop`, guarded
against production and against name collisions with real local levels.

**Public read path** (`HW-E10-F01`) — `/api/v1/local-levels`,
`/api/v1/local-levels/{province}/{district}/{localLevel}`,
`/api/v1/wards/…/{ward}`, and three pages: picker, municipality, ward.

**Design system** (`HW-E07-F02`) — shadcn/ui primitives (button, input, card,
badge, alert, separator, skeleton) with their tokens mapped onto the five
palettes, plus the civic components: ward plate, provenance badge, seat row,
seat list, state notice, coverage line, municipality picker.

**Card layouts** (`HW-E07-F03`) — every list on the three public screens is
now a shadcn `Card` with real padding rather than hand-rolled markup with
none. `Card` takes `asChild`, so a seat row, a picker row and a ward tile are
each a single anchor with the whole card as the hit target. Seat states have
their own card treatment: a vacancy is a plain card, an unverified seat a
dashed amber border, neither pretending to be a person. The local-level type
is no longer printed beside a name that already contains it.

**Person and evidence pages** (`HW-E05-F02`, `HW-E04-F02`, Phase A) — a seat
row links to the person (scoped to one municipality, `D-020`) and its badge to
an evidence page listing every source by authority, with conflicts shown
(`D-021`).

**Search, sitemap, robots** (Phase B) and the **citizen account schema** —
`users` and `user_wards` with their caps enforced in the database (Phase C,
`D-022`–`D-024`). Authentication itself is not wired (`HW-E30-F01-T01`).

**Importer** (`HW-E06-F02-T01`, Phase D) — `hw:import` loads the nine CSV files
of `05` §13: dry run with a report for the D-002 reviewer, one transaction per
database, row-level errors, idempotent re-runs, verifications by two named
people, people and parties published once verified (`D-025`–`D-027`).
Templates and a field guide in `data/templates/`.

**Audit trail** — append-only `audit_events` in central and in every tenant.
Only the importer writes to it so far.

**Tenant isolation** (`HW-E29-F03-T03`, Phase E) — a suite that runs every
public endpoint, queued jobs and the importer against two municipalities and
fails on any cross-tenant data, and on any new route nobody has classified.
Evidence for places and people is scoped to the municipality in its address
(`D-028`), and a municipality whose schema is behind the code answers 503 on
its own.

**Outbox and central index** (`HW-E29-F03-T02`, Phase F) — every change to a
holding writes a tenant outbox event in the same transaction; a job drains it
into `public_entities`, idempotently. Published paths and the sitemap now list
person pages without opening any tenant (`D-029`). CI on the API is green.

**On-demand revalidation** (`HW-E08-F01-T04`, Phase G) — imports, outbox drains
and deploys send signed cache-tag signals to the web tier's `/api/revalidate`,
so a changed page refreshes on its next request instead of when its timer
runs out.

**Share button** (`HW-E09-F01-T04`, Phase H) — native share sheet where there
is one, copy-link with a spoken "Link copied" where there is not.

**Issue schema** (`HW-E11-F01-T01`, Phase I) — categories (central, replicated),
`issues` and an append-only status timeline in every tenant, and tenant-prefixed
public ids. Nothing writes to them yet.

**Staff authority** (`HW-E13-F01-T01`, Phase J) — staff accounts, per-municipality
memberships and `StaffTenantPolicy`; operator admin as a global role (`D-032`).
No staff login yet.

**Admin host** (`HW-E13-F02-T01`, Phase K) — the staff tree is served only on
`HW_ADMIN_HOST`, with a nonce CSP, `noindex` and `no-store`; `/staff` is a 404
on the public host.

**Sign-in** (`HW-E13-F01-T02`, `T03`, Phases M–N) — Laravel Fortify with
Sanctum cookie sessions, one set of routes for both hosts (`D-034`). Staff:
mandatory TOTP (enrolment forced), recovery codes, lockout, 30-minute idle and
12-hour absolute limits. Citizens: sign-in, password reset, profile. API only;
the screens are `HW-E13-F02-T02` and `HW-E30-F01-T03`.

---

## 4. The demonstration data

Invented municipalities inside real provinces and districts. Nothing here is a
real place, person or party; `docs/13_DEMO_DATASET.md` is the full account.

| Municipality | Type | Path | Wards |
|---|---|---|---|
| हिमतारा महानगरपालिका | metropolitan | `bagmati/kathmandu/himtara` | 25 |
| कोशारा उपमहानगरपालिका | sub-metropolitan | `koshi/sunsari/koshara` | 20 |
| सोनापुर नगरपालिका | municipality | `madhesh/rautahat/sonapur` | 11 |
| साइँली गाउँपालिका | rural municipality | `sudurpashchim/baitadi/sainli` | 7 |

Seats follow ward number mod 4, and this pattern **is** the product argument:

| Ward | What it shows |
|---|---|
| 1, 5, 9… | every seat held, every holding backed by a verified official source |
| 2, 6, 10… | the same, except the reserved Dalit woman seat is *verifiably vacant* — nobody stood |
| 3, 7, 11… | names recorded with no source: every seat reads "not yet verified" |
| 4, 8, 12… | nothing entered at all, which must look different from a vacancy |

Koshara ward 1 additionally carries a source conflict: two sources disagreeing
about the holder's party, both preserved.

Demo path: `/ne` → `/ne/palika/koshi/sunsari/koshara` → wards 1 to 4 in order.

---

## 5. What is not built

Issue reporting beyond its schema, moderation (`HW-E11`, `HW-E14`), citizen sign-in
(`HW-E30-F01`), the staff dashboard and staff accounts (`HW-E13`), media upload,
promises, the election module, the AI assistant, Cloudflare cache purge
alongside revalidation, and a command to publish a place. Also: no
real geography import has been run — the importer exists, the data does not.

---

## 6. Open decisions and blockers

| | |
|---|---|
| **D-006** | the pilot local level is still unchosen. Nothing in code depends on it, and nothing should start to |
| **B7** | every Nepali string in the UI is a draft pending review by a native speaker |
| **Storage** | R2 vs MinIO for staging is undecided. `FILESYSTEM_DISK=local` until the media module exists, so it blocks nothing yet |
| **Turnstile** | keys are empty; needed before citizen reporting opens |
| **Mail** | Mailpit on staging; no transactional provider chosen for production |
| **Licence** | undecided |
| **G1–G6** | operator conditions from `D-003` remain open |

---

## 7. Known debt

* **`docs/09_UX_UI_SPEC.md` is out of date** — it still describes the rejected
  palette and roughly a third of the approved screens. The largest documentation
  gap in the project.
* **`ci-api.yml` has no `docker build` smoke check.** The web workflow already
  runs `pnpm --filter web build`; the API image is only built after merge.
* **Three duplicate components** may still exist in `apps/web/src/components/`
  (`beta-banner`, `site-header`, `site-footer` both flat and under `layout/`),
  plus a possibly-orphaned `api-status.tsx`. The flat copies use the old token
  vocabulary, where `text-muted` now means a background colour.
* **`SeedDemoData` does not recover a tenant stuck in `maintenance`** with no
  database; the row has to be deleted by hand before a re-seed.
* **Provisioner credentials sit on the `app` container**, which both serves
  requests and runs the CLI. `docs/12` §7 wants them away from request-serving
  containers; that separation is partial until provisioning moves to a one-off
  container.

---

## 8. What to do next

The demonstration data is the test data until the owner decides otherwise
(`D-030`), so work continues on v0.2 against it:

1. **Issue reporting backend** (`HW-E11-F01-T02`…`T04`) on the new schema.
2. **Staff sign-in screens and declarations** (`HW-E13-F02-T02`,
   `HW-E13-F01-T04`); the sign-in API exists.
3. **Citizen sign-in** (`HW-E30-F01`), whose cookie and guard behaviour must
   still be checked against real hosts before launch.
4. **Bring `09_UX_UI_SPEC.md` up to date** with the palettes, the shadcn
   component set and the screens as built.

Choosing a pilot municipality (`D-006`) and loading real data
(`HW-E06-F01`, `HW-E06-F02-T02`) wait for the owner's decision.

---

## 9. Recent decisions

`D-013` in-house tenancy · `D-014` persons and parties stay central ·
`D-015` environment-agnostic images · `D-016` two stacks, build-only pipelines,
manual deploy · `D-017` shadcn mapped onto the palettes · `D-020`–`D-021`
person and evidence pages · `D-022`–`D-024` saved wards · `D-025`–`D-027`
the importer · `D-028` evidence scoped to its municipality · `D-029` index
keyed by tenant.

Full text and reasoning in `DECISIONS.md` up to `D-016`; from `D-017` on, under
"Decided" in `CHANGELOG.md`.
