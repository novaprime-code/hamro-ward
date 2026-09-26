# Hamro Ward — 02 Nepal Civic Domain Reference

> **Purpose:** The single reference for how Nepal's local government and local elections work, and which domain rules the data model must obey.
> **Status:** Draft v0.1
> **Last researched:** 2026-09-15
> **Owner:** Product / domain lead
> **Feeds into:** `05_DOMAIN_DATA_MODEL.md`, `06_TECHNICAL_ARCHITECTURE.md`, `07_DATA_SOURCES_AND_ACQUISITION.md`

---

## 0. How to read this document

This document follows the same provenance rules as the product. Every factual claim carries a verification tag:

| Tag | Meaning |
|---|---|
| `[OFFICIAL]` | Confirmed directly against an official source (ECN, Government of Nepal, CBS, the local level itself) |
| `[SECONDARY]` | Taken from reputable secondary sources (IFES, UN Women, national press, academic papers). Must be confirmed against an official source before it is used as seed data |
| `[DERIVED]` | Calculated from other tagged facts |
| `[TO VERIFY]` | Believed correct, but not yet sourced |
| `[ASSUMPTION]` | A product decision made in the absence of information |

As of v0.1, **no fact in this document is tagged `[OFFICIAL]`**. Promoting facts to `[OFFICIAL]` is the first task of `07_DATA_SOURCES_AND_ACQUISITION.md`.

Section 9 (Domain rules) contains decisions, not facts. Those rules must hold even if the facts in sections 2–5 change.

---

## 1. Why this document exists

Hamro Ward's core promise is that a citizen can understand their ward. That depends on getting the underlying structure right:

* which administrative units exist
* who is elected to what
* how seats are divided
* how terms start and end
* how Nepali dates, numbers and names behave

Errors here are not cosmetic. Misattributing a candidate to the wrong constituency, or showing a stale ward count, directly damages trust. These errors are also easy to hard-code by accident.

---

## 2. Administrative hierarchy

### 2.1 Levels

```
Country (नेपाल)
└── Province (प्रदेश) ................ 7
    └── District (जिल्ला) ............ 77
        └── Local level (स्थानीय तह) .. 753
            └── Ward (वडा) ........... 6,743
```

| Unit | Count | Tag | Source |
|---|---|---|---|
| Provinces | 7 | `[SECONDARY]` | Constitution of Nepal 2015; multiple secondary sources |
| Districts | 77 | `[SECONDARY]` | IFES 2022 Local Election FAQ |
| Local levels | 753 | `[SECONDARY]` | IFES 2022 FAQ; Kathmandu Post (2022) |
| Wards | 6,743 | `[SECONDARY]` | IFES 2022 FAQ; Kathmandu Post (2022) |
| Wards per local level | 5 to 35 | `[SECONDARY]` | IFES 2022 FAQ |

**Note on the district tier:** A district is a geographic and administrative unit, but it is **not** an elected government tier. The District Coordination Committee (जिल्ला समन्वय समिति) is formed indirectly and has a coordination role only. In Hamro Ward, districts are navigation and grouping units, not governments.

### 2.2 Local level types

| Type | Nepali | Group | Count | Tag |
|---|---|---|---|---|
| Metropolitan city | महानगरपालिका | Municipality (नगरपालिका family) | 6 | `[SECONDARY]` |
| Sub-metropolitan city | उपमहानगरपालिका | Municipality | 11 | `[SECONDARY]` |
| Municipality | नगरपालिका | Municipality | 276 | `[SECONDARY]` |
| Rural municipality | गाउँपालिका | Rural municipality | 460 | `[SECONDARY]` |

The distinction between the municipality group and rural municipalities matters for three things:

* **Position titles.** Municipalities have a Mayor and Deputy Mayor; rural municipalities have a Chairperson and Vice-Chairperson.
* **Executive composition** (see section 3).
* **UI wording.**

Metropolitan and sub-metropolitan cities are legally municipalities with a higher classification. A local level can be **upgraded** between types (for example, municipality → sub-metropolitan city). This is one reason local levels must be temporally versioned.

### 2.3 Provinces

Provinces were originally numbered and later received names. Legacy numbers still appear in older documents, datasets and URLs. For example, the CBS census portal uses `province=1` for Koshi.

| Current name | Nepali | Former designation | Tag |
|---|---|---|---|
| Koshi | कोशी प्रदेश | Province No. 1 | `[SECONDARY]` |
| Madhesh | मधेश प्रदेश | Province No. 2 | `[TO VERIFY]` |
| Bagmati | बागमती प्रदेश | Province No. 3 | `[TO VERIFY]` |
| Gandaki | गण्डकी प्रदेश | Province No. 4 | `[TO VERIFY]` |
| Lumbini | लुम्बिनी प्रदेश | Province No. 5 | `[TO VERIFY]` |
| Karnali | कर्णाली प्रदेश | Province No. 6 | `[TO VERIFY]` |
| Sudurpashchim | सुदूरपश्चिम प्रदेश | Province No. 7 | `[TO VERIFY]` |

**Romanization variants to support in search:** Koshi/Kosi, Madhesh/Madhes, Sudurpashchim/Sudurpaschim/Far-Western.

### 2.4 District pitfalls

* **Some districts were split during federal restructuring, and the halves sit in different provinces.**
  * Nawalparasi was split into an east part (Gandaki) and a west part (Lumbini).
  * Rukum was split into Rukum East (Lumbini) and Rukum West (Karnali).
  * `[TO VERIFY]`
  * Older datasets may still list the pre-split district.
* **District names are not unique identifiers across time.** Always resolve a district by internal ID, never by name.

### 2.5 Wards

* Ward numbers restart at 1 in every local level. "Ward 4" means nothing without its local level.
* The 2017 restructuring merged the former VDCs (Village Development Committees) and old municipalities into the current 753 local levels. Ward numbers and boundaries changed at that point.
* Pre-2017 ward numbers still circulate on official websites (see the Itahari example in 5.2), in older news reports, in citizen memory, and on citizenship documents.
* Ward boundaries can change again in future restructuring.

### 2.6 Historical change: a real example

Itahari became a sub-metropolitan city in 2014, before the 2017 restructuring. It had 26 wards under the old structure and has 20 wards now `[SECONDARY]`.

The municipality's own English "Brief Introduction" page still says 26 wards and quotes 2011 census figures `[SECONDARY — observed 2026-09-15]`.

**Lesson for the product:** An official source can be stale. Source authority does not override freshness. See rule R16.

---

## 3. Local government structure

Each local level has three bodies. The citizen-facing product mainly concerns the elected positions, but accountability features need the full picture.

### 3.1 Bodies

| Body | Nepali (urban / rural) | Role | Composition | Tag |
|---|---|---|---|---|
| Executive | नगर कार्यपालिका / गाउँ कार्यपालिका | Executive government of the local level | **Urban:** Mayor, Deputy Mayor, all ward chairs, 5 women members, and 3 Dalit/minority members elected by the assembly. **Rural:** Chair, Vice-Chair, all ward chairs, 4 women members, and 2 Dalit/minority members. | `[SECONDARY]` UN Women; UNDP GESI report; Constitution Art. 215/216 |
| Assembly | नगर सभा / गाउँ सभा | Local legislature; passes local laws and budget | Executive members plus all ward committee members, plus Dalit/minority members | `[SECONDARY]` |
| Judicial Committee | न्यायिक समिति | Mediation and adjudication of minor local disputes | Chaired by the Deputy Mayor / Vice-Chair, plus two members from among elected representatives, with at least one woman | `[SECONDARY]` |
| Ward Committee | वडा समिति | Ward-level body | Ward chair plus 4 ward members | `[SECONDARY]` |

### 3.2 Elected vs appointed

The platform must never attribute civil-service actions to elected representatives, or the reverse.

| Role | Nepali | Type |
|---|---|---|
| Mayor / Chair | प्रमुख / अध्यक्ष | Elected (direct) |
| Deputy Mayor / Vice-Chair | उपप्रमुख / उपाध्यक्ष | Elected (direct) |
| Ward chair | वडाध्यक्ष | Elected (direct) |
| Ward member | वडा सदस्य | Elected (direct) |
| Executive women / Dalit-minority members | कार्यपालिका सदस्य | Elected (indirect, by assembly) |
| Chief Administrative Officer | प्रमुख प्रशासकीय अधिकृत | Appointed civil servant |
| Ward secretary | वडा सचिव | Appointed staff |

### 3.3 Governing law (names for reference)

| Law | Nepali | Relevance | Tag |
|---|---|---|---|
| Constitution of Nepal, 2015 | नेपालको संविधान, २०७२ | Local government structure; inclusion requirements | `[SECONDARY]` |
| Local Government Operation Act, 2017 | स्थानीय सरकार सञ्चालन ऐन, २०७४ | Powers, bodies, judicial committee, planning and budget | `[SECONDARY]` |
| Local Level Election Act, 2017 | स्थानीय तह निर्वाचन ऐन, २०७३ | Positions, nominations, woman-candidate rule | `[SECONDARY]` |
| Election Management Bill | — | Pending as of the last check; **may change election rules before 2027** | `[SECONDARY]` Rising Nepal (2026) |

Legal interpretation belongs in `03_ELECTION_AND_LEGAL_CONTEXT.md`, reviewed by a Nepali lawyer.

---

## 4. Local elections

### 4.1 Directly elected positions

A voter in any ward votes for **seven** positions:

| # | Position | Constituency | Seat category | Tag |
|---|---|---|---|---|
| 1 | Mayor / Chair | Whole local level | Open | `[SECONDARY]` IFES 2022 FAQ |
| 2 | Deputy Mayor / Vice-Chair | Whole local level | Open | `[SECONDARY]` |
| 3 | Ward chair | Ward | Open | `[SECONDARY]` |
| 4 | Ward member | Ward | Woman | `[SECONDARY]` |
| 5 | Ward member | Ward | Dalit woman | `[SECONDARY]` |
| 6–7 | Ward member (×2) | Ward | Open | `[SECONDARY]` |

Consequences for the product:

* **Ward-level candidate directories must show two constituencies.** A "Ward 4" page lists ward-constituency candidates (ward chair and members) together with local-level-wide candidates (mayor and deputy). The UI must label which is which.
* **Open seats are open.** Women can and do contest open seats. Kathmandu Post reported 442 women elected to open ward-member seats in 2022 `[SECONDARY]`.
* **The electoral system is first-past-the-post** for all local positions `[SECONDARY]`.
* **Symbols:** Party candidates appear on the ballot with the party's registered election symbol. Independents receive symbols assigned by the ECN `[SECONDARY — details TO VERIFY]`. Before displaying symbol images, confirm usage rights (see `07`).

### 4.2 Nomination rules relevant to data validation

* A party must field a woman for **either** mayor **or** deputy mayor (chair or vice-chair in rural municipalities). This comes from Section 17(4) of the Local Level Election Act `[SECONDARY]`.
  * This is a rule about **party nominations**, not about outcomes. Do not use it to validate election results.
* The reserved Dalit woman ward-member seat sometimes has **no candidates at all**. Kathmandu Post reported no candidacies for that category in 123 units in 2022 `[SECONDARY]`.
  * The data model must allow a contest with zero candidacies, and the UI must display that clearly rather than as missing data.

### 4.3 Terms and the current cycle

| Fact | Value | Tag |
|---|---|---|
| Term length | 5 years | `[TO VERIFY]` |
| Previous local elections | 2017 and 13 May 2022 | `[SECONDARY]` |
| Current local term expires | 13 May 2027 (per the ECN) | `[SECONDARY]` Rising Nepal |
| Next local election date | **Not fixed.** The ECN has proposed holding it on the same day as the provincial elections | `[SECONDARY]` Rising Nepal |
| Provincial assembly terms expire | 19 November 2027 (per the ECN) | `[SECONDARY]` Rising Nepal |
| Federal election | Held 5 March 2026 after the House was dissolved in September 2025 | `[SECONDARY]` IFES |

**Product rule:** Never display a local election date until the ECN publishes one. Store it with its source and publication date (Current-Date Rule, project instructions §5).

### 4.4 Vacancies and by-elections

Elected local officials had to resign before standing as candidates in the March 2026 federal election `[SECONDARY — IFES]`. The ECN has discussed elections for 74 vacant local-level posts `[SECONDARY — Rising Nepal, 2026]`.

Therefore:

* An office can be **vacant** mid-term.
* A **by-election** (उपनिर्वाचन) can fill a single position.
* An office holder's term can end early, for example through resignation, death or removal.
* **Whether any Itahari positions are currently vacant is `[TO VERIFY]` and is a pilot-data blocker.**

### 4.5 Indirectly elected positions

Executive women members and Dalit/minority members are elected by the assembly, not by citizens `[SECONDARY]`. They are real office holders and belong in "Who governs my municipality."

`[ASSUMPTION]` For the MVP, they are modelled as office holders but excluded from the candidate directory and comparison.

### 4.6 Voters

| Fact | Tag |
|---|---|
| Voting age: 18 by the day before election day (rule as applied in 2026) | `[SECONDARY]` IFES 2026 FAQ |
| Voter registration is administered by the ECN through district and local election offices | `[SECONDARY]` |
| Voters vote where they are registered. Whether internal migrants can vote elsewhere or by post | `[TO VERIFY]` — directly relevant to the "citizens living away from home" user group |

---

## 5. Pilot: Itahari Sub-Metropolitan City

### 5.1 Facts

| Field | Value | Tag | Source |
|---|---|---|---|
| Nepali name | इटहरी उपमहानगरपालिका | `[TO VERIFY]` spelling | — |
| Province | Koshi | `[SECONDARY]` | Wikipedia; Edusanjal |
| District | Sunsari | `[SECONDARY]` | Edusanjal |
| Type | Sub-metropolitan city (since 2014) | `[SECONDARY]` | Wikipedia |
| Local levels in Sunsari | 12 | `[SECONDARY]` | Edusanjal |
| Wards | 20 | `[SECONDARY]` | Edusanjal; Wikipedia; Nepal Archives |
| Area | 93.78 km² | `[SECONDARY]` | Edusanjal; Wikipedia |
| Population (Census 2021) | **Conflict:** 197,241 (Edusanjal, citing CBS) vs 198,098 (Wikipedia, CCAC) | `[SECONDARY — CONFLICT]` | Resolve from the CBS census portal |
| Directly elected positions (2022) | 102: mayor, deputy mayor, 20 ward chairs, 80 ward members | `[SECONDARY]` + `[DERIVED]` 1 + 1 + 20 + 80 | Wikipedia |
| Executive size | 30: mayor, deputy, 20 ward chairs, 5 women, 3 Dalit/minority | `[DERIVED]` | From 3.1 |
| Official website | itaharimun.gov.np | `[SECONDARY]` | — |
| CBS census portal identifiers | `province=1`, `district=13`, `municipality=6` | `[SECONDARY — observed in URL]` | censusnepal.cbs.gov.np |

Office holders and party results are deliberately **not** listed here. They are time-sensitive data and belong in the database with sources, not in a domain reference document.

### 5.2 Known source conflicts for Itahari (seed test cases)

These are useful as the first real test cases for the conflict-handling UI:

1. **Ward count.** The official municipality page says 26 (pre-2017); secondary sources say 20. The official source is stale.
2. **Population.** 197,241 vs 198,098, both reported as Census 2021 figures.

---

## 6. Dates, numbers and time

### 6.1 Bikram Sambat (BS) calendar

* Official documents, budgets, notices and many citizens use BS dates. The BS year runs roughly 56–57 years ahead of AD.
* **Months:** बैशाख, जेठ, असार, साउन, भदौ, असोज, कात्तिक, मंसिर, पुस, माघ, फागुन, चैत.
* **BS month lengths vary between 29 and 32 days from year to year. There is no arithmetic formula.** Conversion requires a lookup table sourced from the official calendar, covering every year the platform displays, including historical records `[TO VERIFY — table source]`.
* Converters, including existing libraries, must be tested against the official calendar across all supported years. Off-by-one-day errors are the most common failure.

### 6.2 Fiscal year

* The Nepali fiscal year begins on साउन १, around mid-July, and ends at the end of असार `[TO VERIFY]`.
* Fiscal years are written as `2083/84` or `२०८३/८४`. The Itahari website currently lists budget documents for FY २०८३-०८४ `[SECONDARY]`.
* The local budget is presented to the assembly by असार १० `[TO VERIFY]`. This date is a natural anchor for project and promise tracking.

### 6.3 Numbers

* **Nepali digits:** ० १ २ ३ ४ ५ ६ ७ ८ ९
* **Grouping uses lakh and crore:** `1,00,000` (1 lakh), `1,00,00,000` (1 crore). Budget figures are almost always discussed in lakh or crore. Showing "12.5 crore" is more understandable than "125,000,000".
* **Currency:** NPR, written रु. / Rs.

### 6.4 Time

* **Time zone:** `Asia/Kathmandu`, UTC+05:45. It is not a whole or half hour; test any code that assumes it is.
* Store timestamps in UTC and render them in Nepal time.

---

## 7. Language, names and search

### 7.1 Unicode and legacy fonts

* All stored text is Unicode, normalized to NFC.
* **Many government PDFs and notices in Nepal use legacy non-Unicode fonts, such as Preeti.** Text extracted from these files looks like Latin gibberish. The OCR and ingestion pipeline needs Preeti → Unicode conversion as a first-class step, not an afterthought `[TO VERIFY — prevalence in Itahari documents]`.
* **Spelling variants in Devanagari** (for example chandrabindu vs anusvara, and variant spellings like गाउँ / गाँउ) must be handled in search normalization, not "corrected" in stored official names.

### 7.2 Personal names

* Do not assume a first name / last name structure. Store the full name as written in Devanagari (canonical) and a romanized form, with optional aliases.
* The same person may be romanized differently across sources (for example Paudel / Poudel / Poudyal). **Never merge two person records automatically on name similarity alone.** Merges require moderator review.
* **Name order and honorifics** (श्री, श्रीमती, सुश्री) should be stripped for matching but must not be added to display names.

### 7.3 Place names and search

Search must match:

* Devanagari input
* romanized input
* common misspellings
* legacy names (pre-2017 VDC names, old province numbers)
* **mixed queries** such as "itahari वडा ४" or "ward 4 इटहरी"

---

## 8. Terminology glossary

Canonical terms for code, English UI and Nepali UI.

* **Rule:** code uses the `key` column; UI copy uses the Nepali and English columns.
* Nepali UI terms are a **first draft that must be reviewed by a native Nepali UX writer** before launch, per project instructions §16.

### 8.1 Administrative

| key | Nepali | English (canonical) | Notes |
|---|---|---|---|
| `province` | प्रदेश | Province | Avoid "state" in UI |
| `district` | जिल्ला | District | |
| `local_level` | स्थानीय तह | Local level | Generic term covering all four types. Avoid using "municipality" generically |
| `metropolitan_city` | महानगरपालिका | Metropolitan city | |
| `sub_metropolitan_city` | उपमहानगरपालिका | Sub-metropolitan city | |
| `municipality` | नगरपालिका | Municipality | |
| `rural_municipality` | गाउँपालिका | Rural municipality | Avoid "village" |
| `ward` | वडा | Ward | |
| `ward_office` | वडा कार्यालय | Ward office | |
| `vdc_legacy` | गाउँ विकास समिति (गाविस) | VDC (former) | Legacy only; used for search aliases |

### 8.2 Offices and bodies

| key | Nepali | English (canonical) | Notes |
|---|---|---|---|
| `mayor` | प्रमुख / नगर प्रमुख | Mayor | Urban only |
| `deputy_mayor` | उपप्रमुख | Deputy Mayor | Urban only |
| `chairperson` | अध्यक्ष | Chairperson | Rural only |
| `vice_chairperson` | उपाध्यक्ष | Vice-Chairperson | Rural only |
| `ward_chair` | वडाध्यक्ष / वडा अध्यक्ष | Ward chairperson | English press also uses "ward chair" and "ward president". Support both in search |
| `ward_member` | वडा सदस्य | Ward member | |
| `seat_woman` | महिला सदस्य | Woman member (seat) | Seat category |
| `seat_dalit_woman` | दलित महिला सदस्य | Dalit woman member (seat) | Seat category; see R9 |
| `executive` | कार्यपालिका | Executive | |
| `assembly` | सभा (नगर सभा / गाउँ सभा) | Assembly | |
| `judicial_committee` | न्यायिक समिति | Judicial Committee | |
| `ward_committee` | वडा समिति | Ward Committee | |
| `dcc` | जिल्ला समन्वय समिति | District Coordination Committee | Not a government tier |
| `cao` | प्रमुख प्रशासकीय अधिकृत | Chief Administrative Officer | Appointed |

### 8.3 Elections

| key | Nepali | English (canonical) | Notes |
|---|---|---|---|
| `ecn` | निर्वाचन आयोग, नेपाल | Election Commission Nepal | |
| `election` | निर्वाचन / चुनाव | Election | निर्वाचन is formal; चुनाव is everyday speech. Consider चुनाव for headings |
| `by_election` | उपनिर्वाचन | By-election | |
| `candidate` | उम्मेदवार | Candidate | |
| `candidacy` | उम्मेदवारी | Candidacy | |
| `nomination` | मनोनयन | Nomination | |
| `independent` | स्वतन्त्र | Independent | |
| `election_symbol` | निर्वाचन चिन्ह | Election symbol | |
| `voter_roll` | मतदाता नामावली | Voter roll | |
| `polling_station` | मतदान केन्द्र | Polling station | |
| `code_of_conduct` | निर्वाचन आचारसंहिता | Election code of conduct | |
| `manifesto` | घोषणापत्र | Manifesto | |
| `promise` | प्रतिबद्धता / चुनावी वाचा | Promise / commitment | प्रतिबद्धता sounds formal; वाचा sounds everyday. **Needs UX review** |

### 8.4 Civic life and platform

| key | Nepali | English (canonical) | Notes |
|---|---|---|---|
| `issue` | समस्या | Issue / problem | Citizen-facing term for reported problems |
| `grievance` | गुनासो | Grievance | Formal complaint to the local level. Maps to the "reported to authority" stage, and must stay distinct from `issue` |
| `project` | योजना / आयोजना | Project | |
| `users_committee` | उपभोक्ता समिति | Users' committee | Common implementers of small local projects |
| `tole_org` | टोल विकास संस्था | Tole development organization | Neighbourhood-level organization |
| `budget` | बजेट | Budget | |
| `fiscal_year` | आर्थिक वर्ष | Fiscal year | |
| `policy_programme` | नीति तथा कार्यक्रम | Policy and programme | Annual document |
| `recommendation` | सिफारिस | Recommendation letter | A common ward-office service |
| `vital_registration` | घटना दर्ता | Vital event registration | A common ward-office service |
| `source` | स्रोत | Source | |
| `verified` | प्रमाणित | Verified | |
| `unverified_claim` | अप्रमाणित दाबी | Unverified claim | |

---

## 9. Domain rules for the data model

These are **decisions**. `05_DOMAIN_DATA_MODEL.md` must implement all of them. Changing any rule requires an entry in `DECISIONS.md`.

### Administrative units

**R1 — Hierarchy is data.**
* Levels: `country → province → district → local_level → ward`.
* Local level type is an enum: `metropolitan_city | sub_metropolitan_city | municipality | rural_municipality`.
* No unit names, counts or ward numbers appear in application logic.

**R2 — Ward identity.**
* A ward is identified by its internal ID.
* Its natural key is `(local_level_id, ward_number, validity period)`.
* A ward number alone is never an identifier.

**R3 — Temporal versioning.**
* Every administrative unit has `valid_from` / `valid_to`, with predecessor/successor links for merges, splits and upgrades.
* Names are versioned separately from identity: a rename is not a new unit.

**R4 — External identifiers.**
* Internal UUIDs are primary keys.
* Official codes (CBS, ECN, MoFAGA, Survey Department) are stored as `(scheme, code)` pairs per unit.
* The platform never invents codes that could be mistaken for official ones.

**R5 — Slugs are presentation.**
* URL slugs (for example `/ward/koshi/sunsari/itahari/4`) are stable, lowercase and romanized.
* They resolve to IDs. A rename keeps a 301 redirect from the old slug.

**R6 — Multilingual names.**
* Each named entity has `name_ne` (Devanagari, canonical) and `name_en` (romanized canonical).
* An alias list covers search variants and legacy names.

### Elections and offices

**R7 — Election structure.**
* `election → contest → candidacy`.
* A contest is `position × constituency` within one election.
* A constituency is **either** a ward **or** a whole local level.
* A candidate never has a bare `ward` field.

**R8 — Position catalogue is data.**
* Positions are defined per local level type, each with `seat_category` (`open | woman | dalit_woman`), `election_method` (`direct | indirect`) and seat count.
* This keeps the model safe if the Election Management Bill changes the structure.

**R9 — Seat category belongs to the seat, not the person.**
* The platform records the seat a candidate contests, as labelled by the ECN.
* It does not store, infer or filter on a person's caste or ethnicity.

**R10 — Candidacy ≠ office holding.**
* Office holdings are separate records: `person`, `position`, `constituency`, `start_date`, `end_date`, `end_reason` (`term_end | resignation | death | removal | other`), and sources.
* This supports vacancies, by-elections and early departures.

**R11 — Zero-candidate contests are valid.**
* A contest with no candidacies is stored and displayed explicitly.

**R12 — Elected vs appointed.**
* Roles carry an `appointment_type` (`elected_direct | elected_indirect | appointed_staff`).
* Accountability features, such as promises, apply only to elected roles.

**R13 — Indirect positions.**
* Indirectly elected executive members are modelled as office holders.
* They are excluded from the MVP candidate directory `[ASSUMPTION]`.

### Dates, numbers and sources

**R14 — Dates.**
* Timestamps are stored in UTC.
* Calendar dates are stored as AD dates, with BS computed via a validated lookup table.
* When a document states a date in BS, also store the original text (`date_as_written`), so conversion errors can be audited.

**R15 — Fiscal years are entities.**
* `FY 2083/84` is a first-class record mapped to an AD date range. Budgets and projects reference it.
* Numbers are stored as numbers and formatted by locale, with optional Nepali digits and lakh/crore grouping.

**R16 — Freshness is separate from authority.**
* Every sourced fact has both a source authority (from the source hierarchy) and a recency (`published_at` / `retrieved_at`).
* A higher-authority but stale source does not silently override a newer lower-authority one. The conflict is surfaced for editorial review.
* See 5.2 for the Itahari example.

---

## 10. Open questions and verification backlog

Ordered by how badly each one blocks the pilot.

| # | Question | Blocks | Where to verify |
|---|---|---|---|
| 1 | Are any Itahari elected positions vacant after the 2026 federal-election resignations? Were by-elections held? | Pilot seed data | ECN; Itahari SMC; local press |
| 2 | Official local election date for 2027, whether it is combined with the provincial elections, and the nomination timeline | MVP deadline | ECN notices |
| 3 | Status and content of the Election Management Bill. Does it change positions, seats or nomination rules? | R8 flexibility check | Parliament; ECN; legal review |
| 4 | Authoritative source and licence for ward boundary geometry | Issue map | Survey Department; CBS; open datasets (licence check) |
| 5 | Canonical Itahari population (resolve the 197,241 vs 198,098 conflict), including ward-level census figures | Ward profiles | CBS census portal |
| 6 | Official BS↔AD calendar table source and year coverage | Dates | Official calendar publication |
| 7 | Voting rules for citizens away from their registered location | Election info content | ECN |
| 8 | Usage rights for party election symbols | Candidate cards | ECN; legal review |
| 9 | Official codes schemes (CBS vs ECN vs MoFAGA). Which to treat as primary external ID? | R4 | CBS; ECN; MoFAGA |
| 10 | Prevalence of Preeti/legacy-font PDFs in Itahari notices and budgets | OCR pipeline | Sample the Itahari SMC website |
| 11 | Confirm the `[TO VERIFY]` items in sections 2.3, 2.4, 4.3, 6.2 | Correctness | Official sources |
| 12 | Native-speaker review of all Nepali UI terms in section 8 | Localization | Nepali UX writer |

---

## 11. Sources consulted (v0.1)

All are secondary sources unless marked otherwise. Accessed 2026-09-15.

* IFES — *Election FAQs: Nepal 2022 Local Election* — https://www.ifes.org/sites/default/files/migrate/ifes_faqs_elections_in_nepal_2022_local_elections_0.pdf
* IFES — *Elections in Nepal: 2026 General Elections* — https://www.ifes.org/tools-resources/election-snapshots/elections-nepal-2026-general-elections
* IFES — *Election FAQs: Nepal 2026 General Elections* — https://www.ifes.org/sites/default/files/2026-03/2026_Nepal_General%20Elections%20FAQs.pdf
* The Rising Nepal — *EC proposes unified provincial assembly, local-level elections* — https://risingnepaldaily.com/news/80299
* The Kathmandu Post — *How women candidates fared in local polls* (28 May 2022) — https://kathmandupost.com/politics/2022/05/28/how-women-candidates-fared-in-local-polls
* UN Women — *Women in Local Government: Nepal* — https://localgov.unwomen.org/country/NPL
* UNDP / National Women Commission — *GESI in Local Level Elections* report — https://www.undp.org/sites/g/files/zskgke326/files/2023-02/UNDP-NP-GESI-Report-Eng-final-web-version.pdf
* The Asia Foundation — *Seven Years into Federalism* — https://asiafoundation.org/seven-years-into-federalism-is-nepals-glass-half-empty-or-half-full/
* Edusanjal — *Itahari Sub-Metropolitan City* — https://edusanjal.com/local-level/itahari/
* Wikipedia — *Itahari*; *2022 Itahari municipal election* (orientation only; never a seed source)
* Itahari Sub-Metropolitan City Office — *Brief Introduction* — https://itaharimun.gov.np/en/node/13 (official, but stale; see 2.6)
* CBS — *National Population and Housing Census 2021* portal — https://censusnepal.cbs.gov.np (official; figures not yet extracted)

---

## Change log

| Version | Date | Change |
|---|---|---|
| 0.1 | 2026-09-15 | Initial draft: hierarchy, local government structure, election positions, Itahari pilot facts, calendar and language rules, glossary, domain rules R1–R16, verification backlog |
