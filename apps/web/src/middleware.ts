import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

import { DEFAULT_LOCALE, isLocale } from '@/i18n/config';

/**
 * Locale routing (HW-E07-F01-T01):
 *   /            -> /ne
 *   /ward/...    -> /ne/ward/...
 *   /en/ward/... -> unchanged
 *
 * Host routing for the staff dashboard is added in HW-E13-F02-T01: requests to
 * the admin host are rewritten into the (staff) route group, and public-host
 * requests for staff routes return 404.
 */
export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const [, maybeLocale] = pathname.split('/');

  if (isLocale(maybeLocale)) {
    return NextResponse.next();
  }

  const url = request.nextUrl.clone();
  url.pathname = `/${DEFAULT_LOCALE}${pathname === '/' ? '' : pathname}`;

  return NextResponse.redirect(url);
}

export const config = {
  matcher: ['/((?!api|sanctum|_next|favicon.ico|robots.txt|sitemap.xml|.*\\..*).*)'],
};
