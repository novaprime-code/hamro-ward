# Hamro Ward — 03 Software Requirements Specification (SRS)

> **Status:** Draft v0.4
> **Date:** 2026-09-17
> **Structure:** Loosely follows ISO/IEC/IEEE 29148
> **Inputs:** `00_PROJECT_CONTEXT.md`, the PRD, the project instructions, `01`, `02`, `DECISIONS.md`
> **Feeds into:** `05` (data model), `06` (architecture), `08` (plan), `09` (UX/UI), and the backlog (`BACKLOG.md`)

Requirement statements use **shall** (mandatory), **should** (recommended) and **may** (optional).

Every requirement has:

* **an ID** — `FR-<MODULE>-nn` for functional requirements, `NFR-<AREA>-nn` for non-functional ones
* **a priority** — `M` (Must), `S` (Should) or `C` (Could)
* **a target version**

Backlog items reference these IDs.

---

## 1. Introduction

### 1.1 Purpose

This document defines testable requirements for Hamro Ward, from the first public beta (v0.1) through the election MVP (v1.0), with later requirements listed at a lower level of detail.

### 1.2 Scope

Hamro Ward is a neutral, mobile-first civic information platform for Nepal, organized around local levels and wards.

**In scope up to v1.0:**

* administrative discovery
* local-level tenancy with a separate database per local level (D-010)
* citizen accounts with multiple saved wards (D-011)
* ward profiles
* current office holders
* provenance and sources
* issue reporting and moderation
* corrections
* issue map
* promise tracker
* elections and candidates
* candidate comparison
* AI assistant (evidence-grounded)
* sharing
* Nepali/English localization
* PWA

**Out of scope** (PRD §5 non-goals):

* campaign management
* political advertising
* voter targeting
* persuasion
* betting
* candidate ranking
* automated accusations or endorsements
* unrestricted anonymous posting

### 1.3 Definitions

| Term | Meaning |
|---|---|
| Local level | Metropolitan city, sub-metropolitan city, municipality, or rural municipality (`02` §2.2) |
| Pilot local level | The first published local level. Not yet chosen (D-006) |
| Published | An administrative unit or record visible to the public |
| Provenance type | One of the 8 trust categories: official, candidate-submitted, public-record, verified community report, community report, news/media report, AI-generated summary, unverified claim |
| Source authority | Rank of the source type in the source hierarchy (instructions §4) |
| Verified source link | A source link a verifier has confirmed actually supports the stated fact |
| Office holding | A person holding a position for a constituency over a period |
| Contest | A position × constituency within one election |
| Moderation state | pending, approved, rejected, needs_review, flagged, archived |
| Issue lifecycle status | open, community_confirmed, reported_to_authority, acknowledged, in_progress, resolved |
| Tenant | One local level with its own database (D-010, `12`) |
| Central database | Database holding identity, national reference data, registries and cross-tenant indexes (`12` §3) |
| Citizen account | A registered member of the public who can report issues and save wards (D-011) |
| Saved ward | A ward linked to a citizen account with a relationship: permanent address, temporary address, workplace, or other |
| Staff | Operator admins, moderators, verifiers and data editors. Staff accounts are separate from citizen accounts |
| Restricted action | Verifying facts about people, offices, parties or candidates, and moderating politically sensitive content (D-002) |

### 1.4 References

* `02_NEPAL_CIVIC_DOMAIN.md` — domain rules R1–R16
* `DECISIONS.md` — D-001 to D-007
* OWASP ASVS 4.x
* WCAG 2.2

---

## 2. Overall description

### 2.1 Product perspective

A standalone web platform:

* a Next.js public site and staff dashboard (D-001)
* a Laravel API
* PostgreSQL/PostGIS
* object storage
* Cloudflare at the edge

Hamro Ward has no integration with Election Commission Nepal (ECN) systems. Official data enters through manual collection and validated import.

### 2.2 User classes

| Class | Description | Access |
|---|---|---|
| Visitor | Anonymous and mobile-first. Often on low bandwidth, and may be new to civic terminology | Public pages; correction requests |
| Citizen account holder | Registered visitor with a verified email; may live, work or have a permanent address in different local levels | Issue reporting in any published ward, saved wards, My reports (v0.2) |
| Moderator | Reviews submissions | Staff dashboard; moderation queue |
| Verifier | Verifies facts and sources | Staff dashboard; data verification (v0.4) |
| Data editor | Enters and imports data | Import tooling (v0.1); data management UI (v0.4) |
| Operator admin | Operator's responsible staff | Staff management, affiliation declarations, settings, audit |
| System admin | Core technical team | Infrastructure; no editorial rights unless also given a staff role |
| Candidate (v0.6+) | Submits own information after identity verification | Candidate portal |
| Journalist / researcher | Reads and shares | Public pages; exports (v2.0) |

### 2.3 Operating environment

* **Clients:** Android Chrome and WebView (versions from the last 3 years), iOS Safari 16+, and desktop evergreen browsers. Reference device: low-end Android with 2–3 GB RAM on 3G/4G.
* **Server:** a single Linux VPS running Docker Compose, behind Cloudflare (`06` §20).

### 2.4 Design and implementation constraints

* Next.js + Laravel (D-001); modular monolith.
* One tenant (separate PostgreSQL database) per onboarded local level, plus a central database (D-010).
* Laravel Fortify + Sanctum SPA cookie authentication, same-origin API (D-011).
* Monthly running cost below €20 until funding arrives (`01` §5).
* No local names, ward numbers, or election data in application code (R1).
* Nepali is the primary language (instructions §16).
* The operator is a Nepal-based company (D-003).

### 2.5 Assumptions and dependencies

| ID | Assumption / dependency | Blocks |
|---|---|---|
| A-01 | Pilot local level chosen by Fri 25 Sep 2026 (D-006) | v0.1 data |
| A-02 | Operator answers on registration (G1) and conflicts of interest (G2) by Fri 25 Sep 2026 | v0.1 About page |
| A-03 | The ECN's 2022 local election results are accessible for the pilot | v0.1 office holders |
| A-04 | Funding is available for LLM API usage by v1.2 | v1.2 AI assistant |
| A-05 | The ECN publishes the 2027 local election calendar before v1.0 | v1.1 plan |

---

## 3. Functional requirements

### 3.1 Geography and discovery (GEO)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-GEO-01 | The system shall let a citizen browse published units: Province → District → Local level → Ward | M | v0.1 |
| FR-GEO-02 | When only one local level is published, the home page shall link directly to it | M | v0.1 |
| FR-GEO-03 | Every published unit shall be reachable by a stable slug URL (for example `/{locale}/ward/{province}/{district}/{local-level}/{n}`) | M | v0.1 |
| FR-GEO-04 | A request for an unpublished or unknown unit shall return HTTP 404 and reveal nothing about unpublished data | M | v0.1 |
| FR-GEO-05 | A renamed slug shall permanently redirect (301) to the current slug | M | v0.4 |
| FR-GEO-06 | Administrative units shall be temporally versioned. Historical units remain stored, are not shown as current, and are linked to their successors (R3) | M | v0.1 (data), v0.4 (UI) |
| FR-GEO-07 | Search shall match Devanagari, romanized, legacy and misspelled names, including mixed queries | S | v0.4 |
| FR-GEO-08 | The system may offer location-assisted discovery ("find my ward") | C | v2.0 |

### 3.2 Provenance and sources (SRC)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-SRC-01 | Every public factual record, and every factual field that can differ between sources, shall carry a provenance type and at least one source link | M | v0.1 |
| FR-SRC-02 | A factual record or field without a verified source link shall not be shown publicly. The UI shows a "not yet verified" state instead of a value | M | v0.1 |
| FR-SRC-03 | Each fact shall expose its sources in a source sheet showing: title, publisher, source type, provenance type, published date, retrieved date, link, verification status | M | v0.1 |
| FR-SRC-04 | Provenance types shall be visually distinguished with an icon plus a text label (never colour alone) and never mixed silently | M | v0.1 |
| FR-SRC-05 | Source authority ranks shall be configuration data, not code | M | v0.1 |
| FR-SRC-06 | When sources conflict, the system shall keep all of them, record a conflict, and show both values with authority and recency explained until an editor resolves it (R16) | M | v0.1 (data), v0.4 (UI) |
| FR-SRC-07 | The system should store a snapshot or archive reference of web sources at retrieval time | S | v0.4 |
| FR-SRC-08 | Verification of restricted facts shall require a second approver who is not the first verifier and has no conflicting affiliation (D-002) | M | v0.4 |

### 3.3 Offices and representatives (OFF)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-OFF-01 | The ward page shall list the ward's directly elected seats in **ballot order**: ward chair, woman member, Dalit woman member, open members | M | v0.1 |
| FR-OFF-02 | The ward page shall also list local-level-wide seats (mayor/chair and deputy/vice-chair), clearly labelled as covering the whole local level | M | v0.1 |
| FR-OFF-03 | Each seat shall show exactly one of three states: current holder, **Vacant** (sourced), or **Not yet verified** | M | v0.1 |
| FR-OFF-04 | The person page shall show names (Nepali/English), positions held, constituency, term dates, and party or independent status as officially recorded, all with sources | M | v0.1 |
| FR-OFF-05 | The system shall not store or display caste, ethnicity, religion or other personal attributes. Only the official seat category is shown (R9) | M | v0.1 |
| FR-OFF-06 | All seats shall use the same template regardless of party. Ordering is by position, then seat, never by party | M | v0.1 |
| FR-OFF-07 | Office holding history (previous holders, early departures with reasons) should be displayed | S | v0.4 |
| FR-OFF-08 | Indirectly elected executive members should be shown under "Who governs this local level" | S | v0.4 |

### 3.4 Issues (ISS)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-ISS-01 | A signed-in citizen with a verified email shall be able to submit an issue for **any published ward**: ward, category, title (≤120 chars), description (≤2,000 chars), 0–3 photos, optional location (device location or landmark text), language | M | v0.2 |
| FR-ISS-02 | Categories shall be data-driven and seeded with the PRD §3.5 list | M | v0.2 |
| FR-ISS-03 | Submission shall be rate-limited per user, per user per ward, and per anonymized client fingerprint (`12` §12.3) | M | v0.2 |
| FR-ISS-04 | New issues shall enter moderation state `pending`. The submitter receives a tracking code and the expected review time | M | v0.2 |
| FR-ISS-05 | Only `approved` issues shall be publicly visible | M | v0.2 |
| FR-ISS-06 | Reporter identity (account, email, relationship to the ward) shall never be included in public responses. Moderators see display name and relationship; email only through an audited reveal | M | v0.2 |
| FR-ISS-07 | Moderation state and lifecycle status shall be independent fields (`05` §6) | M | v0.2 |
| FR-ISS-08 | Lifecycle status changes shall be recorded with actor, time, note and optional source, and shown as a public timeline | M | v0.2 |
| FR-ISS-09 | Exact locations shall be displayed publicly only if the reporter opted in. Otherwise the location is shown approximately (rounded to about 100 m) or as the ward only | M | v0.2 |
| FR-ISS-10 | Operator admins shall be able to pause new submissions instantly (kill switch). The form then shows a clear notice | M | v0.2 |
| FR-ISS-11 | The public issue list shall support filtering by category and lifecycle status | S | v0.3 |
| FR-ISS-12 | Issues shall be shown on a ward map with clustering | M | v0.3 |
| FR-ISS-13 | A citizen shall be able to flag public content with a reason | M | v0.3 |
| FR-ISS-14 | Citizens should be able to confirm an issue ("I see this too"), with abuse limits | S | v0.4 |
| FR-ISS-15 | A submitter should be able to appeal a rejection using their tracking code | S | v0.4 |

### 3.5 Media (MED)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-MED-01 | Uploads shall accept only JPEG, PNG, WebP and HEIC, up to 10 MB and 8,000 × 8,000 px, validated by file content (magic bytes), not by extension | M | v0.2 |
| FR-MED-02 | The server shall decode and re-encode every image, strip all metadata (EXIF, GPS, XMP), generate display sizes, and delete the original file after processing | M | v0.2 |
| FR-MED-03 | Media shall remain private until its parent record is approved | M | v0.2 |
| FR-MED-04 | The client should downscale photos before upload, to at most 2,000 px on the long edge | S | v0.2 |

### 3.6 Moderation and audit (MOD, AUD)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-MOD-01 | Staff shall have a moderation queue filterable by state, type, ward and age | M | v0.2 |
| FR-MOD-02 | Every moderation decision shall record a reason code, an optional note, the actor, and the time | M | v0.2 |
| FR-MOD-03 | Staff shall not act on items covered by their declared recusals, and the system shall enforce this (D-002) | M | v0.2 |
| FR-MOD-04 | Restricted moderation actions shall require a second approver (D-002) | M | v0.2 |
| FR-MOD-05 | The queue shall show time since submission against the target turnaround | S | v0.2 |
| FR-MOD-06 | Rejection reasons shall include at least: accuses a named person, personal data, spam, duplicate, outside ward scope, unsafe content, not a civic issue | M | v0.2 |
| FR-AUD-01 | Every staff action and every data change shall create an append-only audit event recording actor, action, subject, before/after values, time, and request ID | M | v0.1 (imports), v0.2 (all) |
| FR-AUD-02 | The application database role shall be unable to update or delete audit events | M | v0.2 |
| FR-AUD-03 | Operator admins shall be able to view and filter the audit log | M | v0.2 |

### 3.7 Corrections (COR)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-COR-01 | Every public page shall offer a "Report an error" action (an email link in v0.1–v0.2; a form from v0.3) | M | v0.1 |
| FR-COR-02 | Correction requests shall enter the moderation queue linked to the page and record | M | v0.3 |
| FR-COR-03 | Corrected records should show a public "corrected on" note with a summary | S | v0.4 |

### 3.8 Staff access (STF)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-STF-01 | Staff authentication shall use email and password plus a mandatory TOTP second factor, with recovery codes | M | v0.2 |
| FR-STF-02 | Access shall combine a global `operator_admin` role with **per-tenant memberships** (`moderator`, `verifier`, `data_editor`, `viewer`). A membership in one local level grants nothing in another. Policies check every endpoint | M | v0.2 |
| FR-STF-03 | A staff member shall complete an affiliation declaration before receiving the moderator or verifier role (D-002) | M | v0.2 |
| FR-STF-04 | Accounts shall lock after repeated failed logins, sessions shall expire after inactivity, and deactivated accounts shall lose access immediately | M | v0.2 |
| FR-STF-05 | Staff pages shall never be indexed or cached at the edge | M | v0.2 |

### 3.9 Sharing and SEO (SHR)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-SHR-01 | Every public entity shall have a canonical URL per locale, with `hreflang` alternates | M | v0.1 |
| FR-SHR-02 | Every public page shall have a localized OpenGraph title, description and image | M | v0.1 |
| FR-SHR-03 | Share images shall render Devanagari conjuncts and vowel signs correctly | M | v0.1 |
| FR-SHR-04 | A share action shall use the Web Share API where available, with copy-link as the fallback | M | v0.1 |
| FR-SHR-05 | The system shall publish a sitemap of published pages and a `robots.txt` excluding staff and API hosts | M | v0.1 |
| FR-SHR-06 | Pages should include JSON-LD structured data where appropriate (`06` §17) | S | v0.1 |

### 3.10 Localization (L10N)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-L10N-01 | Nepali shall be the default locale and English the secondary. The architecture shall support adding locales without code changes to domain logic | M | v0.1 |
| FR-L10N-02 | Dates shall be displayed in BS, with AD available. BS conversion shall use the validated backend lookup table (R14) | M | v0.1 |
| FR-L10N-03 | Numbers in Nepali-locale content shall use Devanagari digits. Phone numbers, codes and URLs keep Latin digits | M | v0.1 |
| FR-L10N-04 | Currency shall be formatted with lakh/crore grouping | S | v0.5 |
| FR-L10N-05 | When a translation is missing, the page shall show the available language with a visible "(Nepali only)" or "(English only)" marker | M | v0.1 |

### 3.11 Trust pages (TRU)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-TRU-01 | The site shall publish an About page naming the operator, funding sources, a conflict-of-interest statement and the neutrality commitments | M | v0.1 |
| FR-TRU-02 | The site shall publish "How sources work", "Privacy notice" and "Beta" pages | M | v0.1 |

### 3.12 Promises (PRM)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-PRM-01 | A promise shall record: person, election, text (in the original language), category, date made, sources, status, and status history | M | v0.5 |
| FR-PRM-02 | Status values shall be `proposed`, `not_started`, `planning`, `in_progress`, `completed`, `delayed`, `cancelled`, `disputed`. Every change requires a source or evidence note and is audited | M | v0.5 |
| FR-PRM-03 | Promises of candidates who were not elected shall be kept with tracking state `not_tracked_not_elected` and excluded from delivery statistics | M | v0.5 |
| FR-PRM-04 | **Coverage parity:** a local level's promise pages shall be published only after collection has been attempted for every office holder there. Holders with no documented promises show "No documented promises found", with the collection date | M | v0.5 |
| FR-PRM-05 | Citizens should be able to submit evidence for a promise, which goes through moderation | S | v0.5 |

### 3.13 Elections and candidates (ELE)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-ELE-01 | Elections, contests and candidacies shall be modelled per R7 and R8. A contest's constituency is a ward or a whole local level | M | v0.6 |
| FR-ELE-02 | Election dates and deadlines shall be displayed only from sourced ECN records, with the publication date shown | M | v0.6 |
| FR-ELE-03 | The ward candidate directory shall group candidates by contest, in ballot order. Candidates within a contest follow the official ballot order where available, otherwise alphabetical order in Nepali | M | v0.6 |
| FR-ELE-04 | Contests with zero candidacies shall display explicitly | M | v0.6 |
| FR-ELE-05 | Candidate profiles shall separate candidate-submitted information from independently sourced information | M | v0.6 |
| FR-ELE-06 | Candidates shall be able to submit profile information after identity verification. Submissions are moderated and labelled "candidate-submitted" | S | v0.6 |
| FR-ELE-07 | An operator admin shall be able to enable a campaign silence-period mode that applies the rules defined in the legal review (for example, hiding candidate-submitted campaign content) | M | v1.1 |
| FR-ELE-08 | Official results shall be displayed with sources after the ECN publishes them | M | v1.1 |

### 3.14 Comparison (CMP)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-CMP-01 | A citizen shall be able to compare 2–4 candidates from the same contest | M | v1.0 |
| FR-CMP-02 | The comparison shall show published attributes, priorities, promises and source coverage side by side, stacked vertically on mobile | M | v1.0 |
| FR-CMP-03 | The system shall not compute scores, rankings, or "match" percentages | M | v1.0 |
| FR-CMP-04 | Coverage shall be phrased factually (for example, "Has published positions on 7 of your 10 selected issues") | M | v1.0 |
| FR-CMP-05 | Each comparison shall have a shareable URL, and candidate order in it shall be randomized per view, not by party | M | v1.0 |

### 3.15 AI assistant (AI)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-AI-01 | The assistant shall answer only from retrieved platform evidence, never from model memory, for election-specific facts | M | v1.2 |
| FR-AI-02 | Every factual sentence shall cite at least one retrieved record or source. Answers without citations shall not be shown | M | v1.2 |
| FR-AI-03 | When evidence is insufficient, the assistant shall say "I couldn't verify that from the available sources" (in Nepali or English) | M | v1.2 |
| FR-AI-04 | The assistant shall refuse to give voting advice, rankings, predictions stated as fact, or persuasion, and shall not present allegations as facts | M | v1.2 |
| FR-AI-05 | Answers shall carry the provenance label "AI-generated summary" and an evidence panel listing the cited sources | M | v1.2 |
| FR-AI-06 | Question logs shall be stored without client identifiers and used only for evaluation | M | v1.2 |
| FR-AI-07 | Provider cost shall be capped. The assistant degrades to "temporarily unavailable" when the cap is reached | M | v1.2 |

### 3.16 Documents and OCR (OCR)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-OCR-01 | Staff shall be able to upload official PDFs and images as documents linked to a source | M | v1.2 |
| FR-OCR-02 | The system shall extract text using embedded text first, then OCR (Tesseract, Nepali + English) | M | v1.2 |
| FR-OCR-03 | The system shall detect legacy-font text (such as Preeti) and convert it to Unicode | M | v1.2 |
| FR-OCR-04 | Extracted text shall not become evidence until a staff member marks it reviewed | M | v1.2 |

### 3.17 Tenancy (TEN)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-TEN-01 | Each onboarded local level shall have its own PostgreSQL database; national and identity data shall live in a central database (`12` §3) | M | v0.1 |
| FR-TEN-02 | Tenant databases shall be migrated to the same schema version; a tenant whose migration fails shall enter maintenance without affecting other tenants | M | v0.1 |
| FR-TEN-03 | Tenants shall be provisioned, suspended, exported, restored and archived only via audited CLI commands | M | v0.1 |
| FR-TEN-04 | Each tenant shall hold read-only replicas of the national catalogues and its own geography subtree, kept in sync automatically | M | v0.1 |
| FR-TEN-05 | Requests shall be routed to the correct tenant by local level path, ward, issue ID, or staff tenant selection; unknown or inactive tenants return 404 without revealing existence | M | v0.1 |
| FR-TEN-06 | Cross-tenant listings (sitemap, search, My reports) shall use central indexes updated through a transactional outbox, never by querying all tenants in a request | M | v0.1 |

### 3.18 Citizen accounts (ACC)

| ID | Requirement | P | Ver |
|---|---|---|---|
| FR-ACC-01 | Citizen and staff accounts shall be separate, with separate guards and host-only session cookies on their own hosts | M | v0.2 |
| FR-ACC-02 | Citizens shall be able to register (with CAPTCHA), verify their email, sign in, sign out, reset and change their password | M | v0.2 |
| FR-ACC-03 | Public page HTML shall be identical for signed-in and signed-out users; account state loads client-side | M | v0.2 |
| FR-ACC-04 | A citizen shall be able to save up to 5 wards in any local levels, each with a relationship (permanent address, temporary address, workplace, other), and mark one as primary. Only the ward is stored, never a street address | M | v0.2 |
| FR-ACC-05 | The report flow shall offer saved wards first and, when the reporting scope allows, any other published ward; the relationship to the chosen ward (or `visitor`) is recorded for moderators only | M | v0.2 |
| FR-ACC-06 | A citizen shall see all their reports across local levels, with current moderation and lifecycle status | M | v0.2 |
| FR-ACC-07 | A citizen shall be able to export their data and delete their account (`12` §15) | M | v0.2 |
| FR-ACC-08 | Citizens should be able to enable TOTP two-factor authentication | S | v1.0 |
| FR-ACC-09 | Operator admins shall be able to set the reporting scope to `any_ward` (default) or `saved_wards_only`, platform-wide or for one local level, with a cooldown for newly saved wards; changes are audited and never affect existing reports (`12` §12.4) | M | v0.2 |

### 3.19 Later requirements (summary)

| Area | Requirement summary | Ver |
|---|---|---|
| PWA | Installable; offline access to recently viewed ward pages; offline issue drafts submitted when back online | v1.0 |
| ANA | Privacy-friendly traffic analytics, plus aggregate product events without personal identifiers (`06` §15) | v0.3 |
| PRJ | Local projects and budgets by fiscal year, with sources and status | v2.0 |
| NTF | Opt-in notifications for followed wards and issues | v2.0 |

---

## 4. External interface requirements

### 4.1 User interfaces

Defined in `09_UX_UI_SPEC.md`: mobile-first, Nepali-first, WCAG 2.2 AA.

### 4.2 Software interfaces

| Interface | Direction | Purpose | Version |
|---|---|---|---|
| Laravel REST API `/api/v1` | Next.js ↔ API | All data (`06` §16) | v0.1 |
| Next.js `/api/revalidate` | API → Next.js (HMAC-signed) | On-demand cache invalidation | v0.1 |
| Cloudflare (DNS, CDN, WAF) | Edge | Delivery and protection | v0.1 |
| Cloudflare Turnstile | Browser → API verification | CAPTCHA | v0.2 |
| Cloudflare R2 (S3 API) | API/worker ↔ storage | Media and backups | v0.1 |
| Sentry | API/web → Sentry | Error tracking | v0.1 |
| SMTP provider | API → email | Staff notifications | v0.2 |
| Map tiles (PMTiles on R2) | Browser ↔ R2 | Basemap | v0.3 |
| LLM and embeddings provider | Worker → provider | AI assistant | v1.2 |

### 4.3 Communications

HTTPS only (TLS 1.2+), with HSTS. The API is served on its own subdomain and the staff dashboard on a separate subdomain (`06` §20).

---

## 5. Non-functional requirements

### 5.1 Performance and capacity

| ID | Requirement | Ver |
|---|---|---|
| NFR-PERF-01 | Public pages: 75th-percentile LCP ≤ 2.5 s and INP ≤ 200 ms on a mid-range Android over 4G | v0.1 |
| NFR-PERF-02 | Initial JavaScript ≤ 150 KB (gzip) on public pages; total first-load weight ≤ 500 KB, excluding user photos | v0.1 |
| NFR-PERF-03 | API read endpoints: p95 ≤ 300 ms at the origin | v0.1 |
| NFR-PERF-04 | Public pages shall be served from the edge cache, so 50,000 page views/day cause no more than about 1% of those requests to reach the origin | v0.1 |
| NFR-PERF-05 | Issue submission, including a 2 MB photo, completes in ≤ 10 s on 3G | v0.2 |

### 5.2 Availability and recovery

| ID | Requirement | Ver |
|---|---|---|
| NFR-AVL-01 | Public pages ≥ 99.5% monthly availability | v0.1 |
| NFR-AVL-02 | Recovery point objective (RPO) ≤ 24 h; recovery time objective (RTO) ≤ 4 h, per database; a single tenant can be restored without affecting others | v0.1 |
| NFR-AVL-03 | A backup restore is tested before each minor release and at least monthly | v0.1 |

### 5.3 Security

| ID | Requirement | Ver |
|---|---|---|
| NFR-SEC-01 | Authentication, session management and access control follow OWASP ASVS Level 2 | v0.2 |
| NFR-SEC-02 | All input is validated server-side through form requests; output is encoded; queries are parameterized | v0.1 |
| NFR-SEC-03 | Security headers set: CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` | v0.1 |
| NFR-SEC-04 | Rate limits: public reads 120/min per client; submissions 5/hour and 20/day per fingerprint; login 5/min per account | v0.1–v0.2 |
| NFR-SEC-05 | Secrets never stored in git; production secrets live in server env files with restricted permissions | v0.1 |
| NFR-SEC-06 | Dependency vulnerability audit runs in CI; high-severity findings block release | v0.1 |
| NFR-SEC-07 | An automated tenant isolation suite covering every public and staff endpoint runs in CI and must pass to merge | v0.1 |

### 5.4 Privacy

| ID | Requirement | Ver |
|---|---|---|
| NFR-PRV-01 | Collect only what the requirements need. Citizen accounts store only the data listed in `12` §15; no third-party tracking scripts | v0.1 |
| NFR-PRV-02 | Client fingerprints are salted hashes of IP + user agent; the salt rotates every 30 days | v0.2 |
| NFR-PRV-03 | The link between an approved issue and its reporter is removed 90 days after resolution; rejected issue content is deleted after 90 days `[PROPOSED]` | v0.2 |
| NFR-PRV-04 | Application logs contain no raw contact details or passwords; IP addresses are truncated after 7 days | v0.2 |
| NFR-PRV-05 | Account deletion removes the account, saved wards and reporter links in all tenants within 7 days; export returns profile, wards and own reports | v0.2 |

### 5.5 Neutrality

| ID | Requirement | Ver |
|---|---|---|
| NFR-NEU-01 | Identical templates for all people, parties and candidates | v0.1 |
| NFR-NEU-02 | No party colours in layout; party identity shown only as text plus the official symbol | v0.1 |
| NFR-NEU-03 | Ordering rules are documented and never depend on party, popularity or payment | v0.1 |
| NFR-NEU-04 | Brand palette checked for resemblance to major party colours before launch | v0.1 |

### 5.6 Accessibility and usability

| ID | Requirement | Ver |
|---|---|---|
| NFR-ACC-01 | WCAG 2.2 AA; touch targets ≥ 44 × 44 px; visible focus; respects reduced-motion settings | v0.1 |
| NFR-ACC-02 | Devanagari body text ≥ 17 px with line height ≥ 1.6 | v0.1 |
| NFR-USE-01 | A first-time visitor finds their ward and understands who represents it within 2 minutes (usability test with ≥ 5 users) | v0.1 |

### 5.7 Maintainability and quality

| ID | Requirement | Ver |
|---|---|---|
| NFR-MNT-01 | Domain logic lives in module actions; controllers are thin; module boundaries are enforced by architecture tests | v0.1 |
| NFR-MNT-02 | Every action and policy has automated tests. CI must be green to merge | v0.1 |
| NFR-MNT-03 | API types are generated from OpenAPI; stale generated types fail CI | v0.1 |
| NFR-MNT-04 | Migrations are backward-compatible for one release (expand → migrate → contract) | v0.2 |

### 5.8 Observability

| ID | Requirement | Ver |
|---|---|---|
| NFR-OBS-01 | Errors from both apps go to error tracking, tagged with release version | v0.1 |
| NFR-OBS-02 | A health endpoint reports database, queue lag and storage status; external uptime checks run every 5 min | v0.1 |
| NFR-OBS-03 | Alerts fire on downtime, queue lag > 10 min, moderation items older than 24 h, and backup failure | v0.2 |

---

## 6. Data requirements

| Data | Retention | Notes |
|---|---|---|
| Administrative units, offices, sources | Indefinite, versioned | Public-interest record |
| Issues (approved) | Indefinite; archived after resolution + 2 years | Public |
| Issues (rejected) | Content deleted after 90 days; minimal moderation metadata kept | `[PROPOSED]` |
| Citizen account (email, password hash, display name, locale) | Until deletion (+7 days) | NFR-PRV-05 |
| Saved wards | Until removed or account deleted | FR-ACC-04 |
| Reporter link on issues | 90 days after resolution; immediately on account deletion | NFR-PRV-03 |
| Client fingerprints | Salt rotated every 30 days | NFR-PRV-02 |
| Audit events | 5 years minimum | Append-only |
| Backups | 7 daily, 4 weekly, 3 monthly | `06` §21 |
| AI question logs | 180 days, no client identifiers | `[PROPOSED]` |

The data model is specified in `05_DOMAIN_DATA_MODEL.md`.

---

## 7. Traceability — PRD acceptance criteria

| PRD acceptance criterion | Requirements | Version |
|---|---|---|
| 1. Find their ward | FR-GEO-01..04 (v0.1), FR-GEO-05, FR-GEO-07 (v0.4) | v0.1 / v0.4 |
| 2. Understand the ward profile | FR-OFF-01..03, FR-SRC-01..04 | v0.1 |
| 3. Browse candidates | FR-ELE-03, FR-ELE-04 | v0.6 |
| 4. Open candidate profiles | FR-ELE-05, FR-OFF-04 | v0.6 (v0.1 for office holders) |
| 5. Compare candidates | FR-CMP-01..05 | v1.0 |
| 6. See sources | FR-SRC-01..06 | v0.1 |
| 7. Report a local issue | FR-ISS-01..10, FR-MED-01..04, FR-ACC-01..06 | v0.2 |
| 8. View local issues on a map | FR-ISS-12 | v0.3 |
| 9. View candidate promises | FR-PRM-01..05 | v0.5 |
| 10. Share a useful page | FR-SHR-01..06 | v0.1 |
| 11. Ask the AI a factual question | FR-AI-01..07 | v1.2 |
| 12. Inspect evidence behind an AI answer | FR-AI-02, FR-AI-05 | v1.2 |
| Mobile usability | NFR-PERF-01..02, NFR-ACC-01..02, NFR-USE-01 | v0.1 |

---

## Change log

| Version | Date | Change |
|---|---|---|
| 0.4 | 2026-09-17 | FR-ACC-09 configurable reporting scope (D-012) |
| 0.3 | 2026-09-17 | Tenancy (FR-TEN) and citizen accounts (FR-ACC) added; reporting requires a verified account; staff roles scoped per tenant; privacy requirements updated (D-010, D-011) |
| 0.2 | 2026-09-17 | Normal-pace replan (D-009): AI and OCR requirements move to v1.2; FR-COR-02 to v0.3; FR-GEO-05 to v0.4 |
| 0.1 | 2026-09-17 | Initial SRS |
