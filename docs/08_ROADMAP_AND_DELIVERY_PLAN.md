# Hamro Ward — 08 Roadmap & Delivery Plan

> **Status:** Draft v0.4
> **Date:** Thu 17 Sep 2026
> **Pace:** Normal (D-009): 3 core people × ~15 h/week
> **Scope changes included:** tenant per local level with separate databases (D-010) and citizen accounts with multiple wards (D-011), both detailed in `12_TENANCY_AND_IDENTITY.md`
> **Generated companion:** `BACKLOG.md` (every task, day by day, with the weekly capacity check), `tools/backlog/jira_import.csv`, `tools/backlog/backlog.json`
> **Source of truth for work items:** `tools/backlog/build_backlog.py`. Edit it, then re-run it; never hand-edit the generated files.

---

## 1. Planning approach

**Rolling-wave planning.**

* v0.1 and v0.2 are broken down into tasks, each with an owner, estimate, due day and acceptance criteria.
* v0.3–v1.0 are broken down into features, with estimates and planned weeks.
* v1.1+ exist as features only.
* Tasks for each later version are written at that version's planning session.

**Work-item hierarchy:** Epic (`HW-Enn`) → Feature (`HW-Enn-Fnn`) → Task (`HW-Enn-Fnn-Tnn`).

* 30 epics, 91 features, 106 tasks.
* IDs never change; branches, commits and PRs reference them.

**Sprints and releases:**

* Weekly sprints run Tuesday → Monday.
* Releases happen on Tuesdays.
* W00 is a short Sprint 0 (Thu 17 – Mon 21 Sep).

**Festival-aware scheduling:**

* No due dates on the main days of Dashain (11–25 Oct 2026, tika 21 Oct) or Tihar (8–11 Nov 2026).
* No release lands inside either festival.

---

## 2. Capacity model

| Input | Value |
|---|---|
| Core team | Nova (backend/ops/lead), Friend A (frontend), Friend B (data/QA), plus an operator contact for content and governance |
| Hours | ~15 h/person/week → 45 h/week |
| Overhead | 30% → **~31 productive h/week** |
| Reduced weeks | Dashain W04 40%, W05 25%; Tihar W07 80%, W08 50%; Christmas/New Year W14–W15 60%; TU Dresden exams W19–W21 80% `[TO VERIFY]` |
| Confidence | Tasks ±30%; features ±50%. Re-estimate at each version planning session |

### 2.1 Load per version and role

| Version | Nova | Friend A | Friend B | Operator | Total |
|---|---|---|---|---|---|
| v0.1 Ward Pages | 69 h | 35.5 h | 13.5 h | 10 h | **128 h** |
| v0.2 Citizen Accounts & Issue Reporting | 49 h | 32 h | 2 h | 5.5 h | **88.5 h** |

The weekly table in `BACKLOG.md` §2 stays within productive capacity in every week. W08 (Tihar) is the tightest: 15.5 h planned against 15.7 h available.

### 2.2 Nova is the bottleneck

Nova carries about 13–14 h/week of tasks from W01 to W03. The foundations everything else depends on (tenancy, schema, routing, deploy) are backend work.

**Checkpoint on Sun 4 Oct:** if more than ~6 h of Nova's W00–W02 tasks are still open, then:

* the pilot import and isolation suite move into W06,
* v0.1 moves to **Tue 17 Nov** (after Tihar),
* all later versions shift by 2 weeks,
* and the change is recorded in `DECISIONS.md`.

### 2.3 Election-critical path

Local terms end **13 May 2027**, and the election date is not announced. v1.0 is planned for **Tue 9 Mar 2027**, about 9 weeks before terms end.

**Already applied:** AI assistant and OCR → v1.2 (after the election, unless funded with an extra contributor).

**If a version slips by more than 2 weeks, or the ECN schedules nominations before mid-March,** cut in this order:

1. **v0.5 promise tracker** shrinks to the data model plus the coverage-parity display.
2. **v0.4 second tenant** is postponed; the data management UI stays.
3. **v0.3 map** is reduced to a static boundary plus the issue list.
4. **v1.0 offline drafts** are deferred.
5. **Citizen two-factor auth** stays in v1.0 as optional, and is the first thing dropped.

**Never cut:** provenance, moderation, audit, staff two-factor auth, tenant isolation tests, backups.

---

## 3. Calendar

| Week | Dates | Work | Notes |
|---|---|---|---|
| W00 | Thu 17 – Mon 21 Sep 2026 | Repo, scaffolds, dev stack, VPS, design tokens | — |
| W01 | Tue 22 – Mon 28 Sep | **Tenancy spike + foundation**, central geography and provenance schema, components | **Pilot decision Fri 25 Sep** |
| W02 | Tue 29 Sep – Mon 5 Oct | Offices schema, tenant provisioning, reference sync, tenant routing, API client | **Checkpoint Sun 4 Oct** |
| W03 | Tue 6 – Mon 12 Oct | Geography/ward/person APIs, outbox + public index, home and ward pages, data review | Ghatasthapana 11 Oct |
| W04 | Tue 13 – Mon 19 Oct | Importer, person page (light week) | Dashain: Phulpati 17 Oct |
| W05 | Tue 20 – Mon 26 Oct | Isolation test suite only | Dashain: tika 21 Oct, visits until 25 Oct |
| W06 | Tue 27 Oct – Mon 2 Nov | Images, deploy with tenant migrations, backups, pilot tenant + import, SEO, trust pages, QA, Gate A | — |
| W07 | Tue 3 – Mon 9 Nov | **v0.1 release Tue 3 Nov**; issue schema, staff memberships, same-origin auth, citizen Fortify | Tihar begins 8 Nov |
| W08 | Tue 10 – Mon 16 Nov | Staff 2FA, saved wards, audit, auth screens | Tihar until 11 Nov |
| W09 | Tue 17 – Mon 23 Nov | Uploads, issue submission, media, affiliations, My wards UI, report flow, privacy notice | — |
| W10 | Tue 24 – Mon 30 Nov | Moderation core, public issues, kill switch, My reports, account deletion, queue screen | — |
| W11 | Tue 1 – Mon 7 Dec | Review/audit screens, security review, onboarding, QA, Gate B | — |
| W12 | Tue 8 – Mon 14 Dec | **v0.2 release Tue 8 Dec**; v0.3 (corrections, boundaries, map, staging) | — |
| W13 | Tue 15 – Mon 21 Dec | v0.3 (map, filters/flags, analytics, SLA alerts) | — |
| W14 | Tue 22 – Mon 28 Dec | **v0.3 release Tue 22 Dec**; v0.4 | Christmas (Nova) |
| W15 | Tue 29 Dec – Mon 4 Jan 2027 | v0.4 | New Year (Nova) |
| W16 | Tue 5 – Mon 11 Jan | v0.4 | — |
| W17 | Tue 12 – Mon 18 Jan | v0.4 | — |
| W18 | Tue 19 – Mon 25 Jan | **v0.4 release Tue 19 Jan**; v0.5 | — |
| W19 | Tue 26 Jan – Mon 1 Feb | v0.5 | Exams `[TO VERIFY]` |
| W20 | Tue 2 – Mon 8 Feb | **v0.5 release Tue 2 Feb**; v0.6 | Exams `[TO VERIFY]` |
| W21 | Tue 9 – Mon 15 Feb | v0.6 | Exams `[TO VERIFY]` |
| W22 | Tue 16 – Mon 22 Feb | v0.6 | — |
| W23 | Tue 23 Feb – Mon 1 Mar | **v0.6 release Tue 23 Feb**; v1.0 | — |
| W24 | Tue 2 – Mon 8 Mar | v1.0 hardening, legal sign-off | — |
| — | **Tue 9 Mar 2027** | **v1.0 Election MVP** | ~9 weeks before terms end |
| Mar–May 2027 | ECN-driven | v1.1 election period operations | Replan within 1 week of the ECN calendar |
| After election | Funding-gated | v1.2 AI assistant | — |
| Jun–Dec 2027 | — | v2.0 accountability platform | — |

Release rows show the Tuesday a version ships. Where a release date and the next version's work share a week, the new work starts the day after release.

---

## 4. Version plans

| Version | Name | Estimate | Release |
|---|---|---|---|
| v0.1 | Ward Pages (with tenancy foundation) | 128 h | **Tue 3 Nov 2026** |
| v0.2 | Citizen Accounts & Issue Reporting | 88.5 h | **Tue 8 Dec 2026** |
| v0.3 | Map & Stabilization | 44 h | Tue 22 Dec 2026 |
| v0.4 | Data Tools & Second Local Level | 69.5 h | Tue 19 Jan 2027 |
| v0.5 | Incumbent Promise Tracker | 44 h | Tue 2 Feb 2027 |
| v0.6 | Elections & Candidate Readiness | 51 h | Tue 23 Feb 2027 |
| v1.0 | Election MVP | 42 h | **Tue 9 Mar 2027** |
| v1.1 | Election Period Operations | TBD | ECN |
| v1.2 | AI Assistant | 59 h | Funding-gated |
| v2.0 | Accountability Platform | TBD | 2027 H2 |

### v0.1 — Ward Pages

**Scope:**

* Tenancy foundation: central database, tenant registry, CLI provisioning, reference replicas, routing, outbox, isolation suite.
* Sourced ward and representative pages for the pilot tenant, in Nepali and English.
* Trust pages, SEO and sharing.
* Production infrastructure with per-database backups.
* "Report an error" as an email link.

**Exit gate (Gate A)** — `01` §4 gates 1–6, plus:

* every public fact has a verified source,
* the tenant isolation suite is green,
* a restore drill (central + one tenant) is done,
* the 5-person usability test has been run,
* and the operator has signed off.

**Day-by-day plan:**

| Day | Nova | Friend A (frontend) | Friend B (data) | Operator |
|---|---|---|---|---|
| Thu 17 Sep | Org + repo; governance questions | — | — | — |
| Fri 18 | Conventions | Next.js scaffold | Data sheet template | — |
| Sat 19 | Laravel scaffold | — | — | — |
| Sun 20 | Dev stack + DB roles | Locale routing | — | — |
| Mon 21 | VPS provisioning | Tokens, fonts, layout | — | — |
| Tue 22 | **Tenancy spike** | CI workflows | — | — |
| Wed 23 | **Tenancy foundation** | — | — | Palette neutrality check |
| Thu 24 | `admin_units` (central) | ProvenanceBadge, SourceSheet | — | — |
| Fri 25 | Geography tables; **pilot decision** | Seat components | — | G1/G2 answers |
| Sat 26 | Provenance schema (central + tenant) | — | — | — |
| Sun 27 | Domain + Cloudflare | — | Hierarchy + ward offices data | — |
| Tue 29 | OpenAPI pipeline; positions seed | — | — | — |
| Thu 1 Oct | Persons/parties (central), holdings (tenant) | API client | Office holders data | — |
| Fri 2 | **`hw:tenant:create`** + fixture tenants | Date/numeral utilities | — | — |
| Sat 3 | Reference data sync | — | — | — |
| Sun 4 | Tenant resolution middleware; **checkpoint** | — | — | — |
| Tue 6 | Geography API | — | Vacancy verification | — |
| Wed 7 | Outbox + public index; published paths | — | — | Trust page content |
| Thu 8 | Provenance resolver | Home / local level page | — | — |
| Fri 9 | Ward profile API | — | — | D-002 data review |
| Sat 10 | Person API | Ward page | — | — |
| Thu 15 | — | Person page; 404s | — | — |
| Fri 16 | Importer (central + tenant) | — | — | — |
| Sat 24 | Tenant isolation suite | — | — | — |
| Tue 27 | API image + compose | Web image | — | — |
| Wed 28 | Deploy with tenant migrations | Metadata | — | — |
| Thu 29 | Per-database backups; restore drill | OG images; sitemap | — | — |
| Fri 30 | Provision pilot tenant + import | Revalidation; share | — | Operator agreement draft |
| Sat 31 | Security baseline | Sentry/uptime; trust pages | — | — |
| Sun 1 Nov | Gate A review | — | Android QA | — |
| **Tue 3 Nov** | — | — | — | **Soft launch v0.1** |

### v0.2 — Citizen Accounts & Issue Reporting

**Scope:**

* Citizen accounts: register, verify email, sign in, password reset.
* Saved wards (permanent address, temporary address, workplace, other).
* My reports across municipalities; account deletion and export.
* Staff accounts with mandatory TOTP and per-tenant memberships; affiliations and recusals.
* Same-origin API with separate host-only sessions.
* Media pipeline; issue reporting for any published ward, with an operator setting to limit a local level (or the platform) to saved wards (D-012).
* Moderation with second approval; append-only audit in every database; per-tenant kill switch.

**Exit gate (Gate B)** — `01` §4 gates 7–13, plus:

* the security review covers tenant isolation and session separation,
* at least 2 moderators hold pilot memberships,
* the privacy notice for accounts is published,
* and the rota runs from the release hour.

**Dates:**

| Period | Nova | Friend A | Others |
|---|---|---|---|
| Wed 4 – Sat 7 Nov | Issue schema; staff memberships; same-origin proxy; host-based auth spike; citizen Fortify flows | Admin host routing | — |
| Sun 8 – Wed 11 Nov | **Tihar — no due dates** | — | — |
| Thu 12 – Mon 16 Nov | Staff 2FA; saved wards API; audit in all databases | Citizen auth screens; staff sign-in; account header | — |
| Tue 17 – Sun 22 Nov | Uploads; issue submission; image processing; R2 prefixes; affiliations | My wards screen; report flow; receipt | Operator: guidelines, privacy notice |
| Tue 24 – Sun 29 Nov | Moderation state machine; public issues; kill switch; My reports API; moderation API; account deletion | Issue list/page; My reports; queue screen | — |
| Tue 1 – Sat 5 Dec | Reporting scope setting; security review | Review screen; audit screen; restricted-scope states | Operator: onboarding · Friend B: QA + Gate B |
| **Tue 8 Dec** | — | — | **Release v0.2** |

### v0.3 — Map & Stabilization (W12–W13)

**Features:**

* Corrections workflow
* Ward boundaries
* Issue map
* Filters and content flags
* Staging environment (with two fixture tenants)
* Cookieless analytics
* Holiday moderation rota and SLA alerts
* Release

**Gate:** mobile performance budgets hold with the map; staging deploys include tenant migrations.

### v0.4 — Data Tools & Second Local Level (W14–W17)

**Features:**

* Staff data management UI
* Office history
* Second tenant onboarding
* Trust metrics
* Verification workflow
* Name search
* Source conflict UI
* Correction notes and appeals
* Community confirmations
* Slug redirects

**Gate:** the second tenant is provisioned and published **without code changes**; verification requires two people.

### v0.5 — Incumbent Promise Tracker (W18–W19)

**Features:** promise model, 2022 commitments with coverage parity, share cards, promise pages, citizen evidence submissions (citizens must be signed in).

**Gate:** coverage parity holds for every holder in each published tenant.

### v0.6 — Elections & Candidate Readiness (W20–W22)

**Features:** election model (central events, tenant contests and candidacies), nomination import, candidate directory and profiles, candidate portal, election information pages, code of conduct legal review.

**Gate:** a fictional nomination list imports into a fixture tenant and verifies in under 2 hours.

### v1.0 — Election MVP (W23–W24)

**Features:** candidate comparison, PWA and offline, optional citizen two-factor auth, load and performance audit (including a 10-tenant synthetic setup), security review, legal sign-off, launch kit.

**Gate:** PRD acceptance criteria 1–10 demonstrated. Criteria 11–12 are in v1.2.

### v1.1, v1.2, v2.0

As in `BACKLOG.md` §4.

---

## 5. Dependencies and blockers

| ID | Blocker | Owner | Needed by | Blocks |
|---|---|---|---|---|
| B1 | Pilot local level and wards (D-006) | Nova | **Fri 25 Sep** | All v0.1 data tasks |
| B2 | Operator G1 (registration) and G2 (conflicts of interest) | Operator | Fri 25 Sep | About page, Gate A |
| B3 | Domain registered by operator | Operator | Sat 26 Sep | Cloudflare, deploy |
| B4 | VPS, R2 and Sentry accounts (operator-owned, G4) | Operator + Nova | Mon 21 Sep | Infrastructure |
| B5 | Friend A and Friend B confirm ~15 h/week and skills | Nova | Tue 22 Sep | Task ownership |
| B6 | ~~Tenancy spike~~ **Resolved 22 Sep:** in-house tenancy layer (D-013) | Nova | Tue 22 Sep | — |
| B7 | Native Nepali review of UI copy, glossary and relationship labels | Operator/volunteer | Wed 28 Oct | Gate A |
| B8 | Transactional email provider (free tier) | Operator | Sat 7 Nov | Citizen email verification |
| B9 | Host-based Fortify guard spike passes (`12` §18 S2) | Nova | Fri 6 Nov | Citizen + staff auth |
| B10 | Legal review of account data and retention (G5) | Operator | Tue 1 Dec | Gate B |
| B11 | At least 2 moderators without conflicting affiliations | Operator | Thu 3 Dec | Gate B |
| B12 | Ward boundary source and licence | Friend B | W12 | v0.3 map |
| B13 | ECN 2027 election calendar | External | — | v1.1 plan |
| B14 | Funding + an extra contributor for AI | Operator | — | v1.2 |

---

## 6. Working process

### 6.1 Ceremonies

| Ceremony | When | Length | Output |
|---|---|---|---|
| Sprint planning | Tuesday | 30 min | Tasks moved to "Ready"; owners confirmed |
| Async stand-up | Daily, in chat | 3 lines per person | Yesterday / today / blocked |
| Demo + retro | Monday | 30 min | Demo; 1 improvement |
| Version planning | Release day | 60 min | Next version broken into tasks in the builder; backlog regenerated and re-imported |
| Moderation sync (v0.2+) | Weekly | 15 min | Queue health per tenant |

No ceremonies are held during Dashain (W05) or Tihar (Sun 8 – Wed 11 Nov).

### 6.2 Definition of Ready (task)

* It has an ID, parent feature, owner role, estimate of 4 h or less (the 5–6 h importer and report-flow tasks may be split at planning), acceptance criteria, and requirement IDs.
* Its dependencies are done or scheduled.

### 6.3 Definition of Done (task)

* Acceptance criteria are met.
* Tests are added or updated, and CI is green, **including the tenant isolation suite** once it exists.
* No new Larastan or ESLint errors.
* The PR references the task ID and was reviewed by another person.
* Docs are updated if behaviour, the API, the schema, tenancy placement or a decision changed.
* It is deployed to production (v0.1–v0.2) or staging (v0.3+).

### 6.4 Git workflow

* **Trunk-based development:** branches `type/HW-ID-slug`.
* **Conventional Commits:** `feat(tenancy): add tenant registry [HW-E29-F01-T02]`.
* **Squash merge to `main`.** Tag `vX.Y.Z` to release.
* **Board columns:** Backlog → Ready → In progress → In review → Done.

---

## 7. Setting up tracking

Regenerate first:

```bash
python3 tools/backlog/build_backlog.py
```

### 7.1 Option A — GitHub Issues + Projects (recommended)

1. Create a Project (v2) with a **Board** layout: Backlog / Ready / In progress / In review / Done.
2. Authenticate:
   ```bash
   gh auth login
   gh auth refresh -s project
   ```
3. Dry run, then import:
   ```bash
   python3 tools/backlog/github_import.py --repo ORG/hamro-ward --versions v0.1,v0.2
   python3 tools/backlog/github_import.py --repo ORG/hamro-ward --versions v0.1,v0.2 --apply \
     --project-owner ORG --project-number 1
   ```
4. Add project fields once: `Estimate` (number), `Due` (date), `Week` (single select W00–W24).
5. Views:
   * **This week** — `label:week-w01`, grouped by `role-*`
   * **Roadmap** — by milestone
   * **Epics** — `label:type-epic`

### 7.2 Option B — Jira Cloud

1. Create a Scrum, company-managed project (key `HW`) with Epic → Story → Sub-task, and versions `v0.1` … `v2.0`.
2. External system import → CSV → `jira_import.csv`.
3. Map the fields:

| CSV column | Jira field |
|---|---|
| Issue ID | Issue Id |
| Parent ID | Parent Id |
| Issue Type | Issue Type |
| Summary | Summary |
| Description | Description |
| Priority | Priority |
| Fix Version | Fix Version/s |
| Due Date | Due Date (`yyyy-MM-dd`) |
| Original Estimate | Original Estimate (seconds) `[verify in the importer preview]` |
| Labels (×6) | Labels |

4. Create sprints W00–W11 and bulk-move issues by `week-wNN` labels.

---

## 8. Development sessions with Claude

**One task (or a small group from the same feature) per session**, in backlog order.

1. Start with the task ID(s), for example "Implement `HW-E01-F02-T01`".
2. **Paste or attach the current state of every file the task touches.** Claude sees the project docs, not your repository.
3. Claude replies with the development-task format and **complete files**: migrations (central or tenant), tests, and doc updates.
4. Commit on the task branch, open a PR referencing the ID, merge, then start the next task with the updated files.

**Nova's coding order:**

```
HW-E01-F02-T01 → HW-E01-F03-T01 → HW-E29-F01-T01 → HW-E29-F01-T02 → HW-E03-F01-T01
→ HW-E03-F01-T02 → HW-E04-F01-T01 → HW-E05-F01-T01 → HW-E05-F01-T02 → HW-E29-F02-T01 …
```

**Friend A in parallel:**

```
HW-E01-F02-T02 → HW-E07-F01-T01 → HW-E07-F01-T02 → HW-E01-F04-T01 → HW-E07-F03-T01 …
```

---

## 9. Delivery risks

| Risk | Signal | Response |
|---|---|---|
| Tenancy package doesn't fit (version, PostgreSQL, FrankenPHP) | Spike fails Tue 22 Sep | Use schema-per-tenant or a thin in-house tenant connection switcher; re-estimate W01–W02 |
| Nova overload | Checkpoint Sun 4 Oct (§2.2) | v0.1 → Tue 17 Nov; later versions +2 weeks |
| Fortify two-guard switching doesn't work cleanly | Spike Fri 6 Nov | Fallback in `12` §11.2; +3 h |
| Fake accounts / brigading after v0.2 | Registration or report spikes per ward | Tighten limits, per-tenant kill switch, disposable-email blocklist |
| Operational load of many databases | Deploy migrations > 10 min, or backups miss window | `12` §17 triggers |
| Pilot data harder to source | More than 30% of seats still `not_verified` on Sat 31 Oct | Launch with honest "not yet verified" states |
| Festival capacity lower than modelled | W06 or W09 goals missed | Move the release one week; apply §2.3 cuts if the slip exceeds 2 weeks |
| Election calendar announced early | ECN notice | Apply §2.3 cuts |
