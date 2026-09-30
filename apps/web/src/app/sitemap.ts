import type { MetadataRoute } from 'next';

import { LOCALES } from '@/i18n/config';
import { fetchPublishedPaths } from '@/lib/api';
import { siteUrl } from '@/lib/site';

/*
 * Rendered per request, never prerendered.
 *
 * A `revalidate` window alone is not enough: Next prerenders the first copy at
 * BUILD time, which would bake the build machine's HW_SITE_URL — unset, so
 * localhost — into every URL in the file, and serve that until the window
 * expired. One image has to be promotable between environments (D-015), and a
 * sitemap full of localhost is exactly the failure that rule exists to stop.
 *
 * The cost is nothing. These are read by crawlers on their own schedule, not
 * by visitors.
 */
export const dynamic = 'force-dynamic';

/**
 * Every page worth crawling (Milestone A, A4).
 *
 * Which is fewer pages than the site has, on purpose:
 *
 *  - **Evidence pages are left out.** They are keyed by uuid, thin on their own,
 *    and there is one per record — thousands of near-identical URLs that would
 *    bury the ward pages in an index. They stay crawlable, because a reader
 *    following a shared link must reach one, but they are not advertised.
 *  - **Person pages are left out too**, for now. A person page exists only
 *    where a seat is held, so listing them would publish a crawlable roster of
 *    named individuals per municipality — a different artefact from a civic
 *    information site, and one worth deciding on deliberately rather than as a
 *    side effect of a sitemap (§20).
 *  - **The search page is left out**, and marked noindex on the page itself: it
 *    has no content of its own.
 *
 * Both locales of every page are listed with `alternates.languages`, so a
 * crawler is told the Nepali and English pages are the same page rather than
 * two competing ones.
 *
 * If the API cannot be reached, this returns the pages that need no data rather
 * than throwing. A sitemap that 500s during a deploy tells a crawler the site
 * is broken; one that is briefly short tells it nothing at all.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = siteUrl().origin;
  const result = await fetchPublishedPaths();

  const alternatesFor = (path: string) => ({
    languages: Object.fromEntries(LOCALES.map((locale) => [locale, `${base}/${locale}${path}`])),
  });

  const entry = (path: string, lastModified?: string | null, priority = 0.5) =>
    LOCALES.map((locale) => ({
      url: `${base}/${locale}${path}`,
      lastModified: lastModified ?? undefined,
      alternates: alternatesFor(path),
      priority,
    }));

  const staticPages: MetadataRoute.Sitemap = [
    ...entry('', undefined, 1),
    ...entry('/about', undefined, 0.3),
    ...entry('/sources', undefined, 0.3),
    ...entry('/privacy', undefined, 0.3),
  ];

  if (!result.ok) {
    return staticPages;
  }

  const places: MetadataRoute.Sitemap = result.data.flatMap((place) => [
    ...entry(`/palika/${place.slug_path}`, place.updated_at, 0.8),
    // The ward page is the reason the site exists, so it carries the highest
    // priority of anything data-driven here (§24).
    ...place.wards.flatMap((ward) =>
      entry(`/ward/${place.slug_path}/${ward.number}`, ward.updated_at, 0.9),
    ),
  ]);

  return [...staticPages, ...places];
}
