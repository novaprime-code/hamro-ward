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
};

export type Coverage = { total: number; held: number; vacant: number; not_verified: number };

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

/** Either script, preferring the reader's own (§16). */
export function pick(value: Bilingual, locale: string): string {
  const preferred = locale === 'en' ? value.en : value.ne;

  return preferred ?? value.en ?? value.ne ?? '';
}
