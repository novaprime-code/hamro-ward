import type { NextConfig } from 'next';

const apiOrigin = process.env.API_INTERNAL_URL ?? 'http://127.0.0.1:8000';

const nextConfig: NextConfig = {
  output: 'standalone',
  reactStrictMode: true,
  poweredByHeader: false,

  /**
   * Same-origin API (docs/12 §11.2): the browser talks to /api and /sanctum on
   * this host, so session cookies stay host-only and no CORS setup is needed.
   * In production Caddy does the same routing in front of both apps.
   */
  async rewrites() {
    return [
      { source: '/api/v1/:path*', destination: `${apiOrigin}/api/v1/:path*` },
      { source: '/sanctum/:path*', destination: `${apiOrigin}/sanctum/:path*` },
      // Fortify (docs/12 §11): the same paths on both hosts; Laravel picks
      // the guard from the host. lib/host-routing.ts lets these through.
      { source: '/login', destination: `${apiOrigin}/login` },
      { source: '/logout', destination: `${apiOrigin}/logout` },
      { source: '/register', destination: `${apiOrigin}/register` },
      { source: '/forgot-password', destination: `${apiOrigin}/forgot-password` },
      { source: '/reset-password', destination: `${apiOrigin}/reset-password` },
      { source: '/two-factor-challenge', destination: `${apiOrigin}/two-factor-challenge` },
      { source: '/user/:path*', destination: `${apiOrigin}/user/:path*` },
      { source: '/email/:path*', destination: `${apiOrigin}/email/:path*` },
    ];
  },

  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(self)' },
        ],
      },
    ];
  },
};

export default nextConfig;
