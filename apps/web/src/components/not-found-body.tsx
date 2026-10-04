'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { buttonVariants } from '@/components/ui/button';
import { clientTranslator, localeFromPathname } from '@/i18n/client-messages';
import { cn } from '@/lib/utils';

/**
 * The body of the locale 404 page (`app/[locale]/not-found.tsx`).
 *
 * A client component so that it can read the locale from the URL. The 404
 * page itself stays a server component — see there for why — and client
 * components are rendered on the server too, so the copy is in the HTML a
 * slow phone or a crawler receives.
 */
export function NotFoundBody() {
  const locale = localeFromPathname(usePathname() ?? '/');
  const t = clientTranslator(locale);

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
