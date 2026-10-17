import { fortifyRequest, type FortifyResponse } from '@/lib/fortify';

/**
 * Saved wards, from the browser (HW-E30-F02-T01/T02; docs/12 §12).
 *
 * Every call goes to `/api/v1/me/wards`, same-origin through the web tier's
 * rewrite, carrying the session cookie and the XSRF token like every other
 * write on this site (lib/fortify.ts).
 */

export const RELATIONSHIPS = [
  'permanent_address',
  'temporary_address',
  'workplace',
  'other',
] as const;

export type Relationship = (typeof RELATIONSHIPS)[number];

/** Only a ward you live in can be the main one — mirrors the API's enum. */
export function canBePrimary(relationship: Relationship): boolean {
  return relationship === 'permanent_address' || relationship === 'temporary_address';
}

export type SavedWard = {
  id: string;
  ward_number: number | null;
  slug_path: string | null;
  local_level: { name: { ne: string | null; en: string | null }; type: string | null };
  relationship: Relationship;
  is_primary: boolean;
  can_be_primary: boolean;
  open: boolean;
  saved_at: string;
};

export type WardList = {
  wards: SavedWard[];
  maxSaved: number;
};

/** Reads a list response, whichever call produced it. */
export function readList(body: unknown): WardList {
  const payload = (body ?? {}) as { data?: unknown; meta?: { max_saved?: unknown } };

  return {
    wards: Array.isArray(payload.data) ? (payload.data as SavedWard[]) : [],
    maxSaved: typeof payload.meta?.max_saved === 'number' ? payload.meta.max_saved : 5,
  };
}

export function listWards(): Promise<FortifyResponse> {
  return fortifyRequest('GET', '/api/v1/me/wards');
}

export function addWard(input: {
  slugPath: string;
  wardNumber: number;
  relationship: Relationship;
  makePrimary: boolean;
}): Promise<FortifyResponse> {
  return fortifyRequest('POST', '/api/v1/me/wards', {
    slug_path: input.slugPath,
    ward_number: input.wardNumber,
    relationship: input.relationship,
    make_primary: input.makePrimary,
  });
}

export function removeWard(id: string): Promise<FortifyResponse> {
  return fortifyRequest('DELETE', `/api/v1/me/wards/${id}`);
}

export function makeWardPrimary(id: string): Promise<FortifyResponse> {
  return fortifyRequest('PUT', `/api/v1/me/wards/${id}/primary`);
}

/**
 * The ward numbers a municipality has, as a list to choose from.
 *
 * Wards are numbered from one with no gaps, which is a property of how local
 * levels are constituted rather than an assumption about our data: the count
 * is enough to know every valid number.
 */
export function wardNumbers(total: number): number[] {
  return Array.from({ length: Math.max(0, total) }, (_, index) => index + 1);
}
