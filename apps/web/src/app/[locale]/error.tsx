'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect } from 'react';

import { StateNotice } from '@/components/civic/state-notice';
import { Button, buttonVariants } from '@/components/ui/button';
import { clientTranslator, localeFromPathname } from '@/i18n/client-messages';
import { cn } from '@/lib/utils';

/**
 * The error boundary for everything under a locale.
 *
 * `lib/api.ts` already turns a failed API call into `{ ok: false }`, and the
 * pages render a state notice for that. This catches what is left: a render
 * that threw, which in practice means the API returned a shape the page did
 * not expect. Without this file that visitor gets the framework's default
 * error screen.
 *
 * The wording puts the fault where it belongs. A citizen who sees an error on a
 * government-information site reasonably wonders whether they did something
 * wrong, or whether the information itself is suspect. Neither is true, and the
 * page says so.
 *
 * `digest` is the only identifier tying this screen to a server log line, so it
 * is shown when present — a visitor reporting a problem can quote it.
 */
export default function LocaleError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const locale = localeFromPathname(usePathname() ?? '/');
  const t = clientTranslator(locale);

  useEffect(() => {
    // Structured observability arrives with HW-E02; until then the browser
    // console is where a developer reproducing a report will look.
    console.error('[hamroward] render failed', error);
  }, [error]);

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('error.crashed')}</h1>
        <p className="text-muted-foreground">{t('error.crashedHelp')}</p>
      </header>

      {error.digest === undefined ? null : (
        <StateNotice tone="neutral" title={t('error.reference')}>
          <code className="font-mono text-sm">{error.digest}</code>
        </StateNotice>
      )}

      <div className="flex flex-wrap gap-2">
        <Button onClick={reset}>{t('error.retry')}</Button>
        <Link href={`/${locale}`} className={cn(buttonVariants({ variant: 'outline' }))}>
          {t('notFound.findWard')}
        </Link>
      </div>
    </div>
  );
}
