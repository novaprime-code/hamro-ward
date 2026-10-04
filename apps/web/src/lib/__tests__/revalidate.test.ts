import { describe, expect, it } from 'vitest';

import { cacheTags } from '@/lib/cache-tags';
import { WINDOW_SECONDS, parseTags, sign, usableSecret, verify } from '@/lib/revalidate';

const SECRET = 'a-test-secret-that-is-long-enough';
const NOW = 1_800_000_000;
const BODY = JSON.stringify({ tags: ['place:koshi/sunsari/example', 'index'] });

describe('sign', () => {
  it('matches the known-answer vector the Laravel side asserts too', () => {
    // apps/api/tests/Feature/Publishing/RevalidationTest.php pins the same
    // digest: if either side changes what it signs, both suites fail.
    expect(sign(String(NOW), BODY, SECRET)).toBe(
      '2f55c0578b4423cd637598ef922fe15333525560c91d37ae9d6099e235582731',
    );
  });
});

describe('verify', () => {
  it('accepts a body signed with the shared secret inside the window', () => {
    const ts = String(NOW);

    expect(verify(ts, sign(ts, BODY, SECRET), BODY, SECRET, NOW)).toBe('ok');
  });

  it('accepts the edges of the five-minute window and refuses just past them', () => {
    for (const offset of [-WINDOW_SECONDS, WINDOW_SECONDS]) {
      const ts = String(NOW + offset);
      expect(verify(ts, sign(ts, BODY, SECRET), BODY, SECRET, NOW)).toBe('ok');
    }

    for (const offset of [-WINDOW_SECONDS - 1, WINDOW_SECONDS + 1]) {
      const ts = String(NOW + offset);
      expect(verify(ts, sign(ts, BODY, SECRET), BODY, SECRET, NOW)).toBe('expired');
    }
  });

  it('refuses a body changed after signing', () => {
    const ts = String(NOW);
    const signature = sign(ts, BODY, SECRET);

    expect(verify(ts, signature, BODY.replace('index', 'public'), SECRET, NOW)).toBe('invalid');
  });

  it('refuses a signature made with another secret, or for another timestamp', () => {
    const ts = String(NOW);

    expect(verify(ts, sign(ts, BODY, 'another-secret-entirely-xx'), BODY, SECRET, NOW)).toBe('invalid');
    expect(verify(ts, sign(String(NOW - 1), BODY, SECRET), BODY, SECRET, NOW)).toBe('invalid');
  });

  it('refuses missing headers, a malformed timestamp, and a truncated signature', () => {
    const ts = String(NOW);
    const signature = sign(ts, BODY, SECRET);

    expect(verify(null, signature, BODY, SECRET, NOW)).toBe('unsigned');
    expect(verify(ts, null, BODY, SECRET, NOW)).toBe('unsigned');
    expect(verify('12e3', signature, BODY, SECRET, NOW)).toBe('unsigned');
    expect(verify(ts, signature.slice(0, 20), BODY, SECRET, NOW)).toBe('invalid');
  });
});

describe('parseTags', () => {
  it('returns the known tags, without duplicates', () => {
    expect(parseTags(JSON.stringify({ tags: ['index', 'index', cacheTags.place('a/b/c')] }))).toEqual([
      'index',
      'place:a/b/c',
    ]);
  });

  it('refuses anything that is not exactly a list of known tags', () => {
    for (const body of [
      'not json',
      '[]',
      '{}',
      JSON.stringify({ tags: [] }),
      JSON.stringify({ tags: 'index' }),
      JSON.stringify({ tags: ['index', 7] }),
      JSON.stringify({ tags: ['/en/ward/x'] }),
      JSON.stringify({ tags: ['place:../../etc'] }),
      JSON.stringify({ tags: ['place:only/two'] }),
      JSON.stringify({ tags: Array.from({ length: 101 }, () => 'index') }),
    ]) {
      expect(parseTags(body)).toBeNull();
    }
  });
});

describe('usableSecret', () => {
  it('treats the shipped placeholders and short secrets as no secret', () => {
    for (const value of [undefined, '', 'short', 'change-me-in-every-environment', 'CHANGE_ME_RANDOM_HEX']) {
      expect(usableSecret(value)).toBeNull();
    }

    expect(usableSecret(SECRET)).toBe(SECRET);
  });
});
