export const LOCALES = ['ne', 'en'] as const;

export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = 'ne';

export function isLocale(value: string | undefined): value is Locale {
  return value !== undefined && (LOCALES as readonly string[]).includes(value);
}

/** Devanagari digits for Nepali content. Phone numbers and codes keep Latin digits (docs/09 §3.4). */
const DEVANAGARI_DIGITS = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];

export function formatNumber(value: number | string, locale: Locale): string {
  const text = String(value);

  return locale === 'ne'
    ? text.replace(/\d/g, (digit) => DEVANAGARI_DIGITS[Number(digit)])
    : text;
}
