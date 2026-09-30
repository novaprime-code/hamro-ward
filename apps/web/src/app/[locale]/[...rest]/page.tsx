import { notFound } from 'next/navigation';

/**
 * Everything under a locale that matches no other route.
 *
 * This exists to make `not-found.tsx` reachable. A `not-found.tsx` inside a
 * segment only renders for `notFound()` calls raised *within* that segment —
 * an unmatched URL never enters the segment at all, so it falls through to the
 * framework's global 404, which has no root layout of its own here and renders
 * as unstyled English on a Nepali-first site.
 *
 * That is not a hypothetical path. It is what `/ne/about` did before those
 * pages existed, and what every typo and stale shared link does.
 *
 * Routing a catch-all into `notFound()` puts the request inside the segment,
 * where the locale layout and the 404 page both apply. Next prefers more
 * specific segments over a catch-all, so this cannot shadow a real route: it
 * only ever sees addresses that had nowhere else to go.
 *
 * The alternative is a root `app/layout.tsx` and a root `app/not-found.tsx`,
 * which would demote `[locale]/layout.tsx` to a nested layout and mean two
 * <html> elements until it was rewritten. Not worth it for a 404.
 */
export default function CatchAll(): never {
  notFound();
}
