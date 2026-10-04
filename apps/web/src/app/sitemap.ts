import type { MetadataRoute } from 'next';

import { LOCALES } from '@/i18n/config';
import { fetchPublishedPaths } from '@/lib/api';
import { siteUrl } from '@/lib/site';

/**
 * The sitemap (HW-E10).
 *
 * Until now the only pages a crawler could reach were the ones linked from the
 * home page — which, once there is more than a handful of municipalities, is a
 * small fraction of the site. Most readers will arrive by searching their own
 * ward's name, so this is close to the difference between being found and not.
 *
 * Every address appears once per locale, and the two are declared as alternates
 * of each other rather than as rivals. Without that, a Nepali ward page and its
 * English twin compete for the same query and a search engine picks one on its
 * own reasoning — which on this site may mean English for a reader who wanted
 * Nepali (§16).
 *
 * Ward pages carry the higher priority. They are the pages the platform exists
 * to serve; a municipality page is mostly a route to them.
 *
 * A failed fetch returns the locale roots rather than throwing. A sitemap that
 * 500s during a deploy teaches a crawler the URL is broken; one that is briefly
 * short teaches it nothing at all.
 */
/*
 * Rendered on request, not baked at build — the same reason as every other page
 * here (D-015).
 *
 * Prerendered, this file would be generated while the API is unreachable, so
 * the image would ship a sitemap containing two URLs and nothing else, and
 * would go on serving it until the first revalidation. `fetchPublishedPaths`
 * caches for an hour at the fetch layer, so rendering per request costs one
 * upstream call an hour, not one per crawler.
 */
export const dynamic = 'force-dynamic';

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = siteUrl().origin;
  const result = await fetchPublishedPaths();

  const roots: MetadataRoute.Sitemap = LOCALES.map((locale) => ({
    url: `${base}/${locale}`,
    changeFrequency: 'weekly',
    priority: 1,
    alternates: { languages: languagesFor(base, '') },
  }));

  if (!result.ok) {
    return roots;
  }

  const places = result.data.flatMap((entry): MetadataRoute.Sitemap => [
    ...entries(base, `palika/${entry.slug_path}`, entry.updated_at, 0.7),
    ...entry.wards.flatMap((ward) =>
      entries(base, `ward/${entry.slug_path}/${ward.number}`, ward.updated_at, 0.9),
    ),
    // Below the ward pages: a reader looking for their representative is
    // better served arriving at the ward, which shows every seat.
    ...(entry.people ?? []).flatMap((person) => entries(base, person.path, person.updated_at, 0.6)),
  ]);

  /*
   * The trust pages are static and few, and they are what a reader checks
   * before deciding whether to believe any of the rest. Low priority, but
   * present: a civic platform whose "who runs this" page cannot be found has
   * answered that question badly.
   */
  const trust = ['about', 'sources', 'privacy'].flatMap((page) =>
    entries(base, page, null, 0.3, 'monthly'),
  );

  return [...roots, ...places, ...trust];
}

/** One entry per locale for a single address. */
function entries(
  base: string,
  suffix: string,
  updatedAt: string | null,
  priority: number,
  changeFrequency: 'weekly' | 'monthly' = 'weekly',
): MetadataRoute.Sitemap {
  /*
   * An unparseable or absent timestamp means no lastMod at all, rather than
   * `new Date(null)` — which is 1970, and tells a crawler this page has not
   * changed since before Nepal had local elections.
   */
  const lastModified = updatedAt === null ? undefined : new Date(updatedAt);
  const valid =
    lastModified !== undefined && !Number.isNaN(lastModified.getTime()) ? lastModified : undefined;

  return LOCALES.map((locale) => ({
    url: `${base}/${locale}/${suffix}`,
    lastModified: valid,
    changeFrequency,
    priority,
    alternates: { languages: languagesFor(base, suffix) },
  }));
}

/**
 * @returns the same address in every locale, plus x-default pointing at Nepali
 */
function languagesFor(base: string, suffix: string): Record<string, string> {
  const path = (locale: string): string =>
    suffix === '' ? `${base}/${locale}` : `${base}/${locale}/${suffix}`;

  return {
    ...Object.fromEntries(LOCALES.map((locale) => [locale, path(locale)])),
    'x-default': path('ne'),
  };
}
