import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Signed on-demand revalidation (HW-E08-F01-T04, docs/06 §11 TB8).
 *
 * Laravel tells this app which cached pages are stale by POSTing a list of
 * cache tags, signed with a secret both sides hold: HMAC-SHA256 over
 * `${timestamp}.${body}`, hex, with the timestamp in seconds. A request is
 * accepted only inside a five-minute window, so a captured request stops
 * working once that window closes.
 *
 * Kept free of Next.js so the rules can be tested directly.
 */

export const TIMESTAMP_HEADER = 'x-hw-timestamp';
export const SIGNATURE_HEADER = 'x-hw-signature';

/** ±5 minutes (docs/06 §11). */
export const WINDOW_SECONDS = 300;

const MAX_TAGS = 100;

/**
 * The tags this site attaches to its fetches. Anything else is refused rather
 * than passed to revalidateTag: an unknown tag invalidates nothing, so it can
 * only be a mistake or a probe.
 */
const TAG = /^(public|index|place:[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*){2})$/;

export type Verdict = 'ok' | 'unsigned' | 'expired' | 'invalid';

export function sign(timestamp: string, body: string, secret: string): string {
  return createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
}

export function verify(
  timestamp: string | null,
  signature: string | null,
  body: string,
  secret: string,
  nowSeconds: number = Math.floor(Date.now() / 1000),
): Verdict {
  if (timestamp === null || signature === null || !/^\d{1,12}$/.test(timestamp)) {
    return 'unsigned';
  }

  if (Math.abs(nowSeconds - Number(timestamp)) > WINDOW_SECONDS) {
    return 'expired';
  }

  const expected = Buffer.from(sign(timestamp, body, secret), 'hex');
  const given = Buffer.from(signature, 'hex');

  // timingSafeEqual throws on unequal lengths, and a length mismatch is itself
  // the answer; comparing in constant time only matters once lengths agree.
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
    return 'invalid';
  }

  return 'ok';
}

/** The body's tags, or null when the body is not exactly `{ "tags": [...] }` of known tags. */
export function parseTags(body: string): string[] | null {
  let parsed: unknown;

  try {
    parsed = JSON.parse(body);
  } catch {
    return null;
  }

  if (typeof parsed !== 'object' || parsed === null || !('tags' in parsed)) {
    return null;
  }

  const { tags } = parsed as { tags: unknown };

  if (
    !Array.isArray(tags) ||
    tags.length === 0 ||
    tags.length > MAX_TAGS ||
    !tags.every((tag): tag is string => typeof tag === 'string' && TAG.test(tag))
  ) {
    return null;
  }

  return [...new Set(tags)];
}

/**
 * A secret still set to the shipped placeholder is treated as no secret: the
 * placeholder is public, in this repository's .env.example files.
 */
export function usableSecret(secret: string | undefined): string | null {
  if (secret === undefined || secret.length < 16 || /change[-_]?me/i.test(secret)) {
    return null;
  }

  return secret;
}
