/**
 * The staff dashboard's side of sign-in (HW-E13-F02-T02; docs/12 §11, D-034).
 *
 * Every call is same-origin — /sanctum, /login, /two-factor-challenge and
 * /user/* are rewritten to Laravel by next.config.ts — so the session cookie
 * (hw_staff_session, host-only) and the XSRF-TOKEN cookie flow without any
 * CORS. Laravel's CSRF check wants the XSRF-TOKEN cookie's value echoed in the
 * X-XSRF-TOKEN header on every write, which is what `staffRequest` does.
 *
 * The parsing and wording live in pure functions so they can be tested
 * without a browser.
 */

export type StaffMe = {
  id: string;
  name: string;
  email: string;
  operator_admin: boolean;
  memberships: { tenant_key: string; role: string }[];
};

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

/** Whole minutes until a lock ends, at least one while it lasts; 0 once over. */
export function minutesUntil(lockedUntil: string, now: Date = new Date()): number {
  const remaining = new Date(lockedUntil).getTime() - now.getTime();

  return Number.isNaN(remaining) || remaining <= 0 ? 0 : Math.ceil(remaining / 60_000);
}

/** "Try again in 14 minutes, at 10:42." — the retry time the spec asks for. */
export function lockoutMessage(lockedUntil: string, now: Date = new Date()): string {
  const minutes = minutesUntil(lockedUntil, now);

  if (minutes === 0) {
    return 'The lock has ended. You can try again now.';
  }

  const at = new Date(lockedUntil).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

  return `Too many failed attempts. Try again in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}, at ${at}.`;
}

/** What a sign-in response means for the next screen. */
export type SignInOutcome =
  | { kind: 'challenge' }
  | { kind: 'signed-in' }
  | { kind: 'locked'; lockedUntil: string }
  | { kind: 'throttled'; retryAfterSeconds: number }
  | { kind: 'rejected'; message: string };

export function interpretSignIn(status: number, body: unknown, retryAfter: string | null = null): SignInOutcome {
  const data = (body ?? {}) as Record<string, unknown>;

  if (status === 200) {
    return data.two_factor === true ? { kind: 'challenge' } : { kind: 'signed-in' };
  }

  if (status === 423 && typeof data.locked_until === 'string') {
    return { kind: 'locked', lockedUntil: data.locked_until };
  }

  if (status === 429) {
    return { kind: 'throttled', retryAfterSeconds: Number(retryAfter ?? 60) || 60 };
  }

  return { kind: 'rejected', message: firstError(data) ?? 'Those details do not match an active staff account.' };
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

export type StaffResponse = { status: number; body: unknown; retryAfter: string | null };

/** One same-origin JSON call, with the CSRF token on every write. */
export async function staffRequest(method: 'GET' | 'POST' | 'DELETE', path: string, body?: object): Promise<StaffResponse> {
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
