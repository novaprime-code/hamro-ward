import type { Metadata } from 'next';
import { Anek_Devanagari, Noto_Sans_Devanagari } from 'next/font/google';
import { notFound } from 'next/navigation';

import { BetaBanner } from '@/components/beta-banner';
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';

import './globals.css';

const anek = Anek_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['600', '700'],
  variable: '--font-anek',
  display: 'swap',
});

const noto = Noto_Sans_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['400', '600'],
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
    metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'),
    alternates: {
      canonical: `/${locale}`,
      languages: { ne: '/ne', en: '/en', 'x-default': '/ne' },
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

  return (
    <html lang={locale} className={`${anek.variable} ${noto.variable}`}>
      <body className="bg-paper text-ink">
        <BetaBanner message={t('beta.banner')} />
        <SiteHeader locale={locale} siteName={t('site.name')} switchLabel={t('locale.switch')} />
        <main className="mx-auto w-full max-w-[720px] px-4 pb-16">{children}</main>
        <SiteFooter
          links={[
            { href: `/${locale}/about`, label: t('footer.about') },
            { href: `/${locale}/sources`, label: t('footer.sources') },
            { href: `/${locale}/privacy`, label: t('footer.privacy') },
          ]}
        />
      </body>
    </html>
  );
}
