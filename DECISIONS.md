# Hamro Ward — Decision Log

Architectural and product decisions, newest last.

* When a decision changes, the old entry is kept and marked **Superseded by D-xxx**, per project instructions §23.
* **Statuses:** Proposed · Accepted · Superseded · Rejected

---

## D-001 — Frontend and backend stack

* **Date:** 2026-09-15
* **Status:** Accepted
* **Decided by:** Nova

### Context

The project instructions (§10) specify Next.js + Laravel. Under the tight launch timeline and a budget below €20/month, a single Laravel + Filament application was proposed as a simpler alternative (`01` v0.1, P1).

### Decision

Use **Next.js (TypeScript, Tailwind) for the frontend** and **Laravel for the backend**, as in §10. Filament is not used.

### Consequences

* **Versioned REST API (`/api/v1`) from day one**, documented with OpenAPI and consumed through a typed client.
* **Business logic lives in Laravel domain actions/services, not controllers.**
* **The staff/moderation dashboard is built in Next.js** on a separate subdomain, using Sanctum cookie authentication plus Fortify TOTP 2FA.
* **Public pages are static or ISR, cached at the edge**, which suits the expected traffic spikes.
* **Estimated +30–50% build effort** vs a single app. This is absorbed by splitting v0 into two milestones (`01` §3) and using CSV import instead of an admin CRUD UI for Milestone A.

### Alternatives considered

* **Single Laravel + Filament app — Rejected** (by Nova, 2026-09-15).

---

## D-002 — Volunteer roles and political affiliation

* **Date:** 2026-09-15
* **Status:** Accepted

### Context

Student volunteers, including politically active students, are expected to help. Many student organizations in Nepal are party-affiliated. If affiliated people are visibly controlling verification or moderation, the platform's neutrality (instructions §2) is at risk.

### Decision

* **Volunteers privately declare any political affiliation.**
* **Anyone may do outreach, document collection, translation and field checks.**
* **Verification and moderation** go to unaffiliated people where possible. Otherwise, actions by an affiliated person need cross-affiliation confirmation.
* **No one moderates or verifies content about their own party, candidates or relatives.**
* **All actions are audit-logged under personal accounts.**

Full rules: `01` §8.2.

### Consequences

* The data model needs `staff_affiliation_declaration` (private) and a second-approver workflow for restricted actions.
* The audit log is required from Milestone B.
* The moderation guidelines in `04_EDITORIAL_POLICY.md` must reference these rules.

---

## D-003 — Platform operator

* **Date:** 2026-09-15
* **Status:** Accepted (conditions G1–G6 open)

### Context

The platform needs a legally responsible organization for credibility, funding, defamation exposure and data protection. Development happens partly from Germany, and the users are in Nepal.

### Decision

**A friend's company acts as the platform operator.**

### Consequences

* The operator is named publicly on the About page, with a conflict-of-interest statement.
* The following must be settled before Milestone A:
  * Nepal registration (G1)
  * conflict-of-interest disclosure (G2)
  * a written agreement covering editorial independence, code/data ownership, continuity and funding transparency (G3)
  * operator-owned infrastructure accounts (G4)
  * legal questions (G5)
  * limits on developer access to personal data (G6)
* See `01` §7.

---

## D-004 — v0 two-release launch

* **Date:** 2026-09-15, revised 2026-09-17
* **Status:** Proposed

### Decision

* **v0.1 "Ward Pages":** read-only ward and representative pages.
* **v0.2 "Report an Issue":** issue reporting and moderation.
* **Candidate features wait for v0.6/v1.0**, because no 2027 candidates exist yet.

### Revision (2026-09-17)

* **Old dates:** Milestone A Tue 22 Sep, Milestone B Tue 29 Sep.
* **New dates (normal pace, D-009):** v0.1 **Tue 13 Oct 2026**; v0.2 **Tue 17 Nov 2026**.
* **Why:** work starts 17 Sep; the pilot is not chosen; task-level estimates are 108 h (v0.1) and 61 h (v0.2); the team works ~15 h/person/week; Dashain (11–25 Oct) and Tihar (8–11 Nov) reduce capacity.

Details: `01` §3–4, `08` §1–4.

---

## D-005 — Infrastructure simplifications for v0/v1

* **Date:** 2026-09-15
* **Status:** Proposed

### Decision

| # | Simplification |
|---|---|
| P3 | PostgreSQL full-text search + `pg_trgm` instead of Meilisearch |
| P4 | Database queue instead of Redis |
| P5 | No Reverb |
| P6 | Cloudflare R2 for object storage |
| P7 | AI deferred to v1; pgvector when AI is built |
| P8 | OCR deferred |

Each has a documented revisit trigger in `01` §6.2.

---

## D-006 — Pilot local level and wards

* **Date:** 2026-09-15
* **Status:** Open — Nova will decide
* **Previously:** Itahari Sub-Metropolitan City was the pilot recommended in the project context documents (not a formal decision)

### Context

Nova wants to choose the pilot municipality and wards himself, later. The architecture is unaffected: rules R1–R6 in `02` already keep every administrative unit in data, never in code. The choice does block data collection for Milestone A.

### Decision rule (while open)

* **No local level is named in application code, config defaults, or routes.**
* **Local levels and wards carry a `published` flag.** Only published units appear on public pages, in search, in the sitemap, and in the API.
* **Development uses fixture data** for a sample local level until the pilot is chosen.
* **Deadline:** chosen by **Friday 25 September 2026** (`08` §5 B1). A later choice moves v0.1 by the same number of days; after 2 Oct, v0.1 moves past Dashain to Tue 27 Oct.

### Selection criteria

1. **Sources exist:** 2022 ECN results available; the local level's website or notices are reasonably current.
2. **People on the ground:** operator presence or volunteers who can do field checks and collect notices.
3. **Manageable size:** fewer wards means less data to collect and verify for launch.
4. **No conflicts of interest:** no contracts, political roles or business interests of the operator, core team or their families in that local level.
5. **Language fit:** Nepali is widely used locally. Otherwise, Nepali/English-only UI is a weaker fit until more languages are supported.
6. **Reach:** where the expected launch audience actually lives.

### Consequences

* `02` §5 (Itahari facts) is kept as a worked example and a candidate profile. It is not a commitment.
* When the pilot is chosen, a matching facts section is added to `02` with the same verification tags.

---

## D-007 — Architecture baseline

* **Date:** 2026-09-17
* **Status:** Proposed
* **Details:** `06_TECHNICAL_ARCHITECTURE.md` v0.1, `05_DOMAIN_DATA_MODEL.md` v0.1

### Decision

1. **Monorepo** `hamro-ward` with `apps/api` (Laravel), `apps/web` (Next.js), `packages/api-client`, `infra/`, `docs/`, `tools/`.
2. **One Next.js app** serves both the public site and the staff dashboard, split by host (`admin.`) using middleware.
3. **Laravel on FrankenPHP** (classic mode) in production; queue worker and scheduler use the same image.
4. **PostgreSQL with PostGIS from v0.1**, so there is no database migration when maps arrive in v0.3.
5. **Single `admin_units` table** for the whole hierarchy, with level rules enforced by constraints and a trigger.
6. **Text + CHECK constraints** instead of PostgreSQL ENUM types; time-ordered UUID primary keys; public short IDs for shareable entities.
7. **OpenAPI generated from Laravel** (Scramble), with TypeScript types generated for the web app; stale types fail CI.
8. **Public pages render server-side from the internal API** and are cached at the edge; Laravel triggers signed on-demand revalidation.
9. **Staff auth:** Sanctum SPA cookies + Fortify with mandatory TOTP; roles via spatie/laravel-permission.

### Consequences

* One deployable per app, a single database, and few moving parts for a part-time team.
* Two languages and two build pipelines remain (the accepted cost of D-001).
* Each choice has a revisit trigger in `06` §25.

---

## D-008 — Planning and tracking method

* **Date:** 2026-09-17
* **Status:** Proposed

### Decision

* **Work items** follow Epic → Feature → Task with stable IDs (`HW-Enn-Fnn-Tnn`), defined **only** in `tools/backlog/build_backlog.py`.
* **Generated outputs** are `BACKLOG.md`, `backlog.json`, and `jira_import.csv`; `github_import.py` imports into GitHub Issues/Projects.
* **Rolling-wave planning:** tasks for the next version only; features for later versions.
* **Weekly Tuesday→Monday sprints** with Tuesday releases.
* **Recommended tracker: GitHub Projects** (free, next to code). Jira is supported through CSV import.

### Consequences

* Plan changes are code changes: reviewed, versioned, and regenerated.
* Tracker state such as progress and comments lives in the tracker, not in the builder.

---

## D-009 — Normal delivery pace and festival-aware calendar

* **Date:** 2026-09-17
* **Status:** Accepted (pace) · Proposed (AI rescheduling)

### Context

Nova chose the normal pace (~15 h/person/week) over a two-week sprint at ~30 h/person.

### Decision

1. **Capacity model:** 3 people × 15 h/week with 30% overhead ≈ 31 productive h/week, reduced in Dashain, Tihar, Christmas/New Year (Nova) and TU Dresden exam weeks.
2. **No due dates on main festival days;** releases avoid festival weeks.
3. **Release dates:** v0.1 Tue 13 Oct 2026 · v0.2 Tue 17 Nov 2026 · v0.3 Tue 1 Dec · v0.4 Tue 22 Dec · v0.5 Tue 12 Jan 2027 · v0.6 Tue 2 Feb · **v1.0 Tue 23 Feb 2027**. *Superseded the same day by D-010/D-011 (see D-011 consequences).*
4. **Capacity moves:** CI, revalidation and monitoring tasks move from Nova to Friend A; the D-002 data review moves to the operator; the web image is split out to Friend A; slug redirects move to v0.4; the corrections form moves to v0.3.
5. **AI assistant and OCR (was v0.7) become v1.2**, scheduled after the election unless funding and an extra contributor are available. `[PROPOSED]`

### Consequences

* v1.0 covers 10 of the 12 PRD acceptance criteria; criteria 11–12 (AI) move to v1.2.
* **Checkpoint Fri 2 Oct:** if Nova's remaining v0.1 work exceeds ~13 h, v0.1 moves to Tue 27 Oct (after Dashain) and later versions shift by 2 weeks.
* v1.0 lands about 10 weeks before local terms end (13 May 2027). If the ECN schedules nominations before March, apply the cuts in `08` §2.1.

---

## D-010 — Tenant per local level with a separate database

* **Date:** 2026-09-17
* **Status:** Accepted
* **Details:** `12_TENANCY_AND_IDENTITY.md`

### Context

Nova decided that every metropolitan city, sub-metropolitan city, municipality and rural municipality is a tenant with its own database.

**Previously:** a single database with a single `admin_units` hierarchy (D-007 item 4–5).

### Decision

1. **One central database** (identity, geography, persons, parties, catalogues, registries, cross-tenant indexes) plus **one PostgreSQL database per onboarded local level**, in the same cluster.
2. ~~`stancl/tenancy` in multi-database mode~~ — superseded by D-013: a thin in-house tenancy layer.
3. **Tenants are created only when a local level is onboarded,** through audited CLI commands with a separate provisioner role.
4. **Tenant databases keep read-only replicas** of national catalogues and their own geography subtree; cross-database references are validated by the application and a nightly consistency check.
5. **Central indexes** (sitemap, search, My reports) are updated through a transactional outbox. Request handlers never loop over all tenants.

### Consequences

* Strong isolation; a single municipality can be exported, restored, paused or handed over independently.
* Migrations, backups and monitoring now run per database; deploys must handle a failing tenant (maintenance mode for that tenant only).
* **Adds about 20 h to v0.1.** Revisit triggers in `12` §17. Schema-per-tenant remains the fallback if database count becomes an operational problem.

---

## D-011 — Citizen accounts and cookie authentication

* **Date:** 2026-09-17
* **Status:** Accepted (accounts, Fortify, Sanctum cookies, multiple wards) · Proposed (details in `12` §11–15)

### Context

Nova decided citizens sign in to report, using Fortify, Sanctum and cookie authentication. One citizen can report in several wards or municipalities, for example a permanent address different from a temporary address.

**Previously:** no citizen accounts before v2.0; anonymous reporting with Turnstile and fingerprint limits (`03` NFR-PRV-01, `06` §11).

### Decision

1. **Separate citizen (`users`) and staff (`staff_users`) accounts** in the central database, with separate guards and host-only session cookies. The API is served same-origin on the public and admin hosts.
2. **Fortify** handles registration, email verification, login, password reset, and TOTP. It is mandatory for staff and optional for citizens from v1.0.
3. **Saved wards:** up to 5 per citizen, each with a relationship (permanent address, temporary address, workplace, other). Only the ward is stored, never a street address.
4. **Reporting:** a verified citizen may report in any published ward. The relationship (or `visitor`) is stored privately for moderators; reporter identity is never public.
5. **Staff roles are scoped per tenant** through memberships; `operator_admin` is global.

### Consequences

* Less spam and a way to follow reports, at the cost of more personal data. Privacy notice, retention, deletion and export are required (`12` §15, G5 legal review).
* A transactional email provider is needed (free tier to be confirmed).
* **Adds about 25 h to v0.2.**
* **New release dates:** v0.1 **Tue 3 Nov 2026** · v0.2 **Tue 8 Dec 2026** · v0.3 Tue 22 Dec · v0.4 Tue 19 Jan 2027 · v0.5 Tue 2 Feb · v0.6 Tue 23 Feb · **v1.0 Tue 9 Mar 2027** (about 9 weeks before local terms end).

---

## D-012 — Configurable reporting scope

* **Date:** 2026-09-17
* **Status:** Accepted (configurable, default any ward) · Proposed (cooldown 72 h, 3 ward additions per 30 days)
* **Details:** `12` §12.4, `03` FR-ACC-09

### Context

Nova confirmed that citizens can report in any ward, and asked for this to be something that can be switched off.

### Decision

1. **Setting `issues.reporting_scope`:** `any_ward` (default) or `saved_wards_only`. It can be set as a platform default in central, or overridden for one local level in its tenant database.
2. **Only operator admins can change it,** with a required reason; every change is audited.
3. **Under `saved_wards_only`,** a ward must have been saved at least `issues.saved_ward_cooldown_hours` (default 72) before reporting. Citizens can add at most 3 saved wards per 30 days, in either mode.
4. **Changing the scope never alters existing reports.**

### Consequences

* A targeted response to brigading, without shutting reporting off entirely.
* Travellers and visitors can't report in restricted local levels. The UI explains why and how to add the ward.
* Adds 3 h to v0.2 (88.5 h); the release date is unchanged.

---

## D-013 — Tenancy spike outcome: thin in-house layer

* **Date:** 2026-09-22
* **Status:** Accepted
* **Task:** `HW-E29-F01-T01`
* **Changes:** D-010 item 2 (`stancl/tenancy`)

### Findings

* The latest stable `stancl/tenancy` on Packagist is **v3.10.1** (August 2026). It supports Laravel 10–13 and still depends on the abandoned `facade/ignition-contracts`.
* The package README points to the **v4** documentation, which describes a different API from the installable 3.x line. Building on it means working from docs that don't match the code we install.
* Our requirements are narrow and mostly custom anyway:
  * identification by local-level path, ward and issue ID;
  * a separate `CREATEDB` provisioner role, CLI-only;
  * reference-data replicas and a transactional outbox;
  * per-tenant maintenance on migration failure.
* The package's main strengths — domain identification, automatic bootstrappers, resource syncing — are features we either don't use or would replace.

### Decision

1. **Tenancy is implemented in `app/Modules/Tenancy`,** with these pieces:
   * `TenantManager` switches the `tenant` and `tenant_owner` connections.
   * `CreateTenantDatabase`, `MigrateTenant` and `DropTenantDatabase` actions manage the databases.
   * `hw:tenant:migrate` migrates every tenant.
   * `JobTenancy` puts the tenant ID into job payloads and restores it when the job runs.
   * The `UsesCentralConnection` and `UsesTenantConnection` traits mark which database a model uses, enforced by an architecture test.
2. **Tenant keys are 8 random hex characters,** generated once and unique. They are not derived from the local level's UUID: Laravel's UUIDs are time-ordered, so tenants created in the same minute would share a prefix.
3. **Extensions are installed in `template1`,** so the provisioner role never needs superuser rights to create PostGIS databases.
4. **No cache bootstrapper.** Tenant-scoped cache keys are prefixed explicitly when first needed.

### Consequences

* The code is about 600 lines, with no dependency drift and no mismatch between documentation and API.
* We own isolation correctness. It is covered by the model-connection architecture test and by lifecycle tests with two real tenant databases, plus the endpoint isolation suite in `HW-E29-F03-T03`.
* Schema-per-tenant remains a small change inside `TenantManager` if database count ever becomes a problem (`12` §17).

---

## D-014 — Persons and parties stay central; holdings reference them across databases

* **Date:** 2026-09-29
* **Status:** Accepted
* **Decided by:** Nova
* **Relates to:** D-010, D-013, `05` §5.4, `12` §9

### Context

`05` §12 places `persons` and `parties` in the central database and `office_holdings` in each tenant database, while `05` §5.4 describes `office_holdings.person_id` and `party_id` as foreign keys. Both cannot be true: PostgreSQL has no cross-database foreign keys.

Three ways out were considered.

1. **Move holdings to central.** Undoes the tenancy decision for the busiest read path and puts every municipality's representatives in one table.
2. **Replicate persons and parties into each tenant,** as with `source_types` and `positions`. Reference data is replicated because it changes on a deploy. People change every day — an editor corrects a name, a merge happens, someone is published. A replica of daily-changing data is a stale answer waiting to be served, and it would have to be invalidated from the central write path into every tenant that references the row.
3. **Keep them central and reference them by id,** resolving names at read time.

### Decision

1. **`persons` and `parties` remain central.** One row per person is what makes de-duplication, cross-tenant search and a career spanning several municipalities possible at all.
2. **`office_holdings.person_id` and `party_id` are plain `uuid` columns,** not foreign keys, and are documented as central references. The same pattern `sources.document_media_id` already uses.
3. **Writes validate them.** `RecordOfficeHolding` checks the person exists and has not been merged away, and that the party exists, before inserting.
4. **Reads batch them.** `CurrentSeatsQuery` reads `v_current_seats` from the tenant, then resolves people and parties in one central query each — three queries per page whatever the ward size, never one per seat.
5. **The nightly consistency check re-verifies them,** alongside the existing cross-database source references.

### Consequences

* A ward page cannot be answered by a single SQL statement. It is answered by three, which is cheaper than it sounds: the central database is on the same host and the result is cacheable per ward.
* Referential integrity for these two columns is the application's responsibility, and a bug there produces a seat with a dangling person id. The seat then renders as `not_verified` rather than as a broken page, because a holding with no resolvable person has no name to show — the failure is visible and safe.
* Deleting a person who holds office anywhere is not blocked by the database. Person deletion is not an operation the platform offers; merges and unpublishing are.
* A future extract of one municipality's database is self-contained except for these two columns, which is the same boundary already documented for national sources (`12` §9).

---

## D-015 — Build once, promote the artifact; staging and production from one image

* **Date:** 2026-09-25
* **Status:** Partly superseded by D-016 (items 4 and 5)
* **Decided by:** Nova
* **Relates to:** D-001, `06` §, `13` §4

### Context

The single Oracle A1 server becomes staging, with production arriving later either as a second Portainer stack on the same host or on a separate machine. The proposed pipeline built a staging image and a production image, and deployed each to its environment.

Two problems with that.

**Two images from one commit are two different artifacts.** Dependency resolution, base-image layers and build timestamps can all differ between two runs. Staging then tests something production never runs, which is the one thing a staging environment exists to prevent.

**The web image could not be promoted anyway.** `apps/web/Dockerfile` took `NEXT_PUBLIC_SITE_URL` and `NEXT_PUBLIC_ADMIN_HOST` as build arguments. Anything prefixed `NEXT_PUBLIC_` is compiled into the browser bundle, so the image carried one environment's hostnames in its JavaScript. Building twice was not a preference; it was forced by the Dockerfile.

### Decision

1. **Both images are environment-agnostic.** `NEXT_PUBLIC_SITE_URL` and `NEXT_PUBLIC_ADMIN_HOST` are removed. The only place a site URL was used is `metadataBase`, rendered on the server, so it reads `HW_SITE_URL` at request time — the pattern `HW_THEME` already uses. The build fails if an environment hostname appears in the compiled bundle.
2. **`API_INTERNAL_URL` stays baked, but names a Compose service.** Next.js resolves rewrites into the routes manifest at build time, so it cannot be a runtime value. `http://app:8080` is correct in every environment because service names are scoped to the stack's own network, unlike container names, which are global to the Docker host.
3. **Branches map to environments.** `staging` deploys staging; `main` deploys production.
4. **`main` promotes rather than rebuilds when it can.** The production workflow checks whether `sha-<commit>` already exists in the registry. If it does — which is the case when `main` is fast-forwarded to a commit already built on `staging` — it re-tags that manifest as `:production`. Nothing is pulled, nothing is rebuilt, the digest is unchanged. If the commit has no image, it builds one and marks the job summary to say that image has never run anywhere.
5. **One compose file, prefixed by `HW_STACK`.** Container names, volume names and the project name all derive from it, so two stacks can share a host.
6. **Migrations do not run on boot in production.** `HW_MIGRATE_ON_BOOT` is true on staging and false on production. The boot hook runs under `set -e` inside a restarting container, so a failing migration is an unbounded restart loop rather than a single error — which this project has already experienced, at restart 29.

### Consequences

* A fast-forward merge from `staging` to `main` ships the exact bytes that were tested. A merge commit does not, and the workflow says so rather than hiding it.
* Changing a domain, a theme or an environment no longer needs a rebuild — only a stack environment change and a restart.
* Production migrations become a deliberate step in the deploy runbook. That is more work per release and is the point.
* `APP_ENV=staging` on staging and `production` on production makes `DemoGuard` correct with no extra configuration: the demonstration dataset seeds on staging and is refused on production.
* The previous `image-api.yml` and `image-web.yml` workflows are replaced and must be deleted, or `main` will build twice.


---

## D-016 — Two literal stacks, build-only pipelines, deployment by hand

* **Date:** 2026-09-25
* **Status:** Accepted
* **Decided by:** Nova
* **Supersedes:** D-015 items 4 and 5

### Context

D-015 had `main` re-tag an image built on `staging` rather than rebuilding it, and derived both stacks from one `docker-compose.yml` parameterised by `HW_STACK`. Deployment was to be triggered by a Portainer stack webhook.

Three things were wrong with that in this environment.

**The webhook does not exist.** Portainer lists stack webhooks as a Business Edition feature. On this Community Edition install the toggle is visibly greyed out and labelled "Business Feature".

**The Community webhook that does exist is broken, silently.** Since 2.39.7 it returns HTTP 204 — a success code — and performs no deployment, with nothing written to Portainer's log ([portainer/portainer#13307](https://github.com/portainer/portainer/issues/13307), open). The workflow treated any 2xx as success, so it would have reported a green deploy while the server carried on running the previous image. A pipeline that lies about whether it deployed is worse than no pipeline.

**The parameterised compose file cost more than it saved.** Deriving every container, volume and network name from `$HW_STACK` meant reading a variable to know what a container would be called, for a system one person operates.

### Decision

1. **Two stack directories, each with a literal `docker-compose.yml` and its own `.env.example`:** `infra/stacks/hamroward-staging` and `infra/stacks/hamroward-production`. No interpolated names.
2. **Branch to environment, one build each.** `staging` builds and tags `:staging`; `main` builds and tags `:production`. No promotion step, no cross-branch image reuse.
3. **The pipelines build and stop.** Deployment is Portainer → Stacks → *Update the stack* → *Re-pull image*. The job summary prints the exact image reference to deploy and the pinned `sha-` tag for a rollback.
4. **Both images stay environment-agnostic** (D-015 items 1 and 2 stand). That is what makes a `sha-` tag a valid rollback target in either environment, and it is worth keeping regardless of how deployment is triggered.

### Consequences

* Production runs an image built from `main`, not the exact artifact staging tested. With one person and the same commits flowing through both, that risk is small; the environment-agnostic images mean promotion can be reintroduced later as a workflow change, with no change to the stacks.
* Deployment requires a human, which for a platform with no on-call is a feature: nothing ships while nobody is watching.
* The two compose files can drift. They differ only in the name prefix, the image tags and `HW_MIGRATE_ON_BOOT`; `diff` them when anything looks wrong.
* No deploy secrets exist. Nothing needs inbound access to the server, and there is no webhook URL to leak.
* If manual deployment becomes tiresome, the next step is GitHub Actions calling the **Portainer API** with an API token — which Community Edition does support — rather than the webhook, and a post-deploy check that the running image reports the expected commit.

# D-017 — shadcn/ui, with its tokens mapped onto the palettes

> **Filing note:** this entry belongs at the end of `DECISIONS.md`, after D-016.
> It is written as a separate file so the decision log can be appended to
> rather than rewritten wholesale. Paste it in and delete this file when
> convenient.

* **Date:** 2026-09-26
* **Status:** Accepted
* **Decided by:** Nova
* **Relates to:** `09_UX_UI_SPEC.md`, `NFR-NEU-02`, `NFR-NEU-04`, `NFR-ACC-01`

## Context

The web app's components were hand-written against a set of semantic CSS
variables (`--surface`, `--accent`, `--state-verified`) with five switchable
palettes in `tokens.css`. That worked, but every control — buttons, inputs,
badges, notices — was bespoke, and each new screen meant more bespoke markup
with no shared definition of what a button is.

Nova asked for shadcn/ui, keeping Himal as the default palette and keeping all
five switchable.

The obvious way to adopt shadcn is the way its documentation describes: run
`shadcn init`, which writes its own colour tokens as hex values into `:root` and
`.dark`. That would have produced two colour systems in one application — the
five palettes, and shadcn's — and every registry component would have needed
per-palette overrides. It would also have overwritten `globals.css`.

## Decision

1. **shadcn's token names are mapped onto the palette variables** inside the
   existing `@theme inline` block, rather than defined as colours:

   ```css
   --color-primary: var(--accent);
   --color-background: var(--bg);
   --color-card: var(--surface-2);
   --color-border: var(--line);
   --color-muted-foreground: var(--text-muted);
   ```

   `tokens.css` remains the only file in the application containing a colour
   value. Verified in the compiled stylesheet: `.bg-primary{background-color:var(--accent)}`.

2. **`shadcn init` is never run.** `components.json` is committed by hand, so
   `shadcn add <component>` — which only writes new files into
   `components/ui/` — continues to work.

3. **`raat` is the dark palette, and the `dark:` variant is bound to it**
   with `@custom-variant dark (&:is([data-theme="raat"] *))`. No `next-themes`,
   no `.dark` class that can disagree with `data-theme`.

4. **Primitives are added when a screen needs them,** not up front. Seven so
   far: button, input, card, badge, alert, separator, skeleton. Radix ships
   client components and the audience is on Android phones and inconsistent
   connections.

5. **The civic vocabulary stays ours.** Badge gains `verified`, `unverified`,
   `neutral` and `ai` variants; Alert gains `unverified` and `paused`. shadcn
   has no words for what this product is about, and "what verified looks like"
   needs exactly one definition.

6. **Three registry defaults are overridden, deliberately:**
   * focus is one `:focus-visible` outline in `globals.css` rather than
     per-component rings — an outline cannot be clipped by an ancestor's
     `overflow` (`NFR-ACC-01`);
   * every button size clears 44px, so `sm` differs by padding and type size
     rather than height;
   * inputs are `h-12` with `text-base`, below which iOS zooms the viewport on
     focus.

## Consequences

* An unmodified component from the registry renders correctly in all five
  palettes with no per-palette CSS. Adding a sixth palette is still a block in
  `tokens.css` plus a name in `lib/theme.ts`.
* Six small dependencies arrive: `clsx`, `tailwind-merge`,
  `class-variance-authority`, `lucide-react`, `@radix-ui/react-slot`,
  `@radix-ui/react-separator`. No component library is depended on — the
  components are source in the repository, which matches the no-vendor-lock-in
  principle better than a package that gets upgraded and fought.
* The token vocabulary changed across the whole app: `text-muted` →
  `text-muted-foreground`, `border-line` → `border-border`, `bg-surface-2` →
  `bg-card`, `text-accent-ink` → `text-accent-foreground`. **`text-muted` now
  means a background colour**, so any file still using it renders nearly
  invisible text.
* `09_UX_UI_SPEC.md` is now further out of date and needs rewriting against the
  built component set.
* Neutrality is unaffected. No party colours enter the palette, every template
  stays identical whatever the party, and the accent-versus-party-colour check
  (`NFR-NEU-04`) still applies to `tokens.css`, which is still the only place
  colours live.

## Alternatives considered

* **`shadcn init` as documented — rejected.** Two colour systems, per-palette
  overrides on every component, and `globals.css` overwritten.
* **Keep hand-written components — rejected.** It was working, but every new
  screen meant more bespoke controls with no shared definition, and the staff
  dashboard would have multiplied that.
* **A full component library (MUI, Mantine) — not considered seriously.**
  A runtime dependency that owns the markup is the opposite of what
  `06` §25's revisit triggers are for.
