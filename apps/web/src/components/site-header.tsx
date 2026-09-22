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
    <header className="border-b border-rule">
      <div className="mx-auto flex w-full max-w-[720px] items-center justify-between px-4 py-3">
        <Link href={`/${locale}`} className="font-display text-xl font-bold">
          {siteName}
        </Link>
        <Link
          href={`/${other}`}
          hrefLang={other}
          lang={other}
          className="min-h-11 min-w-11 content-center text-brass-ink underline"
        >
          {switchLabel}
        </Link>
      </div>
    </header>
  );
}
