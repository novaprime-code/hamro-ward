import { Search } from 'lucide-react';
import Link from 'next/link';

import { buttonVariants } from '@/components/ui/button';
import type { Locale } from '@/i18n/config';

export function SiteHeader({
  locale,
  siteName,
  switchLabel,
  searchLabel,
}: {
  locale: Locale;
  siteName: string;
  switchLabel: string;
  searchLabel: string;
}) {
  const other: Locale = locale === 'ne' ? 'en' : 'ne';

  return (
    <header className="border-b border-border bg-card">
      <div className="mx-auto flex w-full max-w-[var(--content-width)] items-center justify-between gap-3 px-4 py-2">
        <Link href={`/${locale}`} className="font-display text-xl font-bold">
          {siteName}
        </Link>

        <div className="flex items-center gap-1">
          {/* Search is a link to a page, not a box in the header. A box here
              would need JavaScript to be useful and would take space on a
              phone from the one element that matters on every screen, which is
              the ward. The page it opens works with the keyboard alone. */}
          <Link
            href={`/${locale}/search`}
            className={buttonVariants({ variant: 'ghost', size: 'sm' })}
            aria-label={searchLabel}
          >
            <Search aria-hidden="true" />
            <span className="sr-only sm:not-sr-only">{searchLabel}</span>
          </Link>

          {/* buttonVariants + asChild rather than <Button onClick={router.push}>:
              this stays a real link, so it is crawlable, middle-clickable and
              works before JavaScript arrives — which on a 3G phone is most of
              the time the page is on screen. */}
          <Link
            href={`/${other}`}
            hrefLang={other}
            lang={other}
            className={buttonVariants({ variant: 'ghost', size: 'sm' })}
          >
            {switchLabel}
          </Link>
        </div>
      </div>
    </header>
  );
}
