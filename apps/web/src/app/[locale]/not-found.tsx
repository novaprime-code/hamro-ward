import { headers } from 'next/headers';
import Link from 'next/link';

import { StateNotice } from '@/components/civic/state-notice';
import { buttonVariants } from '@/components/ui/button';
import { LOCALE_HEADER, localeFromHeader } from '@/i18n/locale-header';
import { getMessages, translator } from '@/i18n/messages';
import { cn } from '@/lib/utils';

/**
 * The 404 page for everything under a locale.
 *
 * Before this existed, every `notFound()` in the application — an unpublished
 * ward, a misspelled municipality, and the three footer links that pointed at
 * pages nobody had built — fell through to the framework's built-in 404:
 * unstyled, outside the layout, and in English on a Nepali-first site. To a
 * visitor arriving from a link someone shared on Viber, that page is
 * indistinguishable from the site being broken.
 *
 * Unmatched addresses reach this through the `[...rest]` catch-all, which is
 * what puts them inside this segment in the first place.
 *
 * Two constraints shape the rest of it:
 *
 *  - It must be a server component. A 'use client' not-found page type-checks,
 *    builds, and then is not server-rendered at all: the response carries the
 *    right status code and an empty body, and the copy only appears once the
 *    JavaScript has loaded. On an Android phone on a slow connection — the
 *    device this site is designed for (§17) — that is a blank page, and to a
 *    crawler it is a blank page permanently.
 *  - Next never passes route params to it, so the locale comes from the header
 *    the middleware sets.
 *
 * It says the two things that are actually true, because a citizen cannot tell
 * them apart from the URL: the address may be wrong, or that municipality may
 * not be on Hamro Ward yet. Both lead to the same useful next step, the picker.
 */
export default async function NotFound() {
  const locale = localeFromHeader((await headers()).get(LOCALE_HEADER));
  const t = translator(await getMessages(locale));

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('notFound.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('notFound.help')}</p>
      </header>

      <StateNotice tone="paused" title={t('notFound.reasons')}>
        {t('notFound.reasonsHelp')}
      </StateNotice>

      <p>
        <Link
          href={`/${locale}`}
          className={cn(buttonVariants({ variant: 'default' }), 'w-full sm:w-auto')}
        >
          {t('notFound.findWard')}
        </Link>
      </p>
    </div>
  );
}
