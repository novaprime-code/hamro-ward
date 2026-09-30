import type { MetadataRoute } from 'next';

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
 * What crawlers may read.
 *
 * Almost everything, because almost everything here is public civic
 * information that exists to be found. The exceptions are narrow and each has
 * a reason:
 *
 *  - `/*​/search` has no content of its own. A search-results page in an index
 *    buries the ward pages it links to.
 *  - The staff host is disallowed outright rather than relying on it being
 *    unguessable. It is served on a separate hostname (docs/12 §11), so this
 *    line does nothing today — it is here so that the day it starts serving,
 *    nobody has to remember.
 *
 * Evidence pages are deliberately NOT disallowed. They are left out of the
 * sitemap because there are thousands of them and they would bury the ward
 * pages, but a reader following a shared link has to reach one, and a page
 * whose entire purpose is to be checkable should not be hidden from the
 * checking.
 *
 * `host` and `sitemap` are absolute, read from HW_SITE_URL at request time —
 * a compiled-in hostname would make one image belong to one environment
 * (D-015).
 */
export default function robots(): MetadataRoute.Robots {
  const base = siteUrl().origin;

  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: ['/*/search', '/staff/', '/api/'],
      },
    ],
    sitemap: `${base}/sitemap.xml`,
    host: base,
  };
}
