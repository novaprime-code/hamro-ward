import { ImageResponse } from 'next/og';

import { DEFAULT_LOCALE, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { OG_CONTENT_TYPE, OG_SIZE, ShareCard } from '@/lib/og/card';
import { ogFonts } from '@/lib/og/fonts';
import { OG_PALETTES } from '@/lib/og/palette';
import { resolveTheme } from '@/lib/theme';

export const alt = 'Hamro Ward — your ward, your information, your voice';
export const size = OG_SIZE;
export const contentType = OG_CONTENT_TYPE;

export const revalidate = 300;

/**
 * The default card, inherited by every page under a locale that does not
 * define its own — the home page, and the three trust pages.
 *
 * Its existence is what stops an About or Sources link from previewing as a
 * bare URL, and it is also the card a crawler gets for the site root, which is
 * the link most people paste first.
 */
export default async function Image({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;

  const locale = isLocale(raw) ? raw : DEFAULT_LOCALE;
  const palette = OG_PALETTES[resolveTheme()];
  const [fonts, messages] = await Promise.all([ogFonts(), getMessages(locale)]);
  const t = translator(messages);

  return new ImageResponse(
    (
      <ShareCard
        palette={palette}
        plateLabel={null}
        plateValue={t('site.name')}
        title={t('home.title')}
        subtitle={t('site.tagline')}
        coverage={null}
        siteName={t('site.name')}
        footer={t('beta.banner')}
      />
    ),
    { ...size, fonts },
  );
}
