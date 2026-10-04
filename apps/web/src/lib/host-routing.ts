/**
 * Which site a request is for (HW-E13-F02-T01, docs/12 §11.1, 06 TB4).
 *
 * One Next.js server answers two hosts. The public host serves the citizen
 * site; the admin host serves the staff dashboard and nothing else. Keeping
 * the decision in one pure function means the middleware stays a thin
 * wrapper and every branch can be tested without a server.
 *
 *  - Admin host: every page path is rewritten into the `/staff` tree, so a
 *    public page can never be reached there and a staff URL needs no prefix.
 *  - Public host: anything under `/staff` is a 404, whatever exists there.
 *  - Either host: Laravel's auth endpoints pass straight through to the
 *    rewrites in next.config.ts — they are not pages and have no locale.
 */

export type HostRoute =
  | { kind: 'staff'; pathname: string }
  | { kind: 'public' }
  | { kind: 'not-found' }
  | { kind: 'passthrough'; admin: boolean };

export const STAFF_PREFIX = '/staff';

/** Laravel (Fortify) endpoints, proxied by next.config.ts on both hosts. */
const AUTH_PATHS = ['/login', '/logout', '/register', '/forgot-password', '/reset-password', '/two-factor-challenge'];

function isAuthPath(pathname: string): boolean {
  return AUTH_PATHS.includes(pathname) || pathname.startsWith('/user/') || pathname.startsWith('/email/');
}

function isUnder(pathname: string, prefix: string): boolean {
  return pathname === prefix || pathname.startsWith(`${prefix}/`);
}

/**
 * Hosts are compared case-insensitively and with the port, as configured: a
 * staging admin host on :3000 is not the production one on :443.
 */
export function isAdminHost(host: string | null, adminHost: string | null): boolean {
  if (host === null || adminHost === null || adminHost === '') {
    return false;
  }

  return host.trim().toLowerCase() === adminHost.trim().toLowerCase();
}

export function routeFor(host: string | null, pathname: string, adminHost: string | null): HostRoute {
  const admin = isAdminHost(host, adminHost);

  if (isAuthPath(pathname)) {
    return { kind: 'passthrough', admin };
  }

  if (admin) {
    // Already inside the tree (a link rendered with the prefix) stays put;
    // everything else is mapped into it.
    return {
      kind: 'staff',
      pathname: isUnder(pathname, STAFF_PREFIX) ? pathname : `${STAFF_PREFIX}${pathname === '/' ? '' : pathname}`,
    };
  }

  return isUnder(pathname, STAFF_PREFIX) ? { kind: 'not-found' } : { kind: 'public' };
}

/**
 * Response headers for every admin-host response (06 TB4).
 *
 * Strict CSP with a per-request nonce: Next.js reads the policy from the
 * request headers and stamps the nonce on its own scripts, so no inline
 * script runs without it. Development keeps 'unsafe-eval' for React's
 * debugging tools and nothing else.
 */
export function staffSecurityHeaders(nonce: string, development = false): Record<string, string> {
  const scriptSrc = ["'self'", `'nonce-${nonce}'`, "'strict-dynamic'", ...(development ? ["'unsafe-eval'"] : [])];

  const policy = [
    "default-src 'self'",
    `script-src ${scriptSrc.join(' ')}`,
    // Tailwind and next/font emit style attributes and tags without nonces.
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ].join('; ');

  return {
    'Content-Security-Policy': policy,
    'X-Robots-Tag': 'noindex, nofollow',
    'Cache-Control': 'no-store',
  };
}
