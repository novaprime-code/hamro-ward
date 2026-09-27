# Current state

**As of:** 2026-09-27
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
There is no real data, no staff dashboard, no citizen accounts, no issue
reporting, and no production environment.

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

Issue reporting and moderation (`HW-E11`, `HW-E14`), citizen accounts
(`HW-E30`), the staff dashboard, media upload, person pages (`HW-E05-F02`), the
source sheet (`HW-E04-F02`), the CSV importer (`HW-E06-F02`), promises, the
election module, search, the sitemap, the AI assistant. Also: no real geography
import, and no About / Sources / Privacy pages — the footer links to three
routes that do not exist.

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
* **`ci-web.yml` runs tests but not a build.** Adding `pnpm --filter web build`
  would have caught four separate failures before they reached a Docker build.
  Same for a `docker build` smoke check in `ci-api.yml`.
* **Three duplicate components** may still exist in `apps/web/src/components/`
  (`beta-banner`, `site-header`, `site-footer` both flat and under `layout/`),
  plus a possibly-orphaned `api-status.tsx`. The flat copies use the old token
  vocabulary, where `text-muted` now means a background colour.
* **`SeedDemoData` does not recover a tenant stuck in `maintenance`** with no
  database; the row has to be deleted by hand before a re-seed.
* **Held seats link to an anchor on the same page** because person pages do not
  exist yet, and the source badge says "official source" without linking to the
  source because the source screens do not exist yet. Both are truthful and
  render correctly; neither is a placeholder pretending to be finished.
* **Provisioner credentials sit on the `app` container**, which both serves
  requests and runs the CLI. `docs/12` §7 wants them away from request-serving
  containers; that separation is partial until provisioning moves to a one-off
  container.

---

## 8. What to do next

In the order that unblocks the most:

1. **Choose the pilot municipality** (`D-006`). It gates real data collection
   and has been open since 15 September.
2. **`pnpm --filter web build` into `ci-web.yml`.** Ten minutes, pays for itself
   on the next push.
3. **The CSV importer** (`HW-E06-F02-T01`) — the bridge from demonstration to
   real data, and the point at which provenance stops being theoretical.
4. **Person pages** (`HW-E05-F02`), so seat rows lead somewhere.
5. **The source sheet** (`HW-E04-F02`), so the provenance badge becomes
   clickable and the conflict on Koshara ward 1 can actually be read.
6. **Bring `09_UX_UI_SPEC.md` up to date** with the palettes, the shadcn
   component set and the screens as built.

---

## 9. Recent decisions

`D-013` in-house tenancy · `D-014` persons and parties stay central ·
`D-015` environment-agnostic images · `D-016` two stacks, build-only pipelines,
manual deploy · `D-017` shadcn mapped onto the palettes.

Full text and reasoning in `DECISIONS.md`.
