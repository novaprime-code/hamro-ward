import Link from 'next/link';
import { notFound } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { buttonVariants } from '@/components/ui/button';
import { formatNumber, isLocale } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchSearch, pick } from '@/lib/api';
import type { SearchHit } from '@/lib/api';
import { cn } from '@/lib/utils';

type PageParams = { locale: string };
type PageSearch = { q?: string };

/*
 * Never prerendered and never cached: the answer depends entirely on a query
 * string. `force-dynamic` rather than a revalidate window, because a cached
 * search page would be one entry per typo.
 */
export const dynamic = 'force-dynamic';

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));

  return {
    title: t('search.title'),
    alternates: { canonical: `/${locale}/search` },
    /*
     * A results page is not a page anyone should arrive at from a search
     * engine: it has no content of its own, and an index full of them buries
     * the ward pages that do. The pages it links to are all in the sitemap.
     */
    robots: { index: false, follow: true },
  };
}

/**
 * Search (HW-E09-F01, FR-GEO-07).
 *
 * The picker on the home page filters a list the browser already holds, which
 * is right for choosing between four municipalities and wrong for the question
 * people actually arrive with. Nobody thinks "select the municipality, then
 * select the ward" — they think "Itahari 4", or "इटहरी ४", and they type that.
 *
 * A plain GET form, so it works with no JavaScript, the results have a URL
 * somebody can share, and the back button behaves. On the connections this site
 * is built for, a form that submits beats a box that fetches per keystroke
 * (§17).
 */
export default async function SearchPage({
  params,
  searchParams,
}: {
  params: Promise<PageParams>;
  searchParams: Promise<PageSearch>;
}) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const { q } = await searchParams;
  const query = (q ?? '').trim();
  const t = translator(await getMessages(locale));

  const result = query === '' ? null : await fetchSearch(query);

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('search.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('search.help')}</p>
      </header>

      <form action={`/${locale}/search`} method="get" className="flex gap-2">
        <Input
          type="search"
          name="q"
          defaultValue={query}
          placeholder={t('search.placeholder')}
          aria-label={t('search.placeholder')}
          className="flex-1"
          // The one place autofocus is right: the page exists to be typed into.
          autoFocus
        />
        <button type="submit" className={cn(buttonVariants({ variant: 'default' }))}>
          {t('search.submit')}
        </button>
      </form>

      {result === null ? null : !result.ok ? (
        <StateNotice tone="neutral" title={t('error.unavailable')}>
          {t('error.unavailableHelp')}
        </StateNotice>
      ) : result.data.length === 0 ? (
        <StateNotice tone="paused" title={t('search.empty')}>
          {t('search.emptyHelp')}
        </StateNotice>
      ) : (
        <ul className="space-y-2">
          {result.data.map((hit) => (
            <li key={`${hit.type}-${hit.slug_path}-${hit.ward_number ?? 0}`}>
              <Hit hit={hit} locale={locale} t={t} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Hit({
  hit,
  locale,
  t,
}: {
  hit: SearchHit;
  locale: Locale;
  t: (key: string) => string;
}) {
  const place = pick(hit.name, locale);

  const href =
    hit.type === 'ward'
      ? `/${locale}/ward/${hit.slug_path}/${hit.ward_number}`
      : `/${locale}/palika/${hit.slug_path}`;

  /*
   * A ward's own name is almost always empty — it is identified by its number
   * and its municipality — so the heading is built here rather than sent by the
   * API, in the reader's own digits (§16).
   */
  const heading =
    hit.type === 'ward'
      ? `${t('home.wardPlateLabel')} ${formatNumber(hit.ward_number ?? 0, locale)} · ${place}`
      : place;

  /*
   * District and province are not decoration. Nepal has repeated place names
   * across districts, and a bare name gives a reader no way to tell which one
   * is theirs.
   */
  const where = [pick(hit.district, locale), pick(hit.province, locale)]
    .filter((part) => part !== '')
    .join(' · ');

  return (
    <Card asChild className="p-3">
      <Link href={href} className="block transition-colors hover:bg-muted focus-visible:bg-muted">
        <span className="block font-display text-[19px] font-semibold">{heading}</span>
        <span className="mt-0.5 block text-sm text-muted-foreground">
          {hit.local_level_type === null ? where : `${t(`type.${hit.local_level_type}`)} · ${where}`}
        </span>
      </Link>
    </Card>
  );
}
