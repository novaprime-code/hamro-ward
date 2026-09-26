# Hamro Ward — 09 UX/UI Specification

> **Status:** Draft v0.2
> **Date:** 2026-09-17
> **Implements:** `03` FR-OFF, FR-SRC, FR-ISS, FR-ACC, FR-SHR, FR-L10N; NFR-ACC, NFR-PERF, NFR-NEU; `12` §11–15
> **Review needed:** Native Nepali UX writer for all copy; neutrality check of the palette (task `HW-E07-F01-T03`)

---

## 1. Design brief

| Item | Definition |
|---|---|
| Subject | The citizen's own ward: the local government unit closest to daily life |
| Audience | Nepali adults on mid-range Android phones, often on mobile data, many unfamiliar with civic terms |
| Primary job | Answer "Who represents my ward, what's happening here, and how do I know it's true?" in under two minutes |
| Emotional target | Calm, official-feeling but human, trustworthy. **Never** partisan, alarmist, or campaign-like |

**Visual source material.** The design draws on the real objects of local government in Nepal:

* the painted **ward office signboard**, where the ward number is the most prominent thing
* notice boards (सूचना पाटी) with stamped, dated notices
* the ballot's fixed ordering of seats

It does not borrow from party campaign material, news-site layouts, or SaaS dashboards.

**The one memorable element: the ward plate.** A signboard-like block with the ward number set very large in Devanagari numerals (वडा नं. ४), and the local level and district beneath. Everything else stays quiet.

---

## 2. Principles

1. **Answer first.** The ward page shows who holds each seat before anything else. There is no hero marketing.
2. **Every fact shows its footing.** A provenance badge sits next to facts. Tapping it opens the sources. "Not yet verified" is a visible, respectable state, not an error.
3. **Order is neutral and explained.** Seats follow ballot order and people are never sorted by party. The explanation is one tap away ("Why this order?").
4. **Lists before maps, text before images.** These are cheap to load. Heavy features are opt-in (maps, photos at full size).
5. **One idea per screen on mobile.** No dense tables; comparisons stack vertically.
6. **Plain Nepali.** Everyday words first, official terms in parentheses when needed.
7. **Structure carries meaning.** Borders, dashes and numbering encode information (for example, a dashed outline means unverified, and numbering is used only for real sequences like report steps).

---

## 3. Design tokens

### 3.1 Colour

Deliberately **not** red, green, saffron or blue as brand accents. Those carry party or national-flag associations. The palette is built from ink, slate, paper and the muted brass of an office signboard.

| Token | Hex | Use |
|---|---|---|
| `--ink` | `#1B2430` | Primary text, ward plate background |
| `--slate` | `#4A5A6A` | Secondary text, icons |
| `--paper` | `#FCFCFA` | Page background (neutral white, not cream) |
| `--rule` | `#DCE1E5` | Dividers, list separators |
| `--brass` | `#A8842C` | The single accent: ward plate numeral, focus ring, primary button border |
| `--brass-ink` | `#6E5518` | Accessible brass for text and links on paper (≥ 4.5:1) |

| Semantic token | Hex | Use | Pairing rule |
|---|---|---|---|
| `--state-verified` | `#2F5D50` | Verified tick icon | Always with icon + text |
| `--state-unverified` | `#8A6D1F` | Dashed outline for unverified | Always with icon + text |
| `--state-vacant` | `#5B6470` | Vacant seat | Always with icon + text |
| `--danger` | `#9B2C2C` | Form errors only | Never used for people or parties |
| `--focus` | `--brass` | 3 px outline + 2 px offset | — |

**Dark mode:** v1.0, not v0.

**Neutrality check:** before launch, compare the palette against current major party flags and symbols. If any token closely matches a party's primary colour, change it (NFR-NEU-04).

### 3.2 Typography

| Role | Typeface | Why |
|---|---|---|
| Display: ward numeral, page titles | **Anek Devanagari** (variable width and weight; Devanagari + Latin) | Its condensed widths echo painted signboard lettering; one variable file covers every display size |
| Body and UI | **Noto Sans Devanagari** + **Noto Sans** (Latin) | Most reliable conjunct and matra rendering on Android; neutral |

Both are self-hosted via `next/font`, subset to Devanagari + Basic Latin, with `font-display: swap`. The budget for all font files on first load is ≤ 120 KB.

| Step | Size / line height | Use |
|---|---|---|
| `plate` | clamp(72px, 22vw, 120px) / 1.0, Anek, wdth 75, wght 700 | Ward number |
| `h1` | 28px / 1.3, Anek, wght 650 | Page title |
| `h2` | 21px / 1.35, Anek, wght 600 | Section title |
| `body` | 17px / 1.65 | Default (NFR-ACC-02) |
| `small` | 15px / 1.55 | Meta: dates, source publisher |

**Rules:**

* No all-caps labels; Devanagari has no case anyway, so keep English consistent with it.
* Line length ≤ 70 characters.
* Headings are left-aligned; nothing is centred except the ward plate numeral.

### 3.3 Space, shape, motion

| Token | Value |
|---|---|
| Space scale | 4, 8, 12, 16, 24, 32, 48 px |
| Page gutter | 16 px mobile, 24 px tablet; content max width 720 px |
| Radius | `0` for the ward plate (signboard), `6px` for inputs and buttons, `12px` for bottom sheets |
| Elevation | None on lists. Bottom sheets and dialogs only: `0 -8px 24px rgba(27,36,48,.12)` |
| Motion | Only on user action: sheet slide (200 ms), status change confirmation. `prefers-reduced-motion` removes all transitions |
| Touch targets | ≥ 44 × 44 px |

### 3.4 Numerals and dates

| Context | Rule |
|---|---|
| `ne` locale, content numbers | Devanagari digits (वडा नं. ४, २०७९) |
| Phone numbers, codes, tracking IDs, URLs | Latin digits (copyable, dialable) |
| Dates | BS primary ("२०७९ जेठ १३"); AD secondary in small text on detail views ("27 May 2022") |
| Money (v0.5+) | Lakh/crore: "रु. १२ करोड ५० लाख" / "Rs 12.5 crore" |

---

## 4. Information architecture

```
Home (/ne)
├─ [pilot local level] ─────────── /ne/ward/{province}/{district}/{local-level}
│   ├─ Ward N ──────────────────── …/{n}
│   │   ├─ Representatives (seat roster)
│   │   ├─ Local-level-wide seats (mayor, deputy)
│   │   ├─ Ward office
│   │   ├─ Issues (v0.2) ─────────── /ne/issue/{publicId}
│   │   └─ Report an issue (v0.2) ── /ne/report?ward=…
│   └─ Local level leadership
├─ Person ─────────────────────── /ne/person/{slug}
├─ Account (v0.2)
│   ├─ Sign in / Register ─────── /ne/account/sign-in, /ne/account/register
│   ├─ Verify email / Reset ───── /ne/account/verify, /ne/account/reset-password
│   ├─ My wards ───────────────── /ne/account/wards
│   ├─ My reports ─────────────── /ne/account/reports
│   └─ Settings (password, export, delete) ── /ne/account/settings
├─ How sources work ───────────── /ne/sources
├─ About ──────────────────────── /ne/about
├─ Privacy ────────────────────── /ne/privacy
└─ Beta ───────────────────────── /ne/beta

Staff (admin host)
├─ Sign in → two-factor challenge / enrolment
├─ Local level switcher (only tenants I'm a member of)
├─ Queue (issues | corrections | flags)
│   └─ Review item
├─ Audit log
└─ Settings (kill switch) · My declaration
```

**Global navigation (mobile):**

* Top bar: logo wordmark "हाम्रो वडा", locale switch (ने | EN), menu.
* Menu: My ward (last visited, stored locally), Report an issue (v0.2), How sources work, About.
* Account area (v0.2): "Sign in" when signed out; display name with My wards, My reports, Settings, Sign out when signed in. Rendered client-side after page load so cached pages stay identical for everyone (`12` §11.4).
* **No bottom tab bar in v0**: too few destinations.

---

## 5. Public screens

### 5.1 Home (v0.1)

**Purpose:** get to a ward in one or two taps.

```
┌──────────────────────────────┐
│ हाम्रो वडा            ने|EN ☰ │
├──────────────────────────────┤
│ तपाईंको वडा, तपाईंको जानकारी  │  h1
│ Who represents your ward, and│  body (short)
│ how we know.                 │
│                              │
│ [Pilot local level name]     │  h2
│ ┌──┐┌──┐┌──┐┌──┐┌──┐         │  ward tiles: numerals only,
│ │ १││ २││ ३││ ४││ ५│         │  a numbered set, so numbering
│ └──┘└──┘└──┘└──┘└──┘         │  is information
│ …                            │
│ Last visited: वडा नं. ४ ›     │  (if stored locally)
│──────────────────────────────│
│ Every fact shows its source. │
│ How sources work ›           │
│──────────────────────────────│
│ Beta · About · Privacy       │  footer
└──────────────────────────────┘
```

**States:**

* Only one local level published → its ward grid shows directly (FR-GEO-02).
* Later, with multiple local levels → province, district, local level pickers plus search (v0.4).

### 5.2 Ward profile (v0.1)

```
┌──────────────────────────────┐
│ ‹ [Local level]        Share │
│┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓│
│┃ वडा नं.                    ┃│  ward plate: ink bg,
│┃  ४                         ┃│  brass numeral
│┃ [Local level], [District]  ┃│
│┃ [Province]                 ┃│
│┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛│
│ Your representatives         │  h2
│ 2022–2027 term · why order? ⓘ│
│──────────────────────────────│
│ Ward chair (वडाध्यक्ष)        │  seat label
│ [Name in Nepali]         ›   │  person row
│ [Party or Independent]       │
│ ◉ Official source            │  provenance badge
│──────────────────────────────│
│ Woman member                 │
│ ┆ Not yet verified ┆         │  dashed outline state
│ We haven't confirmed this    │
│ seat yet. Know a source?     │
│ Report it ›                  │
│──────────────────────────────│
│ Dalit woman member           │
│ ○ Vacant since २०८२ फागुन     │  vacant state + source badge
│──────────────────────────────│
│ Members (2)                  │
│ …                            │
│──────────────────────────────│
│ For the whole local level    │  h2, clearly separated
│ Mayor · Deputy mayor rows    │
│──────────────────────────────│
│ Ward office                  │
│ [address] · 📞 025-xxxxxx     │  Latin digits for phone
│──────────────────────────────│
│ Report an error on this page │
└──────────────────────────────┘
```

**Rules:**

* **Seat order** is fixed by `positions.ballot_order` (FR-OFF-01).
* **The party line** is plain text, the same weight for everyone, with no logo in v0.1.
* **The "Why this order?" sheet** explains that seats follow the ballot order and that people are never sorted by party.
* **Share** uses the Web Share API with a pre-filled localized title, falling back to copy link with a "Link copied" toast.

### 5.3 Person (v0.1)

```
┌──────────────────────────────┐
│ ‹ Ward 4                     │
│ [Full name — Nepali]         │  h1
│ [Full name — English]        │  small
│                              │
│ Current role                 │  h2
│ Ward chair, Ward 4,          │
│ [Local level]                │
│ Since २०७९ जेठ १३             │
│ (27 May 2022)                │
│ [Party / Independent]        │
│ ◉ Official source · 2 sources│
│──────────────────────────────│
│ Earlier roles (v0.4)         │
│──────────────────────────────│
│ Promises (v0.5)              │
│──────────────────────────────│
│ Report an error on this page │
└──────────────────────────────┘
```

No photo in v0.1 unless a usable official photo is sourced. When there is no photo, show **no placeholder silhouette** (it reads as missing data).

### 5.4 Source sheet (v0.1) — component, opened from any badge

```
┌──────────────────────────────┐
│ ▔▔▔                          │  bottom sheet handle
│ Sources for: Ward chair      │
│──────────────────────────────│
│ ◉ Official                   │  provenance type
│ Local election results 2079  │  title
│ Election Commission Nepal    │  publisher
│ Published २०७९ जेठ १५         │
│ Checked  २०८३ भदौ ३२          │
│ ✓ Verified by our team       │
│ Open source ↗                │
│──────────────────────────────│
│ ⚠ Sources disagree (v0.4)    │  conflict block: both values,
│  Source A says … (ward site, │  authority + date, "under
│  2014)                       │  review"
│  Source B says … (CBS, 2021) │
│──────────────────────────────│
│ What these labels mean ›     │
└──────────────────────────────┘
```

### 5.5 Report an issue (v0.2) — a real sequence, so the steps are numbered

**Before step 1:** signed-out visitors see "Sign in to report a problem. It keeps spam out and lets you follow your report." with **Sign in** and **Create account** buttons. After signing in or verifying their email they return to the flow with their progress kept.

| Step | Screen | Notes |
|---|---|---|
| 1 | **Which ward?** Saved wards first, each labelled with its relationship ("Permanent address · Ward 4, Namuna Nagarpalika"), then **Another ward** (search/picker) — hidden when reporting is limited to saved wards. Then location: "Use my location" or "Describe the place" | Prefilled if coming from a ward page. If the chosen ward's municipality isn't open yet: "Reporting isn't open in this municipality yet." Location permission only on tap; exact spot shown publicly only if the reporter chooses |
| 2 | **What kind of problem?** Category list with icons + text, one tap | 14 categories; "Other" last |
| 3 | **Tell us about it.** Title, description, up to 3 photos | Live counters; guidance: "Describe the problem, not a person." Photos compressed on device, with a progress bar |
| 4 | **Check and send.** Summary + note "Your name is never shown publicly" + Turnstile widget + Send report | Button label is exactly "Send report" |
| — | **Receipt.** "Report sent. It will appear after review, usually within 24 hours." Tracking code (Latin, copyable), "See My reports", "Report another" | Same verb as the button |

**Reporting limited to saved wards** (when an operator has turned it on, `12` §12.4):

* The ward page's "Report an issue" button stays visible. Tapping it for a ward the citizen hasn't saved shows: "Reports here are limited to people who have saved this ward. Add it in My wards; you can report from it after 72 hours." with **Add to My wards**.
* A ward still in cooldown appears in step 1 greyed out with "Available from [date, time]".
* Wording never implies the citizen did something wrong.

The former optional contact step is removed: the account email is used for status emails, which the citizen can switch off in Settings.

**Submissions paused state:** "New reports are paused for now. Existing reports are still visible. Try again later." No form is shown.

**Errors are specific:**

* "Photo is larger than 10 MB. Choose a smaller photo."
* "Add a few more words to the description (at least 10 characters)."

### 5.6 Account screens (v0.2)

| Screen | Content | Rules |
|---|---|---|
| Register | Display name, email, password (show/hide), preferred language, Turnstile, link to Privacy notice | Plain explanation: "We ask for an email so reports stay accountable. Your name is never shown on public pages." |
| Sign in | Email, password, "Forgot password?" | Throttle message states the wait time |
| Verify email | "Check your inbox" with resend (limited) and change-email link | Reporting button stays disabled until verified, with this reason shown |
| My wards | List of saved wards (up to 5): ward, local level, relationship badge, primary marker; **Add ward** → ward picker → relationship choice | Relationship options: स्थायी ठेगाना / Permanent address, अस्थायी ठेगाना / Temporary address, काम गर्ने ठाउँ / Where I work, अन्य / Other. Only the ward is saved, never a street address. Wards in municipalities not yet open show "Not open yet" |
| My reports | Rows across all municipalities: title, ward + local level, moderation state ("Waiting for review", "Published", "Not published — reason"), lifecycle status badge | Empty state: "You haven't reported anything yet." with Report an issue |
| Settings | Change password, language, status emails on/off, **Download my data**, **Delete account** | Delete requires password and a confirmation that states exactly what happens (`12` §15) |

### 5.7 Issue list and issue page (v0.2)

* **List:** rows showing category icon + label, title, lifecycle status badge (text + icon), and relative date in BS. Filters arrive in v0.3.
* **Issue page:**
  * title, category, ward, status badge
  * description
  * photos (tap for full size)
  * approximate location text, with the map in v0.3
  * the public **status timeline** (FR-ISS-08)
  * a "Community report" provenance label
  * Report an error / Flag (v0.3)

---

## 6. Staff screens (v0.2)

Staff UI is desktop-first, but must remain usable on a phone for twice-daily queue checks.

| Screen | Content | Key interactions |
|---|---|---|
| Sign in | Email, password → two-factor code; first-time TOTP enrolment with QR + recovery codes (must confirm saved) | Lockout message states the retry time |
| Queue | Tabs: Issues / Corrections / Flags. Rows: age (overdue in `--danger` text + icon), ward, category, restricted marker, assigned | Filter by state, ward, age; assign to me |
| Local level switcher | Top-bar select listing only the staff member's tenant memberships; the current local level name is always visible | Switching reloads queue data; no cross-tenant lists except operator admin overview |
| Review | **Left:** content, photos, location (precise, staff only), reporter display name and **relationship to the ward** (Permanent address / Temporary address / Workplace / Other / Visitor), email reveal (click + audit event). **Right:** decision panel with Approve / Reject (reason code required) / Needs review / Mark restricted, plus note; history of decisions | Keyboard shortcuts on desktop (A/R/N). Restricted items show "Second approval needed" and block self-approval. Recusal match shows "You can't act on this item" |
| Audit log | Table (desktop) / stacked rows (mobile): time, actor, action, subject link; filters | Read-only |
| Settings | Kill switch toggle; **reporting scope** (Any ward / Saved wards only) with cooldown hours, platform-wide or per local level; each change needs a reason and a confirmation dialog stating the exact effect | Operator admin only; audited |
| My declaration | Affiliation declaration form (private) | Required before moderator/verifier role |

---

## 7. Component inventory

| Component | Variants / states | Accessibility |
|---|---|---|
| `WardPlate` | ward, local-level (smaller) | Numeral has `aria-label="Ward number 4"` in the locale |
| `SeatRoster` | ward seats, local-level seats | `<section>` with heading; list semantics |
| `PersonRow` | held, vacant, not_verified | Whole row is a link when held |
| `ProvenanceBadge` | 8 provenance types × verified/unverified | Icon + text; button opens `SourceSheet`; `aria-haspopup="dialog"` |
| `SourceSheet` | sources list, conflict block | Focus trap, Esc/close, returns focus |
| `StateNotice` | not_verified (dashed), vacant, paused, empty | Text always present |
| `StatusBadge` | 6 lifecycle statuses | Icon + text; no colour-only meaning |
| `DateText` | BS primary, AD secondary | `<time datetime="2022-05-27">` |
| `ShareButton` | native share, copy fallback | Live region announces "Link copied" |
| `LocaleSwitch` | ne / en | Keeps current path; `lang` attributes on labels |
| `StepForm` | 5 steps + receipt | Step count announced; errors linked with `aria-describedby` |
| `WardPicker` | saved wards first, search, not-open state | Combobox semantics; results announced |
| `RelationshipBadge` | 5 relationship values | Text label always present |
| `AccountMenu` | loading placeholder, signed out, signed in | Placeholder has fixed size; menu is a disclosure button |
| `PhotoPicker` | empty, compressing, uploaded, error, removed | Progress announced |
| `Toast` | info, success, error | `role="status"` |
| `DecisionPanel` (staff) | normal, restricted, recused, awaiting second approval | Disabled actions state why |

**Provenance badge icons and labels** (Nepali labels are drafts for review):

| Type | Icon idea | EN label | NE label (draft) |
|---|---|---|---|
| official | Stamp seal | Official source | आधिकारिक स्रोत |
| candidate_submitted | Speech mark | Submitted by candidate | उम्मेदवारले पेश गरेको |
| public_record | Document | Public record | सार्वजनिक अभिलेख |
| verified_community_report | People + tick | Verified community report | प्रमाणित नागरिक रिपोर्ट |
| community_report | People | Community report | नागरिक रिपोर्ट |
| media_report | Newspaper | News report | समाचार स्रोत |
| ai_generated_summary | Text lines | AI summary | एआई सारांश |
| unverified_claim | Dashed circle | Unverified claim | अप्रमाणित दाबी |

---

## 8. Copy rules

* **Sentence case**, active voice, and the same verb from button to confirmation ("Send report" → "Report sent").
* **Explain civic terms once, inline.** Example: "Ward chair (वडाध्यक्ष) leads the ward committee."
* **Never use evaluative words about people:** no "good", "bad", "best", "corrupt", "popular".
* **Empty and unverified states point to an action:** "Know a source? Report it."
* **Errors say what happened and how to fix it.** No apologies, no vagueness.
* **Party names** are written as officially registered, identical formatting for all.
* All strings live in `messages/ne.json` and `messages/en.json`. **Nepali is written first**, not translated from English.

---

## 9. Accessibility and performance acceptance

| Check | Target | How |
|---|---|---|
| Contrast | Text ≥ 4.5:1, large text/icons ≥ 3:1 | Token check in CI (axe) |
| Keyboard | All flows operable; visible focus | Playwright + manual |
| Screen reader | TalkBack pass on ward page and report flow | Manual, each release |
| Zoom | 200% without horizontal scroll | Manual |
| LCP / INP | ≤ 2.5 s / ≤ 200 ms (p75, mid Android, 4G) | Lighthouse CI throttled + field data |
| JS budget | ≤ 150 KB gz per public route | `next build` output check in CI |
| Fonts | ≤ 120 KB total | Build check |
| Devanagari rendering | Conjunct test string renders correctly in pages and OG images | Visual snapshot test |

---

## 10. Usability validation

* **v0.1:** 5 people (mixed ages, at least 2 first-time voters, at least 1 low-literacy participant) complete "Find who represents your ward and show me where that information comes from." Pass: 4 of 5 within 2 minutes (NFR-USE-01).
* **v0.2:** 5 people report a sample issue on their own phone over mobile data. Pass: 4 of 5 complete without help; no one enters personal accusations after reading the guidance.
