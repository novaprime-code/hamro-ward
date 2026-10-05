import { describe, expect, it } from 'vitest';

import { firstError, interpretSignIn, lockoutMessage, minutesUntil, readXsrfToken } from '@/lib/staff-auth';

describe('readXsrfToken', () => {
  it('finds and decodes the token among other cookies', () => {
    expect(readXsrfToken('a=1; XSRF-TOKEN=eyJpdiI6%3D%3D; b=2')).toBe('eyJpdiI6==');
    expect(readXsrfToken('a=1; b=2')).toBeNull();
  });
});

describe('lockout', () => {
  const now = new Date('2026-10-07T10:28:00Z');

  it('rounds the wait up to whole minutes and says when it ends', () => {
    expect(minutesUntil('2026-10-07T10:42:30Z', now)).toBe(15);
    expect(lockoutMessage('2026-10-07T10:42:30Z', now)).toMatch(/^Too many failed attempts\. Try again in 15 minutes, at \d{2}:\d{2}\.$/);
    expect(lockoutMessage('2026-10-07T10:28:20Z', now)).toContain('1 minute,');
  });

  it('says the lock is over once it is', () => {
    expect(minutesUntil('2026-10-07T10:00:00Z', now)).toBe(0);
    expect(lockoutMessage('2026-10-07T10:00:00Z', now)).toBe('The lock has ended. You can try again now.');
  });
});

describe('interpretSignIn', () => {
  it('reads Fortify’s answers', () => {
    expect(interpretSignIn(200, { two_factor: true })).toEqual({ kind: 'challenge' });
    expect(interpretSignIn(200, { two_factor: false })).toEqual({ kind: 'signed-in' });
    expect(interpretSignIn(423, { locked_until: '2026-10-07T10:42:00Z' })).toEqual({
      kind: 'locked',
      lockedUntil: '2026-10-07T10:42:00Z',
    });
    expect(interpretSignIn(429, {}, '42')).toEqual({ kind: 'throttled', retryAfterSeconds: 42 });
    expect(interpretSignIn(422, { errors: { email: ['These credentials do not match our records.'] } })).toEqual({
      kind: 'rejected',
      message: 'These credentials do not match our records.',
    });
  });

  it('falls back to a plain message', () => {
    expect(interpretSignIn(500, null)).toEqual({
      kind: 'rejected',
      message: 'Those details do not match an active staff account.',
    });
    expect(firstError({ message: 'Unauthenticated.' })).toBe('Unauthenticated.');
  });
});
