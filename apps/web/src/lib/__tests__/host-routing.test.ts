import { describe, expect, it } from 'vitest';

import { isAdminHost, routeFor, staffSecurityHeaders } from '@/lib/host-routing';

const ADMIN = 'admin.hamroward.example';

describe('routeFor', () => {
  it('maps every admin-host page into the staff tree', () => {
    expect(routeFor(ADMIN, '/', ADMIN)).toEqual({ kind: 'staff', pathname: '/staff' });
    expect(routeFor(ADMIN, '/queue', ADMIN)).toEqual({ kind: 'staff', pathname: '/staff/queue' });
    expect(routeFor(ADMIN, '/staff/queue', ADMIN)).toEqual({ kind: 'staff', pathname: '/staff/queue' });
  });

  it('never serves a public page on the admin host', () => {
    expect(routeFor(ADMIN, '/ne/ward/koshi/sunsari/koshara/1', ADMIN)).toEqual({
      kind: 'staff',
      pathname: '/staff/ne/ward/koshi/sunsari/koshara/1',
    });
  });

  it('returns 404 for staff routes on the public host', () => {
    expect(routeFor('hamroward.example', '/staff', ADMIN)).toEqual({ kind: 'not-found' });
    expect(routeFor('hamroward.example', '/staff/queue', ADMIN)).toEqual({ kind: 'not-found' });
    expect(routeFor('hamroward.example', '/staffing-news', ADMIN)).toEqual({ kind: 'public' });
    expect(routeFor('hamroward.example', '/ne', ADMIN)).toEqual({ kind: 'public' });
  });

  it('passes auth endpoints through on both hosts', () => {
    expect(routeFor(ADMIN, '/login', ADMIN)).toEqual({ kind: 'passthrough', admin: true });
    expect(routeFor('hamroward.example', '/two-factor-challenge', ADMIN)).toEqual({ kind: 'passthrough', admin: false });
    expect(routeFor('hamroward.example', '/user/password', ADMIN)).toEqual({ kind: 'passthrough', admin: false });
  });

  it('treats every host as public when no admin host is configured', () => {
    expect(routeFor(ADMIN, '/', null)).toEqual({ kind: 'public' });
    expect(routeFor(ADMIN, '/staff', '')).toEqual({ kind: 'not-found' });
  });
});

describe('isAdminHost', () => {
  it('compares the whole host, port included, ignoring case', () => {
    expect(isAdminHost('Admin.HamroWard.example', ADMIN)).toBe(true);
    expect(isAdminHost('admin.localhost:3000', 'admin.localhost:3000')).toBe(true);
    expect(isAdminHost('admin.localhost:3001', 'admin.localhost:3000')).toBe(false);
    expect(isAdminHost('evil-admin.hamroward.example', ADMIN)).toBe(false);
    expect(isAdminHost(null, ADMIN)).toBe(false);
  });
});

describe('staffSecurityHeaders', () => {
  it('sets a nonce CSP, noindex and no-store', () => {
    const headers = staffSecurityHeaders('abc123');

    expect(headers['Content-Security-Policy']).toContain("script-src 'self' 'nonce-abc123' 'strict-dynamic'");
    expect(headers['Content-Security-Policy']).toContain("frame-ancestors 'none'");
    expect(headers['Content-Security-Policy']).not.toContain('unsafe-eval');
    expect(headers['X-Robots-Tag']).toBe('noindex, nofollow');
    expect(headers['Cache-Control']).toBe('no-store');
  });

  it('allows eval only in development', () => {
    expect(staffSecurityHeaders('n', true)['Content-Security-Policy']).toContain("'unsafe-eval'");
  });
});
