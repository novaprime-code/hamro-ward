import { notFound } from 'next/navigation';
import type { ReactNode } from 'react';

import { isLocale } from '@/i18n/config';

/**
 * The account area (HW-E30-F01-T03; docs/12 §11.4).
 *
 * Never cached, never indexed. These pages are about one person: a shared
 * cache holding one of them would be a cache holding somebody's session, and
 * a search engine indexing them would be a search result that says "Sign in"
 * where a ward should be. Cloudflare bypasses /*\/account/* for the same
 * reason.
 *
 * They are also the only pages on the public site that are client-rendered
 * through and through. Everything else here renders on the server and is
 * cached for everyone; these cannot be, so they are kept in their own corner
 * of the route tree rather than being allowed to set the habit elsewhere.
 */
export const dynamic = 'force-dynamic';

export const metadata = {
  robots: { index: false, follow: false },
};

export default async function AccountLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  return <div className="py-8">{children}</div>;
}
