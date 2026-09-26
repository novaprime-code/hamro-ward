/**
 * Which palette the site renders in.
 *
 * Set HW_THEME in the stack's environment and restart — it is read on the server
 * at request time, so no rebuild is needed. Palettes themselves live in
 * src/styles/tokens.css; adding one means adding a block there and a name here.
 */
export const THEMES = ['himal', 'sal', 'aaba', 'raat', 'kagaj'] as const;

export type Theme = (typeof THEMES)[number];

export const DEFAULT_THEME: Theme = 'himal';

export function isTheme(value: string | undefined): value is Theme {
  return value !== undefined && (THEMES as readonly string[]).includes(value);
}

/**
 * Server-side only: reads HW_THEME, falls back to the default when it is unset
 * or misspelled, so a typo in an environment variable can never break the site.
 */
export function resolveTheme(value: string | undefined = process.env.HW_THEME): Theme {
  return isTheme(value) ? value : DEFAULT_THEME;
}
