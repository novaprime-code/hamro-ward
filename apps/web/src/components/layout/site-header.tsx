import Link from 'next/link';

import type { Locale } from '@/i18n/config';

export function SiteHeader({
  locale,
  siteName,
  switchLabel,
}: {
  locale: Locale;
  siteName: string;
  switchLabel: string;
}) {
  const other: Locale = locale === 'ne' ? 'en' : 'ne';

  return (
    <header className="border-b border-line bg-surface">
      <div className="mx-auto flex w-full max-w-[var(--content-width)] items-center justify-between px-4 py-3">
        <Link href={`/${locale}`} className="font-display text-xl font-bold">
          {siteName}
        </Link>
        <Link
          href={`/${other}`}
          hrefLang={other}
          lang={other}
          className="inline-flex min-h-[var(--tap-target)] items-center rounded-full px-3 text-accent-ink underline"
        >
          {switchLabel}
        </Link>
      </div>
    </header>
  );
}
