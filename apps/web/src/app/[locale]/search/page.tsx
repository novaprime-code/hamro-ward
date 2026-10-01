import Link from 'next/link';
import { notFound } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { formatNumber, isLocale } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchSearch, pick } from '@/lib/api';
import type { SearchHit } from '@/lib/api';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = { locale: string };
type PageQuery = { q?: string };

/*
 * Never cached. The query is in the URL and comes from whoever is asking, so a
 * cached render is one cache entry per distinct query.
 */
export const dynamic = 'force-dynamic';

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const title = t('search.title');
  const description = t('search.help');
  const path = `/${locale}/search`;

  return {
    title,
    description,
    alternates: { canonical: path },
    ...shareMetadata({ locale, title, description, path, type: 'website', siteName: t('site.name') }),
    /*
     * A search results page is not a page anyone should arrive at from a search
     * engine — the engine already did this job, and a results page indexed
     * under someone else's query is noise. Followed, though: the links out of
     * it are municipality and ward pages, which very much should be indexed.
     */
    robots: { index: false, follow: true },
  };
}

/**
 * Find your ward (HW-E09).
 *
 * A form that submits with GET, rendered on the server. No JavaScript is
 * involved in getting from a typed name to a ward page, which on an Android
 * phone on a patchy connection is the difference between a search box that
 * works and one that does nothing until a bundle arrives (§17).
 *
 * It exists alongside the picker on the home page rather than replacing it.
 * The picker is for browsing four municipalities; this is for finding one of
 * 753 by a name typed the way people actually type it — either script, any
 * romanization, with or without the ward number.
 */
export default async function SearchPage({
  params,
  searchParams,
}: {
  params: Promise<PageParams>;
  searchParams: Promise<PageQuery>;
}) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const { q } = await searchParams;
  const query = (q ?? '').trim();
  const t = translator(await getMessages(locale));
  const result = await fetchSearch(query);

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('search.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('search.help')}</p>
      </header>

      {/* GET, so a search is a URL a reader can bookmark, share or go back to. */}
      <form action={`/${locale}/search`} method="get" className="flex flex-wrap gap-2">
        <Input
          type="search"
          name="q"
          defaultValue={query}
          placeholder={t('search.placeholder')}
          aria-label={t('search.title')}
          className="min-w-0 flex-1"
          autoComplete="off"
        />
        <Button type="submit">{t('search.submit')}</Button>
      </form>

      {query === '' ? null : <Results locale={locale} t={t} query={query} result={result} />}
    </div>
  );
}

function Results({
  locale,
  t,
  query,
  result,
}: {
  locale: Locale;
  t: (key: string) => string;
  query: string;
  result: Awaited<ReturnType<typeof fetchSearch>>;
}) {
  if (!result.ok) {
    return (
      <StateNotice tone="neutral" title={t('error.unavailable')}>
        {t('error.unavailableHelp')}
      </StateNotice>
    );
  }

  if (result.data.length === 0) {
    return (
      /*
       * "Not found" and "not open yet" are different, and a reader cannot tell
       * them apart from a blank result. Both are said, because both are true of
       * a platform that is adding municipalities one at a time.
       */
      <StateNotice tone="paused" title={t('search.empty').replace('{query}', query)}>
        {t('search.emptyHelp')}
      </StateNotice>
    );
  }

  return (
    <ul className="space-y-2">
      {result.data.map((hit) => (
        <li key={`${hit.slug_path}-${hit.ward_number ?? 'x'}`}>
          <Hit hit={hit} locale={locale} t={t} />
        </li>
      ))}
    </ul>
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
    hit.ward_number === null
      ? `/${locale}/palika/${hit.slug_path}`
      : `/${locale}/ward/${hit.slug_path}/${hit.ward_number}`;

  const heading =
    hit.ward_number === null
      ? place
      : `${t('home.wardPlateLabel')} ${formatNumber(hit.ward_number, locale)} · ${place}`;

  return (
    <Card asChild>
      <Link href={href} className="block p-4 transition-colors hover:bg-muted focus-visible:bg-muted">
        <span className="block font-display text-[19px] font-semibold">{heading}</span>
        {hit.local_level_type === null ? null : (
          <span className="mt-0.5 block text-sm text-muted-foreground">
            {t(`type.${hit.local_level_type}`)}
          </span>
        )}
      </Link>
    </Card>
  );
}
