# Hamro Ward — 12 Tenancy & Identity

> **Status:** Draft v0.2
> **Date:** 2026-09-17
> **Implements:** D-010 (tenant per local level, separate database each), D-011 (citizen accounts with multiple wards; Fortify + Sanctum cookie authentication), D-012 (configurable reporting scope)
> **Changes:** `03` (SRS), `05` (data model), `06` (architecture), `09` (UX), `08` (plan). Where one of those documents conflicts with this one, **this document wins** until the others are updated.

---

## 1. Decisions in one page

| Topic | Decision |
|---|---|
| Tenant | Every **local level** is a tenant: metropolitan city, sub-metropolitan city, municipality, or rural municipality |
| Databases | One **central** database, plus **one separate PostgreSQL database per tenant**, all in the same PostgreSQL cluster in v0–v1 |
| When tenants exist | A tenant database is created only when a local level is **onboarded**, not for all 753 local levels up front |
| Tenancy implementation | **Thin in-house layer** in `app/Modules/Tenancy` (D-013): `TenantManager` switches the `tenant` and `tenant_owner` connections; actions create, migrate and drop tenant databases; queued jobs carry their tenant. No tenancy package |
| Accounts | **Citizen accounts** (new) and **staff accounts**, both stored in the central database, with separate guards |
| Authentication | Laravel **Fortify** (registration, login, email verification, password reset, TOTP two-factor auth) + **Sanctum SPA cookie** sessions |
| API origin | **Same-origin:** `/api/*` and `/sanctum/*` are proxied to Laravel on both the public host and the admin host. Cookies are host-only |
| Citizen wards | A citizen links up to **5 wards**, each with a relationship: permanent address, temporary address, workplace, or other. They can be in different local levels |
| Reporting | A **verified citizen** can report an issue in **any published ward** by default. Operator admins can restrict a local level (or the whole platform) to **saved wards only** (D-012). The relationship to the ward is recorded privately for moderators |

**Alternatives considered:**

* **One database with `tenant_id` and row-level security.** Cheapest to operate, but rejected by the product owner in favour of physical separation.
* **Schema per tenant.** Would keep one connection pool and one dump. It remains the **fallback** if the database count becomes an operational problem (§17). Only `TenantManager` and `CreateTenantDatabase` would change (switch `search_path` instead of the database), so the cost of switching stays small.

---

## 2. Tenant model

```
hw_central                      ← identity, national reference data, registries, indexes
hw_t_<tenant_key>               ← one per onboarded local level
  e.g. hw_t_5f3a9c1e            (tenant_key = 8 random hex chars, generated once and unique;
                                 never a name, so renames need no database rename, and never
                                 a UUID prefix, which is time-ordered and would collide — D-013)
```

**Tenant states:**

| State | Meaning | Public access | Staff access |
|---|---|---|---|
| `provisioning` | Database being created, migrated, reference data syncing | 404 | Operator admin only |
| `active` | Normal | Yes, if the local level `is_published` | Members |
| `maintenance` | Migration failed or restore in progress | Maintenance notice for that local level only | Operator admin only |
| `suspended` | Paused by the operator (for example, a legal hold) | Notice | Read-only |
| `archived` | No longer served. Database dumped and dropped after retention | 410 | None |

Two independent gates control visibility:

* **`tenants.status = active`** — technical readiness.
* **`admin_units.is_published`** — editorial readiness (D-006).

---

## 3. Data placement

The rule: data that is **national, shared across local levels, or about a person's identity** lives in the central database. Data **produced or operated inside one local level** lives in that tenant's database.

| Data | Where | Why |
|---|---|---|
| `tenants` registry | Central | Resolves requests to databases |
| Geography (`admin_units`, names, slugs, aliases, codes, lineage, boundaries) | Central | National hierarchy; needed to route a ward to its tenant, and for citizens' wards in any local level |
| `bs_calendar_months`, `positions`, `source_types`, `issue_categories` | Central (**read-only replicas** in each tenant) | National catalogues; replicas keep tenant-side foreign keys and views working |
| `parties`, `persons`, `person_aliases`, `person_merges` | Central | A person can hold office or run in several places over time; parties are national |
| `elections` (events) | Central | National or provincial events spanning local levels |
| National `sources` (ECN, Government of Nepal documents) | Central | Shared evidence used by many tenants |
| `users` (citizens), `user_wards`, `user_issue_index` | Central | One account works across local levels |
| `staff_users`, roles, `staff_memberships`, `affiliation_declarations`, `recusals` | Central | Staff may work for several tenants; D-002 checks span tenants |
| `sessions`, password reset tokens, `jobs`, `failed_jobs`, cache | Central | Infrastructure |
| `public_entities` (search, sitemap, published paths) | Central | Cross-tenant listing without querying every database |
| Central `audit_events` | Central | Auth, staff management, tenant lifecycle, geography, persons |
| `ward_offices` | Tenant | Local facts |
| `office_holdings`, `vacancies` | Tenant | Local seats |
| Local `sources` (municipal notices, ward documents); `source_links` and `fact_conflicts` **for tenant subjects** | Tenant | Local evidence; links may point to a central source (`source_scope = 'central'`) |
| `source_links` and `fact_conflicts` **for central subjects** (geography names and codes, persons, parties) | Central | Central facts need provenance too; corrected 24 Sep 2026 |
| `issues`, `issue_media`, `issue_status_events`, `issue_confirmations`, `content_flags` | Tenant | Citizen reports for that local level |
| `media` records (files in R2 under the tenant prefix) | Tenant | |
| `moderation_items`, `moderation_decisions`, `corrections` | Tenant | Moderation happens per local level |
| `promises` (v0.5), `contests`, `candidacies` (v0.6), `projects` (v2.0) | Tenant | Local elections and accountability |
| Tenant `settings` (for example, the kill switch) | Tenant | Pausing reports in one municipality doesn't affect others |
| Tenant `audit_events` | Tenant | Every change to that tenant's data |
| `outbox_events` | Tenant | Reliable updates to central indexes (§4.3) |

---

## 4. Cross-database rules

### 4.1 References

* PostgreSQL cannot enforce foreign keys across databases. Tenant tables reference central rows by UUID in plainly named columns (`person_id`, `ward_id`, `reporter_user_id`), each with a code comment `-- central ref`.
* **Integrity controls:**
  1. **Write-time validation:** application validators check that the central row exists (for example, `CentralPersonExists`).
  2. **Nightly consistency job:** `hw:consistency-check` reports dangling references per tenant to the operator; it never deletes.
  3. **Merges and deletes of central persons are blocked** while any tenant references them, until references are re-pointed via `hw:person:merge` (which updates every tenant).

### 4.2 Reference replicas

Each tenant database has read-only copies of `positions`, `source_types`, `issue_categories`, `bs_calendar_months`, and **its own `admin_units` subtree** (the local level and its wards).

* The `SyncTenantReferenceData` job is idempotent and keyed by `reference_version`.
* It runs at provisioning, after a relevant central change (event), and on every deploy.
* Tenant foreign keys and views (such as `v_current_seats`) use the replicas.
* The application role has `SELECT` only on replica tables. The sync job uses the owner role.

### 4.3 Outbox for central indexes

Tenant transactions that change public or user-visible state insert an `outbox_events` row **in the same transaction**, for example `issue.state_changed` or `office_holding.published`.

* The `DispatchTenantOutbox` job reads unprocessed rows per tenant and upserts central `public_entities` and `user_issue_index`.
* Processing is idempotent (`event_id` unique in central `processed_outbox_events`).
* If the central write fails, the event is retried. Nothing is lost when a job crashes.

### 4.4 Cross-tenant reads

| Need | Pattern |
|---|---|
| Sitemap, published paths, search | Central `public_entities` |
| "My reports" | Central `user_issue_index`, then open the tenant for detail |
| Staff queue across several tenants | Per-tenant queries, bounded by the staff member's memberships (usually 1–3) |
| National or provincial statistics (later) | Nightly aggregation job into central summary tables |
| Anything else | **Not allowed** in request handlers. Looping over all tenants during a web request is prohibited by code review and an architecture test |

---

## 5. Tenant lifecycle

| Command (CLI only; never triggered from the web) | Does |
|---|---|
| `hw:tenant:create {local-level-path}` | Validates the unit is a local level, then creates the `tenants` row (`provisioning`), `CREATE DATABASE` with the provisioner role, runs tenant migrations, syncs reference data, grants roles, sets status `active`, and writes an audit event |
| `hw:tenant:migrate [--tenant=]` | Runs tenant migrations one tenant at a time and records `schema_version` |
| `hw:tenant:suspend {tenant}` / `resume` | Status change + audit |
| `hw:tenant:export {tenant}` | `pg_dump` of the tenant database plus the tenant's R2 prefix manifest, encrypted |
| `hw:tenant:restore {tenant} {dump}` | Status `maintenance`, restore into a new database, verify, switch, `active` |
| `hw:tenant:archive {tenant}` | Final export, status `archived`, database dropped after 90 days |

Fixture tenants for development and tests: **Namuna Nagarpalika** (municipality, 5 wards) and **Udaharan Gaunpalika** (rural municipality, 4 wards). Both are fictional.

---

## 6. Identification and routing

### 6.1 Public (read) traffic

Next.js page paths already include the local level (`/ne/ward/{province}/{district}/{local-level}/{n}`).

API routes identify the tenant in one of two ways:

| Route shape | Middleware | Resolution |
|---|---|---|
| `/api/v1/local-levels/{slugPath}/…` | `InitializeTenancyByLocalLevelPath` | Central `admin_unit_slugs` → local level → `tenants` |
| `/api/v1/wards/{wardId}/…`, `/api/v1/issues/{publicId}` | `InitializeTenancyByWard` / `InitializeTenancyByIssue` | Ward → parent local level → tenant. Issue public IDs carry a tenant prefix (`{tenant_key[0:4]}-{id}`), so no global lookup is needed |
| `/api/v1/geo/*`, `/api/v1/persons/*`, `/api/v1/me/*` | None (central) | — |

Unknown, unpublished, or non-active tenants return 404 (or the maintenance/suspended notice) **without revealing whether the tenant exists**.

**Schema guard:** if `tenants.schema_version` doesn't match the deployed code's expected version, the request gets the maintenance response. This avoids running new code against an old schema.

### 6.2 Staff traffic

Staff pick a tenant in the dashboard (only tenants they are members of).

* API routes: `/api/v1/staff/tenants/{tenantKey}/…`, protected by `InitializeTenancyForStaff`, which checks an active `staff_memberships` row (or the `operator_admin` role) **before** switching databases.

---

## 7. Connections and database roles

| Role | Rights | Used by |
|---|---|---|
| `hw_owner` | Owns central and tenant schemas | Migrations and reference sync (deploy, CLI) |
| `hw_app` | `CONNECT` on central and active tenant databases; DML; no `UPDATE`/`DELETE` on append-only tables; `SELECT` only on replicas | Web, worker, scheduler |
| `hw_provisioner` | `CREATEDB` | `hw:tenant:create` only; password stored separately from the app `.env` |
| `hw_backup` | Read-only on all databases | Backup script |

**Connections:**

* `central` is the default connection.
* `tenant` is configured at runtime by the tenancy bootstrapper.
* A web request uses at most 2 connections.
* Workers reconnect when switching tenants and disconnect after each tenant job.
* `max_connections` stays at the default (100) until more than about 50 tenants; revisit trigger in §17.

**Model rules** (Pest architecture tests):

* Tenant models use the tenant connection trait.
* Central models declare `$connection = 'central'`.
* Raw `DB::` calls must name a connection.

---

## 8. Migrations and deploys

```
apps/api/database/migrations/central/   ← central schema
apps/api/database/migrations/tenant/    ← applied to every tenant database
```

**Deploy order** (extends `06` §20):

1. Pre-deploy backup of central **and every tenant**.
2. `php artisan migrate --path=database/migrations/central --force`
3. `php artisan hw:tenant:migrate` — sequential. Each tenant is one transaction per migration file. Each success updates `schema_version`.
4. On any tenant failure: stop migrating, mark that tenant `maintenance`, alert, and continue the deploy. Expand/contract migrations keep old and new code compatible, so the other tenants keep serving.
5. `SyncTenantReferenceData` for all tenants, then revalidation.

**Deploy-time budget:** tenant migrations must finish within 10 minutes in total. When that is exceeded, migrations run in batches outside the deploy window (§17).

---

## 9. Jobs, scheduler, cache, storage

| Concern | Rule |
|---|---|
| Queue | Database queue in central. Jobs dispatched inside a tenant context carry `tenant_id` and re-initialize tenancy (queue tenancy bootstrapper) |
| Scheduler | Tenant-wide tasks (SLA checks, contact purge, outbox dispatch) loop over `active` tenants with one job per tenant |
| Cache | Database cache store in central. Tenant cache keys are prefixed `t:{tenantKey}:` by a `TenantCache` helper. Tag-based tenant cache bootstrapping is **not** used with the database store (verify in spike) |
| Rate limiting | Keys include user ID and/or client fingerprint, plus the tenant key for per-municipality limits |
| Files | R2 key prefix `tenants/{tenantKey}/…` for tenant media; `central/…` for central documents |
| Logs and Sentry | Every log line and Sentry event is tagged with `tenant_key` when tenancy is initialized |

---

## 10. Backups and recovery

* **Nightly:** `pg_dumpall --globals-only` (roles), plus `pg_dump -Fc` of central, plus one dump per active tenant. All age-encrypted and uploaded to `hw-backups/{date}/{database}.dump.age`.
* **Retention:** unchanged (`06` §21).
* **Per-tenant restore** is a normal operation (`hw:tenant:restore`). A bad import in one municipality can be rolled back without touching others.
* **Monthly drill:** restore central plus one tenant into scratch, run the consistency check, record timings.
* **RPO/RTO:** unchanged (24 h / 4 h), measured per database.

---

## 11. Accounts and authentication

### 11.1 Account types

| | Citizen (`users`) | Staff (`staff_users`) |
|---|---|---|
| Purpose | Report issues, follow wards, see own reports | Moderate, verify, manage data |
| Host | Public host | Admin host |
| Guard / broker | `web` / `users` | `staff` / `staff_users` |
| Session cookie | `hw_session`, host-only on the public host | `hw_staff_session`, host-only on the admin host |
| Registration | Self sign-up (Turnstile), email verification required before reporting | Invite only, by operator admin |
| Two-factor auth | Optional (v1.0) | **Mandatory** TOTP |
| Same person as both? | Allowed, with **separate accounts and credentials** | — |

### 11.2 Same-origin API and cookies

```
Browser (hamroward.<tld>)        → /api/*, /sanctum/*  → Caddy → Laravel   (cookies host-only on public host)
Browser (admin.hamroward.<tld>)  → /api/*, /sanctum/*  → Caddy → Laravel   (cookies host-only on admin host)
Next.js server (internal)        → http://api/api/v1/* (public reads, no cookies)
```

* No CORS is needed.
* `SESSION_DOMAIN` is **null** (host-only).
* `SANCTUM_STATEFUL_DOMAINS` lists the public host and the admin host.
* Cookies are `Secure`, `HttpOnly` (session), `SameSite=Lax`.
* CSRF uses Sanctum's `XSRF-TOKEN` cookie (host-only) and the `X-XSRF-TOKEN` header.
* The optional `api.` host stays for future partner APIs and serves **no** cookie-authenticated routes.
* **Host-based configuration:** middleware `ConfigureAuthForHost` runs before `StartSession` on every `/api` and `/sanctum` route. It sets the session cookie name, `fortify.guard`, `fortify.passwords`, `fortify.home` and `sanctum.guard` from the request host. Staff routes reject the public host (404) and citizen routes reject the admin host (404).
* Running Fortify for two guards through runtime config switching is verified in spike `HW-E30-F01-T01`. **Fallback:** two thin route groups with custom Fortify-style controllers for the staff guard, reusing Fortify actions.

### 11.3 Citizen flows (Fortify features enabled)

| Flow | Endpoint | Rules |
|---|---|---|
| Register | `POST /register` | Display name (2–60 characters), email, password (min 10, checked against a breached-password list if available offline), preferred locale, Turnstile. Response is identical whether or not the email exists |
| Verify email | `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification` | Signed link, expires in 60 min. Resend limited to 3/hour |
| Login | `POST /login` | 5 attempts/min per email + IP. Session regenerated |
| Logout | `POST /logout` | Session invalidated |
| Forgot / reset password | `POST /forgot-password`, `POST /reset-password` | Enumeration-safe responses; Turnstile on forgot |
| Change password / profile | `PUT /user/password`, `PUT /user/profile-information` | Re-authentication required for email change |
| Current user | `GET /api/v1/me` | Returns display name, locale, verified flag, wards. `Cache-Control: private, no-store` |

**No phone/SMS login in v0–v1** (cost and SIM-linked identity concerns). Revisit when funded.

### 11.4 Logged-in users and edge caching

* **Public HTML never varies by user,** so Cloudflare keeps caching it.
* The header's account area renders a neutral placeholder server-side, then fetches `/api/v1/me` client-side after hydration.
* Pages behind login (`/ne/account/*`, `/ne/report`) are client-rendered shells with `no-store`.
* Cloudflare bypasses the cache for `/api/*`, `/sanctum/*`, `/*/account/*`, and `/*/report*`.

### 11.5 Staff

* Fortify login on the admin host with **mandatory TOTP**. First login forces enrolment.
* **Authorization = global role + tenant memberships:**

| Table | Columns |
|---|---|
| `staff_memberships` | id, staff_user_id, tenant_id, role (`moderator`, `verifier`, `data_editor`, `viewer`), granted_by, granted_at, revoked_at |

* `operator_admin` is a global role (spatie/laravel-permission) and implies access to all tenants.
* Tenant roles are **not** spatie roles. They are memberships checked by `StaffTenantPolicy`, so a moderator in one municipality has no rights in another.
* D-002 affiliation declarations and recusals stay central and are checked in every tenant.

---

## 12. Citizen wards (permanent and temporary addresses)

### 12.1 Model (central)

| Table | Columns | Constraints |
|---|---|---|
| `users` | id uuid, display_name, email citext UNIQUE, email_verified_at, password, preferred_locale (`ne`, `en`), two_factor_* (nullable), status (`active`, `locked`, `deleted_pending`), last_login_at, created_at, updated_at | — |
| `user_wards` | id, user_id FK CASCADE, ward_id FK → admin_units, relationship, is_primary boolean, created_at, updated_at | `relationship IN ('permanent_address','temporary_address','workplace','other')`; UNIQUE (user_id, ward_id, relationship); ward must be level `ward` (trigger); at most 5 rows per user and at most 3 additions per 30 days (action, D-012); at most one `is_primary` per user (partial unique index); `created_at` drives the reporting cooldown (§12.4) |

**Deliberately not stored:** street, tole, house number, GPS home location, citizenship or national ID number, date of birth, phone. **A ward is precise enough** to route reports and keep the account useful, without building an address registry of citizens.

### 12.2 Behaviour

* A ward can be saved even if its local level is not onboarded yet. The UI says "Hamro Ward isn't open in this municipality yet," and the ward becomes usable automatically when the tenant goes live.
* Relationship labels (draft):

| Value | Nepali | English |
|---|---|---|
| `permanent_address` | स्थायी ठेगाना | Permanent address |
| `temporary_address` | अस्थायी ठेगाना | Temporary address (where I live now) |
| `workplace` | काम गर्ने ठाउँ | Where I work |
| `other` | अन्य | Other |

* Removing a ward never affects reports already submitted.

### 12.3 Reporting rules

| Rule | Decision |
|---|---|
| Who can report | A signed-in citizen with a **verified email** |
| Where | Depends on the **reporting scope** (§12.4). Default `any_ward`: any published ward in an active tenant, with saved wards offered first. `saved_wards_only`: only wards the citizen saved at least the cooldown period ago |
| Relationship snapshot | Stored on the issue as `reporter_relationship`: the matching saved relationship, or `visitor` if the ward isn't saved (possible only under `any_ward`). **Visible to moderators only**, never public, never the sole reason for rejection |
| Identity in public | **Never shown.** Public issue pages show "Community report" with no name. Moderators see the display name; the email is shown only through an audited reveal |
| Limits | Per user: 5 reports/hour, 20/day across all tenants; per ward per user: 3/day. Per IP fingerprint as a backstop |
| Contact | The account email replaces the old optional contact field. The citizen can opt out of status emails |

### 12.4 Reporting scope (D-012)

| Setting | Values | Where | Default |
|---|---|---|---|
| `issues.reporting_scope` | `any_ward`, `saved_wards_only` | Central `settings` (platform default) and tenant `settings` (override for one local level) | `any_ward` |
| `issues.saved_ward_cooldown_hours` | 0–720 | Central and tenant, same override rule | 72 `[PROPOSED]` |

**Effective scope** = tenant override if set, otherwise the platform default.

**Who can change it:** `operator_admin` only, through `PUT /staff/settings/{key}` (platform) or `PUT /staff/tenants/{tenantKey}/settings/{key}` (one local level). The confirmation dialog states the exact effect, a reason note is required, and the change is audited in the database where it applies.

**Rules when `saved_wards_only` is in effect:**

1. The ward must be in the citizen's saved wards, **and** saved at least `saved_ward_cooldown_hours` before the report. This stops someone from adding a ward just to post into it.
2. `visitor` reports are refused with `403` and problem type `reporting-scope-restricted`. The response says whether the ward is not saved or still in cooldown, and when it becomes usable.
3. To limit churn, a citizen can add at most **3 saved wards per 30 days** (applies in both modes).
4. **Changing the scope never affects existing reports.** Pending reports stay in the queue; published ones stay public.
5. The ward page and the report flow show the restriction before the citizen starts writing (`09` §5.5).

**When to use it:** coordinated brigading from outside a ward, harassment campaigns, or on a municipality's request during a sensitive period. Consider the per-tenant kill switch first if moderation capacity is the problem.

---

## 13. Data flow — citizen reports an issue in another municipality

1. The citizen (saved wards: permanent address in local level A, temporary address in local level B) opens `/ne/report` on the public host. `GET /api/v1/me` returns both wards.
2. They pick their ward in B, a category and details, attach photos, and pass Turnstile.
3. `POST /api/v1/uploads?ward={wardB}` resolves tenant B, stores the file under `tenants/{B}/…`, and queues `ProcessImage` with tenant B's context.
4. `POST /api/v1/wards/{wardB}/issues` goes through `ConfigureAuthForHost` (web guard), `auth:sanctum`, `verified`, `InitializeTenancyByWard`, and then rate limits. It then:
   * creates the issue (reporter_user_id, relationship `temporary_address`),
   * creates the moderation item and an outbox event,
   * commits in tenant B, then writes a tenant B audit event.
5. `DispatchTenantOutbox` (B) upserts central `user_issue_index`.
6. The citizen's **My reports** shows the pending report. A moderator who is a member of B reviews it in the admin dashboard.
7. On approval, the issue is published in B, media becomes public, the outbox updates `user_issue_index` and `public_entities`, the ward page is revalidated, and the citizen gets an email if they opted in.

---

## 14. "My reports"

| Table (central) | Columns |
|---|---|
| `user_issue_index` | user_id, tenant_id, issue_id, public_id, ward_id, title (the citizen's own text), moderation_state, lifecycle_status, submitted_at, updated_at. PRIMARY KEY (tenant_id, issue_id); index (user_id, updated_at DESC) |

* `GET /api/v1/me/issues` lists from the index.
* The detail view (`GET /api/v1/me/issues/{publicId}`) opens the tenant and checks `reporter_user_id = auth user`. It includes the rejection reason code in plain language and the appeal action (v0.4).

---

## 15. Privacy

This section updates `03` NFR-PRV and needs legal review under G5 (Individual Privacy Act 2075).

| Data | Purpose | Retention |
|---|---|---|
| Email, password hash, display name, locale | Account | Until deletion |
| Saved wards + relationship | Faster reporting; moderation context | Until the user removes them or deletes the account |
| `reporter_user_id` and relationship on issues | Abuse prevention, status emails, "My reports" | Pending or rejected: deleted with the issue (rejected content after 90 days). Approved: the link is **removed** 90 days after resolution or on account deletion; the issue stays as an anonymous community report |
| Login IP (truncated) | Security | 30 days |

**Account deletion** (`DELETE /api/v1/me`, with re-authentication):

* status becomes `deleted_pending`,
* pending reports are withdrawn,
* reporter links are nulled in all tenants (via outbox),
* sessions are revoked,
* and the user row is hard-deleted after 7 days.

**Export:** `GET /api/v1/me/export` returns JSON with the profile, wards and own reports.

**Moderators never see other citizens' saved wards,** only the relationship snapshot on the item being reviewed.

---

## 16. Security additions

| Threat | Control |
|---|---|
| Tenant data leak via wrong connection | Architecture tests (model connection rules); `TenantIsolationTest` suite runs every public and staff endpoint against two fixture tenants and asserts no cross-tenant data; no raw queries without a named connection |
| Staff acting outside their tenant | `InitializeTenancyForStaff` checks membership **before** switching databases; policy tests per role and tenant |
| Session confusion between citizen and staff | Host-only cookies with different names; guard-per-host middleware; staff routes 404 on the public host |
| Credential stuffing / fake accounts | Login throttles, Turnstile on register and forgot-password, verification required before reporting, per-user and per-IP limits, disposable-domain blocklist (optional) |
| Account enumeration | Identical responses for register, forgot, and resend |
| Mass political brigading (many accounts reporting one ward) | Per-ward report velocity alert to moderators; relationship snapshot helps review; kill switch per tenant |
| Provisioner credential abuse | Provisioner role only used by CLI; not in the web container's environment |

---

## 17. Operational limits and revisit triggers

| Signal | Action |
|---|---|
| More than 50 active tenants | Review `max_connections`, backup window, and deploy migration time; consider PgBouncer |
| Tenant migrations exceed 10 minutes per deploy | Batch migrations outside the deploy window with a compatibility guard |
| More than 150 active tenants, or database overhead above 25% of RAM | Evaluate **schema per tenant** or a dedicated database server (8–16 GB) |
| A local government requests its own hosting | Export and restore its tenant to a dedicated cluster; the central database stays with the operator |

---

## 18. Spikes and items to verify

| ID | Question | Task |
|---|---|---|
| S1 | ~~Tenancy package fit~~ **Resolved 22 Sep 2026 (D-013):** in-house layer instead of `stancl/tenancy` | `HW-E29-F01-T01` |
| S2 | Fortify for two guards through host-based config switching; Sanctum stateful cookies with same-origin proxy through Next.js/Caddy | `HW-E30-F01-T01` |
| S3 | ~~Database cache store with tenancy~~ **Resolved (D-013):** no cache bootstrapper; tenant keys prefixed explicitly by `TenantCache` when first needed | `HW-E29-F01-T01` |
| S4 | Transactional email provider free tier sufficient for verification emails | `HW-E30-F01-T02` |
| S5 | Legal review of account data, reporter links, and retention | G5 |

---

## 19. Plan impact

| | Before (D-009) | After (D-010/D-011) |
|---|---|---|
| v0.1 | 108 h, Tue 13 Oct 2026 | 128 h, **Tue 3 Nov 2026** (after Dashain, before Tihar) |
| v0.2 | 61 h, Tue 17 Nov 2026 | 88.5 h, **Tue 8 Dec 2026** |
| v1.0 | Tue 23 Feb 2027 | **Tue 9 Mar 2027**, about 9 weeks before local terms end |

Details and weekly load are in `08` and `BACKLOG.md`.
