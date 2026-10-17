import { fieldErrors, firstError, fortifyRequest } from '@/lib/fortify';

/**
 * The citizen account screens' side of Fortify (HW-E30-F01-T03; docs/12 §11).
 *
 * The plumbing is shared with the staff dashboard (lib/fortify.ts). What is
 * here is what the public site does differently: it never has a two-step
 * challenge, it has a sign-up, and every one of its answers has to be read
 * through the rule that sign-up follows — the API deliberately gives the same
 * reply whether or not an address already has an account, so these screens
 * must never put words on screen that undo that.
 */

export type Me = {
  display_name: string;
  preferred_locale: 'ne' | 'en';
  email_verified: boolean;
};

/** Who is signed in, or null. 401 is the normal answer for a visitor. */
export async function fetchMe(): Promise<Me | null> {
  const response = await fortifyRequest('GET', '/api/v1/me');

  return response.status === 200 ? ((response.body as { data: Me }).data ?? null) : null;
}

/** What a form submission came back as. */
export type FormOutcome =
  | { kind: 'ok'; body: unknown }
  | { kind: 'invalid'; errors: Record<string, string>; message: string }
  | { kind: 'throttled'; retryAfterSeconds: number }
  | { kind: 'failed'; message: string };

/**
 * One reading of a Fortify answer, used by every form on these screens.
 *
 * `fallback` is what to say when the API gives no message of its own, which
 * happens on a 500 or a dropped connection.
 */
export function interpret(
  status: number,
  body: unknown,
  retryAfter: string | null,
  fallback: string,
): FormOutcome {
  if (status >= 200 && status < 300) {
    return { kind: 'ok', body };
  }

  if (status === 422) {
    return {
      kind: 'invalid',
      errors: fieldErrors(body),
      message: firstError(body) ?? fallback,
    };
  }

  if (status === 429) {
    return { kind: 'throttled', retryAfterSeconds: Number(retryAfter ?? 60) || 60 };
  }

  return { kind: 'failed', message: firstError(body) ?? fallback };
}

/** "Wait about 3 minutes and try again." — rounded up, never "0 minutes". */
export function waitMessage(retryAfterSeconds: number, template: string): string {
  const minutes = Math.max(1, Math.ceil(retryAfterSeconds / 60));

  return template.replaceAll('{minutes}', String(minutes));
}

/**
 * The token and address a reset link carries.
 *
 * Both come from the query string, so both are attacker-controlled: they are
 * passed straight back to the API, which is the only thing that can say
 * whether the pair is real.
 */
export function resetCredentials(search: URLSearchParams): { token: string; email: string } {
  return { token: search.get('token') ?? '', email: search.get('email') ?? '' };
}

export { ensureCsrfCookie, fortifyRequest } from '@/lib/fortify';
