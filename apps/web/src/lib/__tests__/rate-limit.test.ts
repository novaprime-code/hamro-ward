import { describe, expect, it } from 'vitest';

import { clientAddress, createRateLimiter } from '@/lib/rate-limit';

describe('createRateLimiter', () => {
  it('allows requests up to the limit and refuses the next one', () => {
    const limiter = createRateLimiter({ limit: 3, windowMs: 60_000 });

    expect(limiter.check('a', 0)).toEqual({ allowed: true, remaining: 2 });
    expect(limiter.check('a', 1)).toEqual({ allowed: true, remaining: 1 });
    expect(limiter.check('a', 2)).toEqual({ allowed: true, remaining: 0 });
    expect(limiter.check('a', 3)).toEqual({ allowed: false, retryAfterSeconds: 60 });
  });

  it('counts each address separately', () => {
    const limiter = createRateLimiter({ limit: 1, windowMs: 60_000 });

    expect(limiter.check('a', 0).allowed).toBe(true);
    expect(limiter.check('b', 0).allowed).toBe(true);
    expect(limiter.check('a', 0).allowed).toBe(false);
  });

  it('starts a new window once the old one has passed', () => {
    const limiter = createRateLimiter({ limit: 1, windowMs: 60_000 });

    expect(limiter.check('a', 0).allowed).toBe(true);
    expect(limiter.check('a', 59_999).allowed).toBe(false);
    expect(limiter.check('a', 60_000).allowed).toBe(true);
  });

  it('rounds Retry-After up, so it never tells a caller to retry at zero', () => {
    const limiter = createRateLimiter({ limit: 1, windowMs: 60_000 });

    limiter.check('a', 0);
    const decision = limiter.check('a', 59_900);

    expect(decision).toEqual({ allowed: false, retryAfterSeconds: 1 });
  });

  it('does not grow without bound when every request is from a new address', () => {
    const limiter = createRateLimiter({ limit: 5, windowMs: 60_000, maxKeys: 10 });

    for (let i = 0; i < 500; i += 1) {
      limiter.check(`address-${i}`, 0);
    }

    expect(limiter.size()).toBeLessThanOrEqual(10);
  });
});

describe('clientAddress', () => {
  it('is null without the header, so container-local traffic is not counted', () => {
    expect(clientAddress(null)).toBeNull();
    expect(clientAddress('')).toBeNull();
  });

  it('reads a single address', () => {
    expect(clientAddress('203.0.113.7')).toBe('203.0.113.7');
  });

  /*
   * The point of the whole design: everything before the last entry was written
   * by the caller. Keying on the first entry would let one forged header hand
   * out a fresh bucket per request.
   */
  it('takes the last entry, which is the one the proxy observed', () => {
    expect(clientAddress('198.51.100.1, 203.0.113.7')).toBe('203.0.113.7');
    expect(clientAddress('spoofed, also-spoofed, 203.0.113.7')).toBe('203.0.113.7');
  });

  it('tolerates the whitespace real proxies emit', () => {
    expect(clientAddress('198.51.100.1,   203.0.113.7 ')).toBe('203.0.113.7');
  });
});
