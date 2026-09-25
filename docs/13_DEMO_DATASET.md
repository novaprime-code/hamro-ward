# Hamro Ward — 13 Demonstration Dataset

**Version:** v0.1 · 2026-09-25
**Owner:** Nova
**Relates to:** `02` §2, `05` §3, `12` §9, D-006, D-015

---

## 0. Why this document exists

Hamro Ward's proposition is that it does not publish claims without evidence. A demonstration that invents officials for a real ward contradicts that proposition more loudly than any feature demonstrates it, and a screenshot outlives its caption.

This document fixes what the demonstration dataset is, so that every mockup, seeded environment and screenshot tells the same story and none of them can be mistaken for a record about a real place or person.

---

## 1. The rule

**Real province, real district, fictional local level.**

Provinces and districts are real, because the administrative hierarchy is the thing being demonstrated and inventing it would misrepresent how Nepal is organised. Every local level is invented, so no real ward ever appears with invented representatives.

Everything below the local level — wards, people, parties, holdings, issues, promises, sources, figures — is fictional.

---

## 2. The four local levels

One per local level type, so the type system is visible in the demonstration itself.

| # | Local level | Nepali | Type | District | Province | Wards | Population (fictional) |
|---|---|---|---|---|---|---|---|
| 01 | Himtara Metropolitan City | हिमतारा महानगरपालिका | Metropolitan City | Kathmandu | Bagmati | 25 | ~620,000 |
| 02 | Koshara Sub-Metropolitan City | कोशारा उपमहानगरपालिका | Sub-Metropolitan City | Sunsari | Koshi | 20 | ~265,000 |
| 03 | Sonapur Municipality | सोनापुर नगरपालिका | Municipality | Rautahat | Madhesh | 11 | ~82,000 |
| 04 | Sainli Rural Municipality | साइँली गाउँपालिका | Rural Municipality | Baitadi | Sudurpashchim | 7 | ~21,500 |

**Koshara is the primary demonstration local level.** Ward screens, the ward plate, the representative list and the source screens all use Koshara ward 4. The other three appear in the municipality picker and in the multi-tenant staff screens, which is where type variety earns its place.

### 2.1 Profiles

Each local level carries a profile so that seeded issues, promises and projects are coherent rather than random. A pothole in a metropolitan commercial ward and a suspension bridge in a mountain rural municipality are different civic problems, and a demonstration that mixes them at random looks synthetic.

| Local level | Character | Issues that fit |
|---|---|---|
| Himtara | Urban centre: technology, finance, education, tourism, heritage zones, electric bus corridors | Traffic, parking, waste collection, heritage permits, street lighting |
| Koshara | Eastern Terai industrial and trade centre: manufacturing, agriculture, logistics, flood management | Road condition, drainage, river embankments, market sanitation, industrial effluent |
| Sonapur | Terai market town: agriculture, retail, rice mills, central market serving villages | Irrigation, bus park, drainage, market waste, school buildings |
| Sainli | Mountain rural community: agriculture, forestry, herbs, remittances, emerging homestay tourism | Rural roads, suspension bridges, drinking water, health post staffing, school access |

### 2.2 Structural notes

* **Kathmandu district really has 11 local levels — 1 metropolitan city and 10 municipalities, and no rural municipality at all.** Himtara is therefore a second metropolitan city in that district. That is unusual but not impossible (Sunsari really does contain two sub-metropolitan cities), and it is acceptable for a demonstration whose local levels are invented anyway.
* Ward counts follow the type: metropolitan cities run to the high twenties, sub-metropolitan cities around twenty, municipalities nine to fifteen, rural municipalities five to nine.
* Seat counts are **not** set here. They come from the positions catalogue (`05` §5.1) applied to each local level's type, which is the point of that catalogue.

---

## 3. Names

### 3.1 People

No demonstration person may carry the name of a real public figure.

The first version of the mockups used **राम बहादुर थापा** as the ward chair — the name of a real, prominent Nepali politician — complete with verified official sources. That is fabricated information about a named individual, and it was removed.

Names now in use are ordinary Nepali given-name and surname combinations chosen so that no full name matches a recognisable public figure. Surnames are kept regionally plausible so a ward still reads like a Nepali ward.

`PersonFactory` builds test names from invented syllables for the same reason.

### 3.2 Parties

Invented only: `उदाहरण दल / Example Party`, `नमूना पार्टी / Sample Party`, and similar. **No real party name appears anywhere**, in a factory, a fixture or a mockup. A real party's name beside an invented promise is the same problem as a real politician's name beside an invented record.

### 3.3 Local levels

The four names above are invented. Before any demonstration becomes publicly reachable they must be checked against the official MoFAGA list of 753 local levels — a name that accidentally matches a real one turns this whole scheme back into the problem it avoids.

`DemoDataSeeder` performs that check automatically against published `admin_units` once the geography import has run, and refuses to seed on a collision.

---

## 4. Rules for seeded demonstration data

1. **Never a real national institution.** Demonstration sources may be of type `local_level`, `ward_office`, `community` or `social_media` — all of which belong to the invented municipality, which impersonates nobody. They may **never** be `ecn` or `government_of_nepal`: those name real institutions, and fabricated data must not borrow their authority.

   This was written more strictly in the first draft (`community` and `social_media` only), which would have made the "official source" badge undemonstrable — and that badge is one of the things worth demonstrating. The line that matters is the real-institution one.

   Demonstration source URLs use the reserved `.example` domain, which can never be registered, so a demonstration link cannot one day resolve to a stranger's website.

   The mockups are a separate case: they illustrate the provenance interface itself, carry a permanent fictional-data banner, and may show an Election Commission source label to demonstrate what that label looks like.
2. **Refuses to run in production** unless `HW_ALLOW_DEMO_DATA=true` is set explicitly.
3. **Deterministic.** Fixed UUIDs and a fixed random seed, so re-running produces identical data and screenshots stay valid.
4. **Its own tenants.** Demonstration local levels are ordinary tenants with their own databases, dropped with one command when no longer wanted.
5. **Not indexable.** `noindex` on demonstration routes, so a demonstration ward page never ranks for a real place name.
6. **Marked, permanently.** A banner on every page, and a `नमुना डेटा · DEMO` chip on every screen, generated by one CSS rule so a new screen cannot be added without it.

---

## 5. What the dataset must show

A demonstration where every seat is filled and every fact is verified demonstrates a directory. What distinguishes Hamro Ward is the awkward states, so the seed deliberately includes:

* a ward with mixed seat states — some `held`, one `vacant` with a source, several `not_verified`
* a reserved Dalit woman ward-member seat with `no_candidate`, the real 2022 pattern (`02` §4.2)
* one source conflict: two sources disagreeing, both preserved, shown side by side
* an unverified claim rendered as an unverified claim beside a verified fact
* issues across every moderation state, including one rejected
* a promise part-way through its timeline, and one with no evidence either way

---

## 6. Retirement

The dataset exists until real data replaces it. Retirement is dropping the demonstration tenants and no longer running the seeder; no application code changes, because the seeder writes through the same schema and the same actions as the importer.

---

## Change log

| Date | Change |
|---|---|
| 2026-09-25 | v0.1. Four local levels fixed, one per type. Real-politician name removed from the mockups, real districts restored as containers, demonstration marking added to the theme. |
