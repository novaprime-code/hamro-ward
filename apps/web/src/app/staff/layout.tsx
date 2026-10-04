import type { Metadata } from 'next';

import { anek, noto } from '@/lib/fonts';
import { resolveTheme } from '@/lib/theme';

import '../[locale]/globals.css';

/**
 * Root layout of the staff dashboard (HW-E13-F02). Reached only on the admin
 * host — the middleware rewrites every admin request into /staff and answers
 * /staff on the public host with a 404.
 *
 * Rendered per request: the nonce in the Content-Security-Policy differs on
 * every response, and a cached page would carry a stale one.
 */
export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: { default: 'Hamro Ward staff', template: '%s · Hamro Ward staff' },
  robots: { index: false, follow: false },
};

export default function StaffLayout({ children }: { children: React.ReactNode }) {
  return (
    // The same palette and type as the public site (HW_THEME), so a staff
    // screen is recognisably the same service.
    <html lang="en" data-theme={resolveTheme()} className={`${anek.variable} ${noto.variable}`}>
      <body className="min-h-dvh bg-background text-foreground">
        <main className="mx-auto max-w-3xl px-4 py-8">{children}</main>
      </body>
    </html>
  );
}
