import { describe, expect, it } from 'vitest';

import { DEFAULT_THEME, isTheme, resolveTheme, THEMES } from '@/lib/theme';

describe('theme resolution', () => {
  it('accepts every palette that exists', () => {
    THEMES.forEach((theme) => {
      expect(isTheme(theme)).toBe(true);
      expect(resolveTheme(theme)).toBe(theme);
    });
  });

  it('falls back instead of breaking on a bad value', () => {
    expect(resolveTheme('kagoj')).toBe(DEFAULT_THEME);
    expect(resolveTheme('')).toBe(DEFAULT_THEME);
    expect(resolveTheme(undefined)).toBe(DEFAULT_THEME);
  });
});
