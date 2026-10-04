/**
 * The read API, typed.
 *
 * Every call runs on the server: the browser never talks to Laravel directly,
 * so there is no public API host to configure and nothing environment-specific
 * in the bundle (D-015). `API_INTERNAL_URL` names the Compose service and is
 * the same string in every environment.
 *
 * Failures return null rather than throwing. A ward page that renders with a
 * clear "we cannot reach this right now" is a better answer for a citizen on a
 * patchy connection than a stack trace or a blank screen (§17), and the caller
 * decides between that and a 404.
 */

const ORIGIN = process.env.API_INTERNAL_URL ?? 'http://127.0.0.1:8000';

/** Both scripts travel together; the page picks one (§16). */
export type Bilingual = { ne: string | null; en: string | null };

export type LocalLevelType =
  | 'metropolitan_city'
  | 'sub_metropolitan_city'
  | 'municipality'
  | 'rural_municipality';

export type SeatState = 'held' | 'vacant' | 'not_verified';

export type LocalLevelSummary = {
  slug_path: string;
  name: Bilingual;
  type: LocalLevelType | null;
  district: Bilingual;
  province: Bilingual;
  wards: number;
};

export type Seat = {
  position_key: string;
  title: Bilingual;
  seat_category: 'open' | 'woman' | 'dalit_woman' | 'dalit_minority';
  seat_index: number;
  ballot_order: number | null;
  constituency_level: 'local_level' | 'ward';
  ward_number: number | null;
  state: SeatState;
  is_independent: boolean;
  term_label: string | null;
  started_on: string | null;
  person: { slug: string; name: Bilingual } | null;
  party: { slug: string; name: Bilingual; abbreviation: Bilingual } | null;
  vacancy: { reason: string; since: string | null } | null;
  /**
   * Two or more sources disagree about one of this seat's fields.
   *
   * Separate from `state` on purpose: a holding can be verifiably real while
   * the party it records is disputed. The row shows the value AND says the
   * sources disagree.
   */
  has_source_conflict: boolean;
  /**
   * The record this seat's sources hang off, or null when nothing is recorded
   * at all. Null and "recorded but unsourced" are different: the first has no
   * page to show, the second has a page that says plainly there is no evidence
   * yet.
   */
  evidence: EvidenceRef | null;
};

/** One hit from the search endpoint — an address, not a page's worth of content. */
export type SearchHit = {
  type: 'local_level' | 'ward';
  slug_path: string;
  /** Present on a ward hit; the client renders it in the reader's own digits. */
  ward_number: number | null;
  name: Bilingual;
  local_level_type: LocalLevelType | null;
  district: Bilingual;
  province: Bilingual;
};

/** Every address the public site has a real page for, for the sitemap. */
export type PublishedPath = {
  slug_path: string;
  updated_at: string | null;
  wards: { number: number; updated_at: string | null }[];
  /**
   * Person pages in this municipality, from the central index (D-020: a
   * person page lives at the address of a municipality they sit in). `path`
   * is relative to the locale root, e.g. `person/koshi/sunsari/x/some-name`.
   * Absent from responses by an older API, hence optional.
   */
  people?: { path: string; updated_at: string | null }[];
};

export type Coverage = { total: number; held: number; vacant: number; not_verified: number };

/**
 * Where to read the working for one record (HW-E04-F02).
 *
 * `subject_type` is a stable key, not a class name, so a link a citizen shared
 * keeps resolving across refactors.
 */
export type EvidenceRef = { subject_type: string; subject_id: string };

export type EvidenceSource = {
  source_id: string;
  title: string;
  publisher: string | null;
  url: string | null;
  source_type: {
    key: string;
    label: Bilingual;
    /** 1 is the Election Commission; higher is less authoritative. */
    authority_rank: number;
  };
  provenance_type: ProvenanceKind;
  verification_status: 'unverified' | 'verified' | 'disputed' | 'rejected';
  /** What THIS source says the value is — not necessarily what the site shows. */
  asserted_value: string | null;
  locator: string | null;
  excerpt: string | null;
  published_at: string | null;
  retrieved_at: string | null;
};

export type ProvenanceKind =
  | 'official'
  | 'candidate_submitted'
  | 'public_record'
  | 'verified_community_report'
  | 'community_report'
  | 'media_report'
  | 'ai_generated_summary'
  | 'unverified_claim';

export type EvidenceDetail = {
  subject_type: string;
  subject_id: string;
  is_empty: boolean;
  has_conflict: boolean;
  /** Sources for the record as a whole. */
  record: EvidenceSource[];
  /** Sources for individual values, where disagreement lives. */
  fields: { field_path: string; in_conflict: boolean; sources: EvidenceSource[] }[];
};

export type PersonDetail = {
  slug: string;
  name: Bilingual;
  local_level: {
    slug_path: string;
    name: Bilingual;
    type: LocalLevelType | null;
    district: Bilingual;
    province: Bilingual;
  };
  seats: Seat[];
  evidence: EvidenceRef;
};

export type LocalLevelDetail = {
  slug_path: string;
  name: Bilingual;
  type: LocalLevelType | null;
  district: Bilingual;
  province: Bilingual;
  tenant_key: string;
  wards: { number: number; name: Bilingual; slug_path: string | null }[];
  leadership: Seat[];
  coverage: Coverage;
};

export type WardOffice = {
  address: Bilingual;
  phone: string | null;
  alternate_phone: string | null;
  email: string | null;
  office_hours: Bilingual;
};

export type WardDetail = {
  ward_number: number;
  slug_path: string | null;
  name: Bilingual;
  local_level: {
    slug_path: string;
    name: Bilingual;
    type: LocalLevelType | null;
    district: Bilingual;
    province: Bilingual;
  };
  seats: Seat[];
  coverage: Coverage;
  ward_office: WardOffice | null;
};

/** Distinguishes "not here" from "could not ask", which the pages treat differently. */
export type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number };

async function get<T>(path: string, revalidate: number): Promise<ApiResult<T>> {
  try {
    const response = await fetch(`${ORIGIN}/api/v1${path}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate },
    });

    if (!response.ok) {
      return { ok: false, status: response.status };
    }

    const body = (await response.json()) as { data: T };

    return { ok: true, data: body.data };
  } catch {
    // The API container is down or still booting. 0 means "never reached it".
    return { ok: false, status: 0 };
  }
}

/**
 * The same request, deliberately uncached.
 *
 * ISR keys on the URL, so caching a search would mint one cache entry per
 * distinct query string — including every typo on the way to a real one. That
 * cache only grows and is almost never hit twice, which is the worst shape a
 * cache can have. The endpoint it fronts scans a few thousand rows and is
 * cheaper than the entry would be.
 */
async function getUncached<T>(path: string): Promise<ApiResult<T>> {
  try {
    const response = await fetch(`${ORIGIN}/api/v1${path}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok) {
      return { ok: false, status: response.status };
    }

    const body = (await response.json()) as { data: T };

    return { ok: true, data: body.data };
  } catch {
    return { ok: false, status: 0 };
  }
}

/**
 * Cached for five minutes. The list of municipalities changes when one is
 * onboarded, which is a deliberate act nobody is waiting on.
 */
export function fetchLocalLevels(): Promise<ApiResult<LocalLevelSummary[]>> {
  return get<LocalLevelSummary[]>('/local-levels', 300);
}

export function fetchLocalLevel(path: string): Promise<ApiResult<LocalLevelDetail>> {
  return get<LocalLevelDetail>(`/local-levels/${path}`, 120);
}

/**
 * Shorter cache than the picker: a seat changing from not_verified to held is
 * the thing an editor is watching for after they verify a source.
 */
export function fetchWard(path: string, ward: number): Promise<ApiResult<WardDetail>> {
  return get<WardDetail>(`/wards/${path}/${ward}`, 60);
}

/**
 * The person behind a seat row. Same cache window as a ward: the two pages show
 * the same holding from different directions and should not disagree about it.
 */
export function fetchPerson(path: string, slug: string): Promise<ApiResult<PersonDetail>> {
  return get<PersonDetail>(`/persons/${path}/${encodeURIComponent(slug)}`, 60);
}

/**
 * The sources behind one record.
 *
 * Cached for a minute, like the pages that link to it. Longer would be worse
 * than it looks: the moment an editor verifies a source is exactly when someone
 * is refreshing to see whether it took, and an evidence page that lags the seat
 * state it explains is its own small credibility problem.
 */
export function fetchEvidence(
  path: string,
  subjectType: string,
  subjectId: string,
): Promise<ApiResult<EvidenceDetail>> {
  return get<EvidenceDetail>(
    `/evidence/${path}/${encodeURIComponent(subjectType)}/${encodeURIComponent(subjectId)}`,
    60,
  );
}

/**
 * Place search. Not cached: a query string is unbounded, and an ISR entry per
 * distinct typo is a cache that only ever grows and never gets a hit.
 */
export async function fetchSearch(query: string): Promise<ApiResult<SearchHit[]>> {
  return getUncached<SearchHit[]>(`/search?q=${encodeURIComponent(query)}`);
}

/**
 * The sitemap feed. An hour, because a sitemap is read by crawlers on their own
 * schedule and a municipality opening is not an event anyone is refreshing for.
 */
export function fetchPublishedPaths(): Promise<ApiResult<PublishedPath[]>> {
  return get<PublishedPath[]>('/published-paths', 3600);
}

/** Either script, preferring the reader's own (§16). */
export function pick(value: Bilingual, locale: string): string {
  const preferred = locale === 'en' ? value.en : value.ne;

  return preferred ?? value.en ?? value.ne ?? '';
}

