import { describe, expect, it } from 'vitest';

import { formatNumber, isLocale } from '@/i18n/config';

describe('locale helpers', () => {
  it('accepts supported locales only', () => {
    expect(isLocale('ne')).toBe(true);
    expect(isLocale('en')).toBe(true);
    expect(isLocale('np')).toBe(false);
    expect(isLocale(undefined)).toBe(false);
  });

  it('uses Devanagari digits in Nepali content', () => {
    expect(formatNumber(4, 'ne')).toBe('४');
    expect(formatNumber(2079, 'ne')).toBe('२०७९');
  });

  it('keeps Latin digits in English', () => {
    expect(formatNumber(4, 'en')).toBe('4');
  });
});
