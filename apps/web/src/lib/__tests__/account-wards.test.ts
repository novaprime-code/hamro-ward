import { describe, expect, it } from 'vitest';

import { canBePrimary, readList, wardNumbers } from '@/lib/account-wards';

describe('canBePrimary', () => {
  it('allows only a ward someone lives in', () => {
    expect(canBePrimary('permanent_address')).toBe(true);
    expect(canBePrimary('temporary_address')).toBe(true);
    expect(canBePrimary('workplace')).toBe(false);
    expect(canBePrimary('other')).toBe(false);
  });
});

describe('wardNumbers', () => {
  it('counts from one, and copes with a municipality of no known size', () => {
    expect(wardNumbers(3)).toEqual([1, 2, 3]);
    expect(wardNumbers(0)).toEqual([]);
    expect(wardNumbers(-1)).toEqual([]);
  });
});

describe('readList', () => {
  it('reads the list and its cap', () => {
    expect(readList({ data: [{ id: 'a' }], meta: { max_saved: 5 } })).toEqual({
      wards: [{ id: 'a' }],
      maxSaved: 5,
    });
  });

  it('falls back rather than rendering undefined when the body is not what we expect', () => {
    expect(readList(null)).toEqual({ wards: [], maxSaved: 5 });
    expect(readList({})).toEqual({ wards: [], maxSaved: 5 });
    expect(readList({ data: 'nope', meta: { max_saved: 'five' } })).toEqual({ wards: [], maxSaved: 5 });
  });
});
