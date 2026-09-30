import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

import { DEFAULT_LOCALE, isLocale } from '@/i18n/config';
import { LOCALE_HEADER } from '@/i18n/locale-header';
import { clientAddress, createRateLimiter } from '@/lib/rate-limit';

/**
 * Locale routing and the per-address request ceiling.
 *
 * Locale routing (HW-E07-F01-T01):
 *   /            -> /ne
 *   /ward/...    -> /ne/ward/...
 *   /en/ward/... -> unchanged
 *
 * Host routing for the staff dashboard is added in HW-E13-F02-T01: requests to
 * the admin host are rewritten into the (staff) route group, and public-host
 * requests for staff routes return 404.
 *
 * ---------------------------------------------------------------------------
 * Why the limit is here and not in Laravel
 *
 * The browser never talks to Laravel: server components fetch the API from
 * inside the container network (lib/api.ts), so every request Laravel sees
 * arrives from one address — the web container. An IP-keyed limiter there
 * would put every visitor in the country into a single bucket, and the first
 * busy minute would take the site down for all of them. Laravel keeps a
 * limiter as a backstop against a runaway loop or a direct hit; the real
 * per-visitor ceiling has to sit at the layer that can still see the visitor,
 * which is this one.
 *
 * Deliberately NOT forwarding the visitor's address on to the API instead:
 * reading request headers inside a server component makes the route dynamic,
 * which would switch off the ISR caching that currently shields the API from
 * almost all of this traffic. Losing that cache to gain a header would cost
 * far more than it buys.
 *
 * The counters are per-process and are lost on deploy, so this is a ceiling,
 * not an audit. The durable limit belongs at the reverse proxy.
 * ---------------------------------------------------------------------------
 */

const WINDOW_MS = 60_000;

/**
 * Generous for a person, tight for a script. A visitor reading a municipality
 * page and opening several wards makes a handful of requests a minute; this
 * allows two a second, sustained, before it says no.
 *
 * Read at module scope, which in middleware means once per deployment. There is
 * no per-request environment read here on purpose: the middleware runs on every
 * page view.
 */
const LIMIT = Number.parseInt(process.env.HW_RATE_LIMIT_PER_MINUTE ?? '120', 10);

const limiter = createRateLimiter({ limit: LIMIT, windowMs: WINDOW_MS });

export function middleware(request: NextRequest) {
  const address = clientAddress(request.headers.get('x-forwarded-for'));

  if (address !== null && LIMIT > 0) {
    const decision = limiter.check(address);

    if (!decision.allowed) {
      // Plain text, not a rendered page: rendering a styled 429 would cost the
      // work the limit exists to avoid.
      return new NextResponse('Too many requests. Please wait a moment.', {
        status: 429,
        headers: {
          'Retry-After': String(decision.retryAfterSeconds),
          'Content-Type': 'text/plain; charset=utf-8',
          // Never let a shared cache keep a 429 and serve it to someone else.
          'Cache-Control': 'no-store',
        },
      });
    }
  }

  const { pathname } = request.nextUrl;
  const [, maybeLocale] = pathname.split('/');

  if (isLocale(maybeLocale)) {
    /*
     * The locale, forwarded to the server components that cannot read it from
     * route params. `not-found.tsx` is the one that matters: Next never gives
     * it params, and it has to stay a server component — a 'use client'
     * not-found page is not server-rendered at all, so a visitor on a slow
     * phone gets an empty 404 until the JavaScript arrives, and a crawler gets
     * one permanently.
     *
     * Set on the REQUEST headers, not the response: this is input for the
     * render, not something the browser needs.
     */
    const headers = new Headers(request.headers);
    headers.set(LOCALE_HEADER, maybeLocale);

    return NextResponse.next({ request: { headers } });
  }

  const url = request.nextUrl.clone();
  url.pathname = `/${DEFAULT_LOCALE}${pathname === '/' ? '' : pathname}`;

  return NextResponse.redirect(url);
}

export const config = {
  matcher: ['/((?!api|sanctum|_next|favicon.ico|robots.txt|sitemap.xml|.*\\..*).*)'],
};
