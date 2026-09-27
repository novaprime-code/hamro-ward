# Hamro Ward

> तपाईंको वडा। तपाईंको जानकारी। तपाईंको आवाज।
> Your ward. Your information. Your voice.

A neutral civic-information platform for Nepal, organised around local levels and
wards. It exists to make it dramatically easier for an ordinary citizen to find
out who represents their ward, what has been promised, what is actually known,
and what is not — and to say so honestly when the answer is "we don't know yet".

**It is not** a voting guide, a ranking, or a place that tells anyone who to vote
for. The platform presents evidence with its provenance and lets citizens decide.
See [`docs/00_PROJECT_CONTEXT.md`](docs/00_PROJECT_CONTEXT.md) §2 and the
neutrality requirements `NFR-NEU-01`–`04` in [`docs/03_SRS.md`](docs/03_SRS.md).

---

## Status

| | |
|---|---|
| Version in progress | **v0.1 — Ward Pages** |
| Staging | live, with the demonstration dataset seeded |
| Production | not yet provisioned |
| Real data | none — every municipality currently visible is invented |

The four municipalities on staging (Himtara, Koshara, Sonapur, Sainli) are
fictional, inside real provinces and districts. That is deliberate and is
documented in [`docs/13_DEMO_DATASET.md`](docs/13_DEMO_DATASET.md). Nothing in
the codebase names a real local level: `D-006` is still open and the pilot is
undecided.

Current state of the running system, and what to pick up next:
[`docs/14_CURRENT_STATE.md`](docs/14_CURRENT_STATE.md).

---

## What's in here

```
apps/
  api/              Laravel 11 — modular monolith, central + per-tenant databases
  web/              Next.js 15 (App Router, RSC) — public site and, later, staff
packages/
  api-client/       generated TypeScript client
infra/
  postgres/         one-time server scripts: roles, template, central databases
  stacks/           Portainer compose files, one directory per environment
  docker/           local development images
docs/               numbered specifications, decisions, changelog
tools/backlog/      the backlog is generated from build_backlog.py, not edited
```

Architecture in one line: **a modular monolith in Laravel, one PostgreSQL
database per municipality plus one central database, rendered by a Next.js app
that talks to it over a versioned REST API.**

The shape of that and why: [`docs/06_TECHNICAL_ARCHITECTURE.md`](docs/06_TECHNICAL_ARCHITECTURE.md)
and [`docs/12_TENANCY_AND_IDENTITY.md`](docs/12_TENANCY_AND_IDENTITY.md).

---

## Getting started

* **Working on the code** → [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)
* **Setting up a server** → [`docs/SETUP.md`](docs/SETUP.md)
* **Understanding a decision** → [`DECISIONS.md`](DECISIONS.md), newest last
* **What changed and when** → [`CHANGELOG.md`](CHANGELOG.md)

Shortest useful path from a clean checkout:

```bash
make up                                   # PostgreSQL + PostGIS, Mailpit
cd apps/api && php artisan migrate --database=central_owner \
    --path=database/migrations/central
php artisan db:seed --force               # source types, positions catalogue
php artisan hw:demo:seed                  # four demonstration municipalities
cd ../web && pnpm dev
```

---

## The four rules that shape most of the code

Everything else is detail. These are the ones that will surprise you if you
don't know them.

**1. No local level is ever named in code.** Not in routes, not in config
defaults, not in a constant. Municipalities and wards live in data with a
`published` flag. `D-006` depends on this being true.

**2. Every important claim carries its provenance.** Eight source types with
authority ranks, `source_links` recording which source asserts which field, and
`fact_conflicts` preserving both sides when sources disagree. A candidate's
statement is never stored as if it were an independently verified fact.

**3. "We don't know" is a first-class answer.** A seat is `held`, `vacant` or
`not_verified`, and all three render with equal care. A seat is never omitted
because it is unconfirmed: a page showing three of seven representatives tells a
citizen something false about their own ward.

**4. Identical templates for everyone.** No party colours, no ordering
advantage, no emphasis that depends on who a person is (`NFR-NEU-01`,
`NFR-NEU-02`). The brand palette is checked against major party colours before
launch (`NFR-NEU-04`).

---

## Language

Nepali is the primary language and English the secondary one. Devanagari is not
an afterthought: body text is 17px with 1.65 line height because matras and
conjuncts need the room (`NFR-ACC-02`), numbers render in Devanagari digits in
Nepali content, and BS dates appear alongside AD where people expect them.

UI copy currently in the repository is a draft pending review by a native
speaker — blocker **B7** in [`docs/08_ROADMAP_AND_DELIVERY_PLAN.md`](docs/08_ROADMAP_AND_DELIVERY_PLAN.md).

---

## Contributing

Branches are `type/HW-ID-slug`; commits follow Conventional Commits. Every pull
request states its task ID, the requirement IDs it satisfies, and test evidence.
CI must be green to merge.

If you are politically active, declare it privately before doing verification or
moderation work. The rules are in `D-002` and they exist to protect the
platform's neutrality, not to exclude anyone.

---

## Licence and operator

The platform operator is named on the About page with a conflict-of-interest
statement (`D-003`). Licence to be decided before the first public release.