# Contributing

## Work items

Every change belongs to a backlog task from `docs/BACKLOG.md`, for example `HW-E03-F01-T01`.

* **Branch:** `type/HW-ID-short-slug` → `feat/HW-E03-F01-T01-admin-units`
* **Types:** `feat`, `fix`, `chore`, `docs`, `test`, `refactor`, `perf`, `ci`
* **Commits (Conventional Commits):** `feat(geography): add admin_units hierarchy [HW-E03-F01-T01]`
* **Merge:** squash into `main`. Release with a `vX.Y.Z` tag.

## Definition of Done

- [ ] Acceptance criteria in the task are met
- [ ] Tests added or updated; `make test` and `make lint` pass
- [ ] Docs updated when behaviour, API, schema, tenancy placement or a decision changed
- [ ] PR reviewed by another person and linked to the task

## Rules that are not negotiable

1. **No local level, ward number, party or person name in code.** They live in data (`docs/02` R1).
2. **Public facts need a verified source link** before they render (`docs/03` FR-SRC-02).
3. **Tenant data never leaks.** Models declare their connection; the isolation suite must stay green (`docs/12` §16).
4. **No political ranking, scoring or persuasion** anywhere in code or copy.
5. **Personal data is minimised.** No street addresses, no caste, ethnicity or religion columns, ever.

## Code style

* PHP: Pint (Laravel preset, strict types), Larastan level 6. Controllers validate, call one action or query, return a resource.
* TypeScript: ESLint, `tsc --noEmit`, no `any` without a comment explaining why.
* Copy: Nepali written first, English second. Never machine-translate UI strings.

## Reviews

Be direct about correctness, security and privacy; kind about everything else. Anyone may block a merge that breaks one of the five rules above.
