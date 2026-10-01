import type { Metadata } from 'next';
import { Anek_Devanagari, Noto_Sans_Devanagari } from 'next/font/google';
import { notFound } from 'next/navigation';

import { BetaBanner } from '@/components/layout/beta-banner';
import { SiteFooter } from '@/components/layout/site-footer';
import { SiteHeader } from '@/components/layout/site-header';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { operator } from '@/lib/operator';
import { siteUrl } from '@/lib/site';
import { resolveTheme } from '@/lib/theme';

import './globals.css';

/* Self-hosted through next/font: no request to Google at runtime, and the
   Devanagari subset only. Anek has a width axis, used by the ward plate. */
const anek = Anek_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['600', '700', '800'],
  variable: '--font-anek',
  display: 'swap',
});

const noto = Noto_Sans_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['400', '500', '600'],
  variable: '--font-noto',
  display: 'swap',
});

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));

  return {
    title: { default: t('site.name'), template: `%s · ${t('site.name')}` },
    description: t('site.tagline'),
    // Read at request time from HW_SITE_URL, not baked in as NEXT_PUBLIC_*.
    // A hostname compiled into the bundle would make this image belong to
    // one environment and end promotion (D-015).
    metadataBase: siteUrl(),
    alternates: {
      canonical: `/${locale}`,
      languages: { ne: '/ne', en: '/en', 'x-default': '/ne' },
    },
    /*
     * Share defaults for every page under this locale (§18).
     *
     * The images themselves are not listed here: Next collects them from the
     * opengraph-image files in the route tree, so each page contributes its
     * own card and inherits this one's when it has none. Naming images here
     * would override that and give every ward the same picture.
     *
     * og:locale matters more than usual — without it a crawler guesses from
     * the page language, and Devanagari titles are what it guesses wrong.
     */
    openGraph: {
      type: 'website',
      siteName: t('site.name'),
      title: t('site.name'),
      description: t('site.tagline'),
      locale: locale === 'ne' ? 'ne_NP' : 'en_US',
      alternateLocale: locale === 'ne' ? 'en_US' : 'ne_NP',
      url: `/${locale}`,
    },
    twitter: {
      card: 'summary_large_image',
      title: t('site.name'),
      description: t('site.tagline'),
    },
  };
}

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const messages = await getMessages(locale);
  const t = translator(messages);

  // Read at request time, so changing HW_THEME and restarting is enough —
  // no rebuild, no redeploy of the image.
  const theme = resolveTheme();

  return (
    <html lang={locale} data-theme={theme} className={`${anek.variable} ${noto.variable}`}>
      <body className="min-h-dvh bg-background text-foreground">
        <BetaBanner message={t('beta.banner')} />
        <SiteHeader
          locale={locale}
          siteName={t('site.name')}
          switchLabel={t('locale.switch')}
          searchLabel={t('search.link')}
        />
        <main className="mx-auto w-full max-w-[var(--content-width)] px-4 pb-16">{children}</main>
        <SiteFooter
          links={[
            { href: `/${locale}/about`, label: t('footer.about') },
            { href: `/${locale}/sources`, label: t('footer.sources') },
            { href: `/${locale}/privacy`, label: t('footer.privacy') },
          ]}
          corrections={corrections(locale, t('footer.reportError'))}
        />
      </body>
    </html>
  );
}

/**
 * Where "report an error" goes.
 *
 * A mailto once the operator has an address, and the Sources page until then —
 * which explains what a correction needs to be useful and says plainly that
 * there is no address yet. Both are better than a link that silently does
 * nothing (D-003 G1).
 */
function corrections(locale: string, label: string): { href: string; label: string; external: boolean } {
  const email = operator().correctionsEmail;

  return email === null
    ? { href: `/${locale}/sources#corrections-heading`, label, external: false }
    : { href: `mailto:${email}`, label, external: true };
}
