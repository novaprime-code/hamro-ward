import type { MetadataRoute } from 'next';
import { headers } from 'next/headers';

import { isAdminHost } from '@/lib/host-routing';
import { adminHost, siteUrl } from '@/lib/site';

/**
 * robots.txt (HW-E10).
 *
 * Almost everything is allowed, because almost everything here is public
 * information that exists to be found. The exceptions are narrow and each has a
 * reason:
 *
 *  - `/*\/search` — a results page indexed under somebody else's query is noise,
 *    and the links out of it are already in the sitemap. The page also carries
 *    a noindex, which is what actually removes it from an index; this only
 *    saves the crawl.
 *  - the API — machine-readable and unpaginated in places, and nothing in it is
 *    a page a reader would want in their results.
 *
 * Evidence pages are deliberately NOT disallowed. They are uuid-addressed and
 * of no use as a search result, so they carry a noindex of their own — and a
 * crawler has to be allowed to fetch a page in order to read the noindex on it.
 * Blocking them here would leave them in the index with no description, which
 * is the outcome the noindex exists to avoid.
 *
 * The staff host gets no entry at all. `Disallow` takes a PATH, so naming a
 * hostname there produces `Disallow: /admin.example.com`, which matches no URL
 * on this host and reads to anyone auditing the file as though the admin
 * interface were protected. It is not protected by this file and cannot be: a
 * robots file has authority only over the host that serves it. The staff host
 * needs its own, and gets it here: on the admin host this route answers
 * `Disallow: /` (HW-E13-F02-T01). The pages there also carry
 * `X-Robots-Tag: noindex`, which is what actually keeps them out of an index.
 */
/*
 * Rendered on request. Prerendered, `siteUrl()` would be read on the build
 * machine and this file would ship with `http://localhost:3000` as its host and
 * its sitemap URL — an image that is wrong in every environment, which is the
 * precise failure D-015 exists to prevent.
 */
export const dynamic = 'force-dynamic';

export default async function robots(): Promise<MetadataRoute.Robots> {
  if (isAdminHost((await headers()).get('host'), adminHost())) {
    return { rules: [{ userAgent: '*', disallow: '/' }] };
  }

  const base = siteUrl().origin;

  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: ['/api/', '/*/search'],
      },
    ],
    sitemap: `${base}/sitemap.xml`,
    host: base,
  };
}
