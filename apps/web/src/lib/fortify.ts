/**
 * Talking to Fortify from the browser (docs/12 §11, D-034).
 *
 * Both sites — the citizen one and the staff dashboard — sign in against the
 * same Laravel endpoints, which next.config.ts rewrites so they are
 * same-origin. That is what makes the session cookie and the XSRF-TOKEN
 * cookie work without any CORS, and it is why these helpers are shared rather
 * than written twice: the host decides which guard Laravel uses, so the
 * browser's side of the conversation is identical.
 *
 * Everything here is pure or a single fetch, so the screens stay testable.
 */

/** The decoded XSRF-TOKEN cookie, or null. Laravel URL-encodes the value. */
export function readXsrfToken(cookieHeader: string): string | null {
  for (const part of cookieHeader.split(';')) {
    const [name, ...rest] = part.trim().split('=');

    if (name === 'XSRF-TOKEN') {
      try {
        return decodeURIComponent(rest.join('='));
      } catch {
        return null;
      }
    }
  }

  return null;
}

/** The first validation message in a Laravel 422 body, if there is one. */
export function firstError(body: unknown): string | null {
  const errors = (body as { errors?: Record<string, unknown> } | null)?.errors;

  if (errors && typeof errors === 'object') {
    for (const messages of Object.values(errors)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') {
        return messages[0];
      }
    }
  }

  const message = (body as { message?: unknown } | null)?.message;

  return typeof message === 'string' ? message : null;
}

/** Every validation message, keyed by field, for showing errors beside inputs. */
export function fieldErrors(body: unknown): Record<string, string> {
  const errors = (body as { errors?: Record<string, unknown> } | null)?.errors;
  const found: Record<string, string> = {};

  if (errors && typeof errors === 'object') {
    for (const [field, messages] of Object.entries(errors)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') {
        found[field] = messages[0];
      }
    }
  }

  return found;
}

export type FortifyResponse = { status: number; body: unknown; retryAfter: string | null };

/** One same-origin JSON call, with the CSRF token on every write. */
export async function fortifyRequest(
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  path: string,
  body?: object,
): Promise<FortifyResponse> {
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    const token = readXsrfToken(document.cookie);

    if (token !== null) {
      headers['X-XSRF-TOKEN'] = token;
    }
  }

  const response = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const text = await response.text();
  let parsed: unknown = null;

  try {
    parsed = text === '' ? null : JSON.parse(text);
  } catch {
    parsed = null;
  }

  return { status: response.status, body: parsed, retryAfter: response.headers.get('Retry-After') };
}

/** Sanctum's CSRF cookie; needed once before the first write in a session. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', cache: 'no-store' });
}

/** Whole minutes until a moment, at least one while it is ahead; 0 once past. */
export function minutesUntil(moment: string, now: Date = new Date()): number {
  const remaining = new Date(moment).getTime() - now.getTime();

  return Number.isNaN(remaining) || remaining <= 0 ? 0 : Math.ceil(remaining / 60_000);
}
