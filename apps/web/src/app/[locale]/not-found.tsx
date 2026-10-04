import { NotFoundBody } from '@/components/not-found-body';

/**
 * The 404 page for everything under a locale.
 *
 * Before this existed, every `notFound()` in the application — an unpublished
 * ward, a misspelled municipality, and the three footer links that pointed at
 * pages nobody had built — fell through to the framework's built-in 404:
 * unstyled, outside the layout, and in English on a Nepali-first site. To a
 * visitor arriving from a link someone shared on Viber, that page is
 * indistinguishable from the site being broken.
 *
 * Unmatched addresses reach this through the `[...rest]` catch-all, which is
 * what puts them inside this segment in the first place.
 *
 * Two constraints shape the rest of it:
 *
 *  - It must be a server component. A 'use client' not-found page type-checks,
 *    builds, and then is not server-rendered at all: the response carries the
 *    right status code and an empty body, and the copy only appears once the
 *    JavaScript has loaded. On an Android phone on a slow connection — the
 *    device this site is designed for (§17) — that is a blank page, and to a
 *    crawler it is a blank page permanently.
 *  - Next never passes route params to it, and it must not read request
 *    headers either: Next renders this boundary as part of every page in the
 *    segment, so a headers() call here makes every public page dynamic and
 *    uncacheable. The locale is read from the URL by NotFoundBody instead.
 *
 * It says the two things that are actually true, because a citizen cannot tell
 * them apart from the URL: the address may be wrong, or that municipality may
 * not be on Hamro Ward yet. Both lead to the same useful next step, the picker.
 */
export default function NotFound() {
  return <NotFoundBody />;
}
