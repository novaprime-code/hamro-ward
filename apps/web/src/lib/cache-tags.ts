/**
 * Cache tags, built in one place so the API fetches and Laravel's
 * revalidation signals agree (HW-E08-F01-T04). Kept apart from
 * lib/revalidate.ts, which needs node:crypto, so anything may import it.
 */
export const cacheTags = {
  /** Every public page: a deploy, or a central change that touches many places. */
  public: 'public',
  /** Lists spanning municipalities: the picker, published paths. */
  index: 'index',
  /** Everything at one municipality's address: its page, wards, people, evidence. */
  place: (path: string): string => `place:${path}`,
} as const;
