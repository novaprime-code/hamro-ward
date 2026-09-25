# Hamro Ward — 01 Constraints and Team

> **Purpose:** The real-world limits every architecture and scope decision must respect.
> **Status:** Draft v0.6
> **Date:** 2026-09-15
> **Feeds into:** `06_TECHNICAL_ARCHITECTURE.md`, `08_MVP_SCOPE_AND_ROADMAP.md`, `DECISIONS.md`

**Tags**

* `[STATED]` — decided by Nova
* `[DECIDED D-xxx]` — recorded in `DECISIONS.md`
* `[PROPOSED]` — awaiting approval
* `[ASSUMPTION]` — placeholder until confirmed
* `[ESTIMATE]` — rough planning number
* `[TO VERIFY]` — check before relying on it

---

## 1. Constraints at a glance

| Constraint | Value | Tag |
|---|---|---|
| Core team | Nova plus 1–2 developer friends | `[STATED]` |
| Wider volunteers | Student volunteers, including politically active students, expected after launch | `[STATED]` — rules in §8, `[DECIDED D-002]` |
| Weekly capacity | ~15 h/week per core member (Nova is doing an MSc and part-time work; friends work evenings/weekends) | `[ASSUMPTION]` |
| Monthly running budget | Under €20 for hosting and AI APIs, until funding arrives | `[STATED]` |
| Operator | A friend's company acts as the platform operator | `[DECIDED D-003]` — conditions in §7 |
| Launch target | Originally Tuesday 22 September 2026 | `[STATED]` — replanned (D-009, D-010, D-011): v0.1 Tue 3 Nov 2026, v0.2 Tue 8 Dec 2026 |
| Tenancy | Each local level is a tenant with its own database | `[STATED]` — D-010, `12` |
| Citizen accounts | Citizens sign in (Fortify + Sanctum cookies) and can report from several wards, e.g. permanent and temporary address | `[STATED]` — D-011, `12` |
| Pilot local level | **Not yet chosen.** Nova will decide. Itahari was the original recommendation | `[STATED]` — `[OPEN D-006]`; needed by Fri 25 Sep (`08` §5 B1) |
| Expected demand | High interest and traffic after launch | `[STATED]` — readiness plan in §6 |
| Stack | Next.js frontend + Laravel API | `[DECIDED D-001]` |
| Election context | Local terms expire 13 May 2027; the ECN has not yet announced the election date (see `02` §4.3) | `[SECONDARY]` |
| Lead's strongest stack | Laravel, PHP, React, Django | `[STATED]` (profile) |
| Friends' skills | Unknown | `[ASSUMPTION]` — general web development |
| Development location | Nova develops from Dresden, Germany; the operator and users are in Nepal | `[STATED]` |

---

## 2. Reality check: capacity vs scope

| Item | Figure | Tag |
|---|---|---|
| Capacity per week (3 people × ~15 h) | ~45 person-hours | `[ESTIMATE]` |
| Full MVP as defined in the PRD (14 modules, AI assistant, map, comparison, promise tracker, moderation, localization, hardening) | ~500–900 person-hours | `[ESTIMATE]` |
| Extra cost of two codebases vs a single app (API contract, auth between apps, admin UI built by hand) | +30–50% on v0 build effort | `[ESTIMATE]` |
| Pilot data collection (20 wards, incumbents, ward offices, sources) | ~15–25 person-hours | `[ESTIMATE]` |

**Conclusion.** The documented MVP cannot ship in a week. The work is delivered in phases:

* **v0 — Public beta.** Two milestones, one week apart (§3).
* **v1 — Election MVP.** Must be live before the 2027 nominations open. Internal target: **end of January 2027** `[ASSUMPTION]`, to be adjusted when the ECN publishes its calendar.
* **v2+** — Remaining modules, following the product lifecycle.

**Why v0 is not about candidates.** No 2027 local election candidates exist yet, because nominations haven't opened. What does exist today:

* the ward itself
* who currently represents it (the 2022 term)
* local problems

So v0 is an early form of lifecycle Stage 2, with an accountability focus.

---

## 3. v0 scope — two milestones `[PROPOSED]`

The launch week is split so the high-traffic first launch carries **no user-generated content**. That removes moderation load and legal exposure at the moment of peak attention.

### 3.1 Milestone A — Read-only launch, Tuesday 22 September 2026

| # | Feature | Minimum version |
|---|---|---|
| A1 | Ward discovery | Pilot local level landing page → its wards. **Pilot not yet chosen (D-006).** Only local levels marked `published` in the database appear publicly; no local level is named in code (`02` rules R1–R6) |
| A2 | Ward profile | Ward, local level, district, province, ward office contact (where sourced), current elected representatives, and a source label on every fact |
| A3 | Representative profile | Name, position, seat category, party/independent status as recorded by the ECN (2022), term, holding status (serving / vacant / not yet verified), sources |
| A4 | Sharing | Canonical URLs, OpenGraph metadata, and a generated social image per ward and representative |
| A5 | Localization | Nepali first, English second, using locale routes (`/ne/...`, `/en/...`) |
| A6 | Trust pages | About (naming the operator), Beta notice, how sources work, Privacy notice |
| A7 | Corrections | "Report an error" link on every page → operator email address (no form in Milestone A) |
| A8 | Data entry | Spreadsheet → validated, idempotent `artisan` CSV import command. **No admin CRUD UI in Milestone A** |

### 3.2 Milestone B — Participation, Tuesday 29 September 2026

| # | Feature | Minimum version |
|---|---|---|
| B1 | Issue reporting | Ward, category, title, description, optional photo, optional location (device location or text landmark), optional private contact |
| B2 | Photo pipeline | Queued job: validate, strip EXIF/GPS, re-encode, store in object storage |
| B3 | Moderation dashboard (Next.js) | Queue (pending / approved / rejected / needs_review / flagged), moderator notes, audit log. Staff login with 2FA |
| B4 | Public issue lists | Approved issues per ward, as lists with status badges (no map yet) |
| B5 | Corrections form | Replaces the email link; entries land in the moderation queue |
| B6 | Submission kill switch | Feature flag to pause new submissions instantly if the queue is overwhelmed or under attack |

### 3.3 Deferred beyond v0

| Feature | Earliest | Reason |
|---|---|---|
| Candidate directory, profiles, comparison | v1 | No candidates until nominations open |
| Promise tracker | v1 | Requires sourced 2022 commitments |
| Issue map (MapLibre) | v0.3, ~mid-October | Needs a tile source that fits the budget and tile-usage policies `[TO VERIFY]` |
| AI assistant | v1 | Needs an evidence corpus and funding |
| OCR pipeline | v1 | Includes Preeti → Unicode conversion (`02` §7.1) |
| Citizen accounts | v1 | Avoids SMS/OTP costs and personal data |
| Notifications, PWA/offline, realtime | v1+ | — |

---

## 4. Launch gates (not negotiable)

If a gate fails, the milestone moves. Dates are targets; gates are not.

### Milestone A gates

1. **Every published fact has at least one source record.** No source → not shown.
2. **Representatives use an identical format regardless of party.** Ordered by position, then ward; no party colours in the layout.
3. **The operator is named** on the About page, with a contact address and a conflict-of-interest statement (§7).
4. **Beta label and privacy notice** are live.
5. **Security baseline:** HTTPS only; security headers (CSP, HSTS); API rate limiting; no debug mode in production.
6. **Nightly database backup** off-server, with **one restore tested** before launch.

### Milestone B gates (in addition to A)

7. **No citizen report is public before moderator approval.** Anonymous submission is acceptable only because it is pre-moderated.
8. **Reporter contact details are never public** and are visible only to moderators.
9. **Photos are metadata-stripped and re-encoded server-side**, with type and size validated.
10. **Abuse protection:** CAPTCHA (e.g. Cloudflare Turnstile), per-IP rate limits, and a working kill switch (B6).
11. **Every staff account has 2FA.** No shared accounts; every moderation action is audit-logged.
12. **"Problems, not people."** Reports accusing a named individual are rejected in v0.
13. **Moderator roster meets the D-002 rules** (§8), with at least two queue checks per day.

---

## 5. Budget (under €20/month) `[PROPOSED]`

### 5.1 Allocation

| Item | Choice | Monthly | Tag |
|---|---|---|---|
| Server | One VPS (2 vCPU / 4 GB class) running Docker Compose: Caddy, Next.js (standalone build), Laravel (PHP-FPM), queue worker, scheduler, PostgreSQL | ~€5–10 | `[TO VERIFY]` prices; upgrade to 8 GB if memory is tight with Next.js + PostgreSQL |
| CDN / DNS / TLS / DDoS | Cloudflare free plan | €0 | `[TO VERIFY]` |
| Domain | `.np` / `.com.np`, registered by the operator | €0 | `[TO VERIFY]` eligibility |
| Object storage | Cloudflare R2 free tier (S3-compatible) | €0 within tier | `[TO VERIFY]` limits |
| CAPTCHA | Cloudflare Turnstile | €0 | |
| Error tracking | Sentry free tier | €0 | `[TO VERIFY]` |
| Uptime and analytics | Free uptime checker; cookieless analytics | €0 | `[TO VERIFY]` |
| AI APIs | None in v0 | €0 | |
| **Reserve** | Unallocated | ~€10 | |

**Why not Vercel for Next.js:** Vercel's free Hobby plan is restricted to non-commercial use. A company operator makes that a risk `[TO VERIFY]`. Self-hosting on the VPS keeps everything on one bill and avoids lock-in.

### 5.2 Budget rules

* **B1.** Every paid API gets a provider-level hard spending cap before its key reaches production.
* **B2.** Every free tier has a written exit path: what happens if it is exceeded or withdrawn.
* **B3.** No production component requiring a GPU or more than 4 GB RAM runs before funding arrives.
* **B4.** When funding arrives, spend it first on:
  1. data collection and verification capacity
  2. legal review
  3. infrastructure

---

## 6. Architecture implications

### 6.1 Decided

**D-001 — Next.js frontend + Laravel API**, as specified in project instructions §10. The earlier proposal for a single Laravel + Filament app was rejected.

Consequences for v0:

* **Versioned REST API from day one:** `/api/v1`, documented with OpenAPI, and consumed by Next.js through a generated typed client.
* **Business logic lives in Laravel domain actions/services, not controllers.**
* **Public pages are statically generated or revalidated** (ISR) in Next.js and cached at the edge, so a page view doesn't reach Laravel.
* **The staff/moderation UI lives in the Next.js codebase**, served on a separate subdomain:
  * staff host set to `noindex`
  * no edge caching
  * stricter CSP
* **Staff authentication uses Laravel Sanctum** (cookie-based SPA auth across subdomains of one domain), with Laravel Fortify for TOTP 2FA.

### 6.2 Proposed simplifications (pending approval)

None of these removes the option to adopt the original component later.

| # | Instructions §10 | Proposed for v0/v1 | Why | Revisit when |
|---|---|---|---|---|
| P3 | Meilisearch | PostgreSQL full-text search + `pg_trgm` with Nepali and romanized aliases | One less service; 20 wards don't need a search engine | Nationwide data, or search quality/latency problems |
| P4 | Redis | Laravel database queue driver; file/database cache | One less service; low job volume | Queue backlog or cache pressure |
| P5 | Laravel Reverb | Not used | No realtime requirement | A realtime feature is approved |
| P6 | Self-managed S3-compatible storage | Cloudflare R2 via Laravel's S3 driver | S3-compatible (no lock-in); free tier | Cost or limits |
| P7 | Ollama + vector DB | AI deferred to v1. When built: external API with a hard cap, and **pgvector** in PostgreSQL | Budget rule B3 | Funding arrives |
| P8 | Tesseract OCR | Deferred to v1 | Not needed for v0 content | v1 document ingestion begins |
| P9 | Docker, Compose, GitHub Actions, reverse proxy | **Kept** | Fits the constraints | — |

### 6.3 Readiness for launch hype

| Pressure | Plan |
|---|---|
| Traffic spikes on shared links | Static or ISR pages cached at Cloudflare; the API is only hit on revalidation. Generated OG images are cached too |
| Crawler and bot load | API rate limits; Cloudflare bot protection; cache-friendly URLs |
| Submission floods (Milestone B) | CAPTCHA, per-IP limits, and the kill switch (B6) |
| **Moderation backlog — the real bottleneck** | Recruit and train moderators **before** public promotion. Queue triage by category. Publish the expected moderation time honestly on the submission page |
| VPS saturation | Vertical upgrade (4 GB → 8 GB) is one reboot; documented in the runbook |

---

## 7. Operator and governance — D-003

A friend's company acts as the platform operator: the organization responsible for Hamro Ward publicly and legally. This helps with credibility, receiving funds, legal accountability and data protection. It also means the company's reputation and neutrality become the platform's.

### 7.1 Conditions to settle before Milestone A

| # | Condition | Why | Tag |
|---|---|---|---|
| G1 | **Confirm the company is registered in Nepal**, and that its registered objectives allow operating an online information platform | Makes "operated from Nepal" true in legal terms | `[TO VERIFY]` |
| G2 | **Conflict-of-interest disclosure:** any work for political parties, candidates, the pilot local level (D-006) or other government bodies, and any owner/director political roles. A summary goes on the About page | Neutrality principle; an undisclosed tie discovered later would be very damaging | `[TO VERIFY]` |
| G3 | **Short written agreement** between the operator and the core team covering the items in 7.2 | Prevents disputes once the project has traction | `[PROPOSED]` |
| G4 | **Accounts owned by the operator**, with at least two administrators each: domain, Cloudflare, hosting, GitHub organization, email | No single point of failure or lock-out | `[PROPOSED]` |
| G5 | **Legal questions for a Nepali lawyer:** defamation exposure from user reports; Individual Privacy Act, 2075 obligations; whether any platform registration rules apply to a site with user submissions; any data residency requirement; whether hosting outside Nepal is acceptable | Pre-launch risk reduction | `[TO VERIFY]` |
| G6 | **Nova's role and data access:** production access to personal data (reporter contact details) is done on the operator's instructions and kept minimal. Ask whether EU data protection law touches Nova's activities in Germany | Operator-in-Nepal reduces, but may not fully remove, EU exposure | `[TO VERIFY]` |

### 7.2 Agreement contents (G3)

* **Roles:** operator (legal responsibility, publication decisions, funding) vs core team (build, run, data).
* **Editorial independence:** the operator's commercial or personal interests do not influence content about candidates, parties or officials, and neutrality rules override business interests.
* **Code ownership and licence:** who owns the repository, and whether it will be open source `[ASSUMPTION: undecided]`.
* **Data ownership:** what happens to the data, domain and accounts if the operator withdraws. A continuity clause lets the project move to another operator.
* **Funding transparency:** sources of funding are disclosed publicly.

---

## 8. Team and volunteers

### 8.1 Core team roles `[PROPOSED]`

| Person | Launch-week focus | Secondary |
|---|---|---|
| Nova | Tech lead: Laravel data model (`02` §9), `/api/v1`, CSV importer, deployment | Final approval on published data |
| Friend A | Next.js public site: ward and representative pages, i18n, OG images | Mobile QA on low-end Android |
| Friend B (if available) | **Data collection and verification:** 20 wards, representatives, vacancies, sources | Moderation lead from Milestone B |
| Operator contact | Legal/governance items G1–G6, About page statement, correction emails | Signs off public launch |

### 8.2 Volunteer rules — D-002

Many student organizations in Nepal are affiliated with political parties. Volunteer help is welcome; these rules protect the platform's neutrality and the volunteers themselves.

1. **Every volunteer declares any political affiliation** (party, student union, campaign role) privately to the operator. This is kept confidential and used only for role assignment.
2. **Open to everyone regardless of affiliation:**
   * outreach and awareness
   * collecting official documents and notices
   * photographing ward office notice boards
   * translation
   * field checks of reported issues
3. **Restricted roles:** verification of facts about candidates, officials or parties, and moderation decisions.
   * These are assigned to people without an active affiliation where possible.
   * Otherwise, any verification or approval by an affiliated person needs confirmation by someone of a different affiliation or no affiliation.
4. **No volunteer moderates or verifies content about their own party, candidates, or relatives.**
5. **Every verification and moderation action is audit-logged** with the acting account. Accounts are personal, never shared.
6. **Volunteers do not represent Hamro Ward publicly**, and do not use Hamro Ward branding in campaign activity.
7. **Breaking these rules** means immediate removal of access, and a review of that person's past actions.

---

## 9. Launch plan — superseded

The launch-week plan that was here (start Tue 15 Sep, launch Tue 22 Sep) is **superseded by `08_ROADMAP_AND_DELIVERY_PLAN.md`** (17 Sep 2026). Reasons: work started 17 Sep, the pilot local level is still open (D-006), and the task-level estimate for v0.1 is 109 h.

Current dates (normal pace D-009, with tenancy and citizen accounts D-010/D-011): **v0.1 Tue 3 Nov 2026**; **v0.2 Tue 8 Dec 2026**; v1.0 Tue 9 Mar 2027. Day-by-day tasks are in `BACKLOG.md`.

---

## 10. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Incorrect representative data (vacancies after the 2026 resignations; name errors) | High | High | Sourced records only; "not yet verified" state; corrections channel |
| Neutrality compromised through affiliated volunteers | Medium | High | D-002 rules; audit log; cross-affiliation confirmation |
| Operator conflict of interest discovered later | Low–Medium | High | G2 disclosure before launch; editorial independence clause |
| Moderation backlog after hype | High | High | Milestone split; recruit moderators before promotion; kill switch |
| Defamation or legal complaints | Medium | High | Pre-moderation; "problems, not people"; G5 legal review |
| Data protection obligations (Nepal; possible EU exposure through development from Germany) | Medium | Medium | Minimal data; no accounts; G5–G6 legal review |
| Two-codebase overhead delays milestones | High | Medium | Admin UI deferred to Milestone B; CSV import instead of CRUD; strict scope |
| Burnout of a part-time team | High | High | Phased milestones; no promotion push before moderation is stable |
| Free tier changes / overages | Low | Medium | Budget rule B2 exit paths |

---

## 11. Open items

1. **Pilot local level and wards (D-006)** — blocks data collection; needed by Fri 25 Sep
2. Operator details: company name, Nepal registration (G1), conflict-of-interest answers (G2)
3. Weekly hours per core member; friends' skills; whether Friend B exists
4. Approval of the two-milestone plan (§3) and simplifications P3–P8 (§6.2)
5. Domain name and registrant (operator)
6. Code licence: open source or not
7. v1 internal target date (assumed end of January 2027)

---

## Change log

| Version | Date | Change |
|---|---|---|
| 0.1 | 2026-09-15 | Initial draft: constraints, capacity check, v0/v1 split, launch gates, budget, proposed stack deviations, roles, launch-week plan, risks |
| 0.6 | 2026-09-17 | Tenancy (D-010) and citizen accounts (D-011) added to constraints; dates updated |
| 0.5 | 2026-09-17 | Normal pace adopted (D-009); pilot decision deadline Fri 25 Sep |
| 0.4 | 2026-09-17 | §9 launch plan superseded by `08`; pilot decision deadline moved to Mon 21 Sep |
| 0.3 | 2026-09-15 | Pilot local level reopened (D-006): no longer assumed to be Itahari; `published` flag gates public visibility; decision deadline Thu 17 Sep added to the launch plan |
| 0.2 | 2026-09-15 | D-001 Next.js + Laravel (single-app proposal P1 rejected; P2 replaced by a versioned API). D-002 volunteer rules. D-003 friend's company as operator, with governance conditions G1–G6. v0 split into Milestone A (22 Sep, read-only) and Milestone B (29 Sep, participation). Added hype-readiness plan. Removed GDPR as a primary risk; retained a narrower data-protection item |
