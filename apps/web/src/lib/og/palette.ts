import type { Theme } from '@/lib/theme';

/**
 * The palette colours the share cards need, as literal hex.
 *
 * This is the second file in the application that contains a colour value, and
 * the only one that is allowed to be. `tokens.css` is the first and is meant to
 * be the only one — but Satori renders the share card outside the browser, with
 * no stylesheet and no CSS custom properties to resolve, so it can only be
 * given real values.
 *
 * Duplicated values drift. `__tests__/palette.test.ts` parses `tokens.css` and
 * fails if any value here stops matching the palette it came from, so a
 * repainted palette cannot silently leave the share cards on the old colours.
 * Adding a palette means adding it in three places: tokens.css, THEMES in
 * lib/theme.ts, and here — and the test will say so.
 *
 * Only the seven tokens a card actually uses are mirrored. Anything the card
 * does not draw stays out, because every value here is a value that can drift.
 */
export type OgPalette = {
  surface: string;
  text: string;
  textMuted: string;
  line: string;
  plateBg: string;
  plateNumber: string;
  stateVerified: string;
};

export const OG_PALETTES: Record<Theme, OgPalette> = {
  himal: {
    surface: '#fbfdfd',
    text: '#14212a',
    textMuted: '#51646f',
    line: '#d3dee3',
    plateBg: '#14212a',
    plateNumber: '#5fd3c8',
    stateVerified: '#136b5b',
  },
  sal: {
    surface: '#fdfaf5',
    text: '#33241a',
    textMuted: '#6b5847',
    line: '#e3d7c6',
    plateBg: '#33241a',
    plateNumber: '#e6a84e',
    stateVerified: '#3f6b41',
  },
  aaba: {
    surface: '#fcfafc',
    text: '#241a28',
    textMuted: '#5f5266',
    line: '#e0d6e2',
    plateBg: '#241a28',
    plateNumber: '#e2adcd',
    stateVerified: '#2f6152',
  },
  raat: {
    surface: '#111a23',
    text: '#e7eef3',
    textMuted: '#9aaebc',
    line: '#25323f',
    plateBg: '#050a0f',
    plateNumber: '#67d6b5',
    stateVerified: '#67d6b5',
  },
  kagaj: {
    surface: '#ffffff',
    text: '#111111',
    textMuted: '#5a5a5a',
    line: '#dcdcda',
    plateBg: '#111111',
    plateNumber: '#ffffff',
    stateVerified: '#1f5e3a',
  },
};

/** Which CSS custom property each key mirrors — the test reads this, so it cannot go stale. */
export const OG_PALETTE_TOKENS: Record<keyof OgPalette, string> = {
  surface: '--surface',
  text: '--text',
  textMuted: '--text-muted',
  line: '--line',
  plateBg: '--plate-bg',
  plateNumber: '--plate-number',
  stateVerified: '--state-verified',
};
