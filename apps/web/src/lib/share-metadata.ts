import type { Metadata } from 'next';

import type { Locale } from '@/i18n/config';

/**
 * Open Graph and Twitter metadata for one page.
 *
 * This exists because of a trap in how Next merges metadata: a page's
 * `openGraph` object REPLACES the layout's rather than merging field by field.
 * A page that sets only a title and a description therefore silently drops
 * `og:site_name`, `og:locale` and `og:type`, and a page that sets `twitter`
 * drops `twitter:card` back to the default `summary` — which is the small
 * square preview, not the wide card the 1200×630 image was drawn for.
 *
 * That failure is invisible in development. You only see it after the link is
 * already in a Viber group.
 *
 * So every page that customises its share metadata builds it here, and the
 * fields that must never be lost are set in one place.
 *
 * Images are deliberately absent: Next collects those from the
 * opengraph-image files in the route tree, and naming them here would override
 * that and give every ward the same picture.
 */
export function shareMetadata({
  locale,
  title,
  description,
  path,
  type = 'article',
  siteName,
}: {
  locale: Locale;
  title: string;
  description: string;
  /** Locale-prefixed path, e.g. `/ne/ward/koshi/sunsari/koshara/4`. */
  path: string;
  type?: 'website' | 'article';
  siteName: string;
}): Pick<Metadata, 'openGraph' | 'twitter'> {
  return {
    openGraph: {
      type,
      siteName,
      title,
      description,
      url: path,
      locale: locale === 'ne' ? 'ne_NP' : 'en_US',
      alternateLocale: locale === 'ne' ? 'en_US' : 'ne_NP',
    },
    twitter: {
      card: 'summary_large_image',
      title,
      description,
    },
  };
}
