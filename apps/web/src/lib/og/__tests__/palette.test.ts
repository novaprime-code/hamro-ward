import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { OG_PALETTES, OG_PALETTE_TOKENS } from '@/lib/og/palette';
import type { OgPalette } from '@/lib/og/palette';
import { THEMES } from '@/lib/theme';

/**
 * tokens.css is meant to be the only file in the application holding a colour
 * value, and lib/og/palette.ts breaks that rule because Satori renders the
 * share cards with no stylesheet to resolve custom properties against.
 *
 * A duplicated colour that nothing checks is a colour that will drift. These
 * tests are what make the duplication safe: repaint a palette in tokens.css
 * without updating palette.ts and CI fails here, instead of the share cards
 * quietly staying on last month's colours — a failure nobody on the team would
 * see, because the people who see share cards are not the people who deploy.
 */

const TOKENS = readFileSync(
  join(__dirname, '..', '..', '..', 'styles', 'tokens.css'),
  'utf8',
);

/** The custom properties declared inside one [data-theme="…"] block. */
function declaredTokens(theme: string): Map<string, string> {
  const block = new RegExp(`\\[data-theme="${theme}"\\]\\s*\\{([^}]*)\\}`).exec(TOKENS);

  if (block === null) {
    throw new Error(`tokens.css has no [data-theme="${theme}"] block`);
  }

  const declarations = new Map<string, string>();

  for (const [, name, value] of block[1].matchAll(/(--[a-z0-9-]+)\s*:\s*([^;]+);/g)) {
    declarations.set(name, value.trim().toLowerCase());
  }

  return declarations;
}

describe('OG palettes mirror tokens.css', () => {
  it('covers every theme the site can be set to', () => {
    expect(Object.keys(OG_PALETTES).sort()).toEqual([...THEMES].sort());
  });

  it.each([...THEMES])('%s matches its tokens.css block', (theme) => {
    const declared = declaredTokens(theme);
    const palette = OG_PALETTES[theme];

    for (const key of Object.keys(OG_PALETTE_TOKENS) as (keyof OgPalette)[]) {
      const token = OG_PALETTE_TOKENS[key];

      expect(declared.get(token), `${theme}: ${token} is missing from tokens.css`).toBeDefined();
      expect(palette[key].toLowerCase(), `${theme}: ${key} has drifted from ${token}`).toBe(
        declared.get(token),
      );
    }
  });
});
