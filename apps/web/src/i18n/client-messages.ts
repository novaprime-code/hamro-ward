import type { Locale } from '@/i18n/config';
import { DEFAULT_LOCALE, isLocale } from '@/i18n/config';

import en from '../../messages/en.json';
import ne from '../../messages/ne.json';

/**
 * Messages for the two components that cannot ask the server for them.
 *
 * `i18n/messages.ts` is `server-only` and reads the locale from the route
 * params. Neither works here: `not-found.tsx` is never given params by Next,
 * and `error.tsx` is a client component by definition — it exists to render
 * after the server render has already failed, so it cannot depend on the
 * server succeeding.
 *
 * So these two files, and only these two, import the catalogues directly and
 * pick the locale from the URL. Both catalogues land in the error chunk, which
 * is a few kilobytes and is loaded only when something has gone wrong.
 *
 * Everything else must keep using `getMessages()`.
 */

const CATALOGUES: Record<Locale, Record<string, string>> = { ne, en };

/** The locale in a pathname such as `/ne/ward/...`, defaulting like the middleware does. */
export function localeFromPathname(pathname: string): Locale {
  const [, first] = pathname.split('/');

  return isLocale(first) ? first : DEFAULT_LOCALE;
}

/**
 * Falls back to the default catalogue key by key, then to the key itself, so a
 * missing translation degrades to Nepali rather than to a blank error page.
 */
export function clientTranslator(locale: Locale) {
  const messages = { ...CATALOGUES[DEFAULT_LOCALE], ...CATALOGUES[locale] };

  return (key: string): string => messages[key] ?? key;
}
