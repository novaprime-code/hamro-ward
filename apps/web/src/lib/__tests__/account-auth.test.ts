import { describe, expect, it } from 'vitest';

import { interpret, resetCredentials, waitMessage } from '@/lib/account-auth';
import { fieldErrors } from '@/lib/fortify';

describe('interpret', () => {
  it('reads a success, whatever 2xx it is', () => {
    expect(interpret(200, { two_factor: false }, null, 'fallback')).toEqual({
      kind: 'ok',
      body: { two_factor: false },
    });
    expect(interpret(201, { status: 'verification_sent' }, null, 'fallback')).toEqual({
      kind: 'ok',
      body: { status: 'verification_sent' },
    });
    expect(interpret(204, null, null, 'fallback')).toEqual({ kind: 'ok', body: null });
  });

  it('keeps every field error so each can sit beside its own input', () => {
    const outcome = interpret(
      422,
      {
        message: 'The display name must be at least 2 characters.',
        errors: {
          display_name: ['The display name must be at least 2 characters.'],
          email: ['The email has already been taken.'],
        },
      },
      null,
      'fallback',
    );

    expect(outcome).toEqual({
      kind: 'invalid',
      message: 'The display name must be at least 2 characters.',
      errors: {
        display_name: 'The display name must be at least 2 characters.',
        email: 'The email has already been taken.',
      },
    });
  });

  it('reads a throttle, defaulting the wait when the header is missing', () => {
    expect(interpret(429, {}, '150', 'fallback')).toEqual({ kind: 'throttled', retryAfterSeconds: 150 });
    expect(interpret(429, {}, null, 'fallback')).toEqual({ kind: 'throttled', retryAfterSeconds: 60 });
    expect(interpret(429, {}, 'soon', 'fallback')).toEqual({ kind: 'throttled', retryAfterSeconds: 60 });
  });

  it('falls back to our own words when the API offers none', () => {
    expect(interpret(500, null, null, 'Something went wrong.')).toEqual({
      kind: 'failed',
      message: 'Something went wrong.',
    });
    expect(interpret(503, { message: 'Service unavailable' }, null, 'Something went wrong.')).toEqual({
      kind: 'failed',
      message: 'Service unavailable',
    });
  });
});

describe('waitMessage', () => {
  it('rounds up to whole minutes and never says zero', () => {
    expect(waitMessage(150, 'Wait about {minutes} minute(s).')).toBe('Wait about 3 minute(s).');
    expect(waitMessage(1, 'Wait about {minutes} minute(s).')).toBe('Wait about 1 minute(s).');
    expect(waitMessage(0, 'Wait about {minutes} minute(s).')).toBe('Wait about 1 minute(s).');
  });
});

describe('resetCredentials', () => {
  it('takes the pair out of the link, and reports an incomplete one as empty', () => {
    expect(resetCredentials(new URLSearchParams('token=abc&email=nabin%40example.com'))).toEqual({
      token: 'abc',
      email: 'nabin@example.com',
    });
    expect(resetCredentials(new URLSearchParams('token=abc'))).toEqual({ token: 'abc', email: '' });
    expect(resetCredentials(new URLSearchParams(''))).toEqual({ token: '', email: '' });
  });
});

describe('fieldErrors', () => {
  it('survives a body that is not the shape Laravel promises', () => {
    expect(fieldErrors(null)).toEqual({});
    expect(fieldErrors({ errors: 'nope' })).toEqual({});
    expect(fieldErrors({ errors: { email: [] } })).toEqual({});
  });
});
