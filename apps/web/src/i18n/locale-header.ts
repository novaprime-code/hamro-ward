import type { Locale } from '@/i18n/config';
import { DEFAULT_LOCALE, isLocale } from '@/i18n/config';

/**
 * The request header the middleware uses to tell a server component which
 * locale the URL asked for.
 *
 * Route params carry the locale everywhere else, and should. This exists for
 * the one file that is never given them: `not-found.tsx`. Keeping the name and
 * the reader in one module means the middleware and the 404 page cannot drift
 * apart over a string literal.
 */
export const LOCALE_HEADER = 'x-hw-locale';

/**
 * Falls back to the default rather than throwing. A request that somehow
 * reaches a page without passing through the middleware — a direct hit on the
 * container's port, a future rewrite — should still render a 404 in Nepali
 * rather than a 500.
 */
export function localeFromHeader(value: string | null): Locale {
  return isLocale(value ?? undefined) ? (value as Locale) : DEFAULT_LOCALE;
}
