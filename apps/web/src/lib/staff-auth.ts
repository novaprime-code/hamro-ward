/**
 * The staff dashboard's side of sign-in (HW-E13-F02-T02; docs/12 §11, D-034).
 *
 * The same-origin plumbing — the XSRF cookie, the JSON call, Laravel's error
 * shapes — is shared with the citizen screens in lib/fortify.ts. What is left
 * here is what only staff have: the two-step challenge, and a lockout that
 * says when the next attempt is possible.
 */

import { firstError, minutesUntil } from '@/lib/fortify';

export { ensureCsrfCookie, firstError, minutesUntil, readXsrfToken } from '@/lib/fortify';
export { fortifyRequest as staffRequest } from '@/lib/fortify';
export type { FortifyResponse as StaffResponse } from '@/lib/fortify';

export type StaffMe = {
  id: string;
  name: string;
  email: string;
  operator_admin: boolean;
  memberships: { tenant_key: string; role: string }[];
};

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
