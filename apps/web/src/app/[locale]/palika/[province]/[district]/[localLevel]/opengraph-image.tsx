import { ImageResponse } from 'next/og';

import { DEFAULT_LOCALE, formatNumber, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchLocalLevel, pick } from '@/lib/api';
import { OG_CONTENT_TYPE, OG_SIZE, ShareCard } from '@/lib/og/card';
import { ogFonts } from '@/lib/og/fonts';
import { OG_PALETTES } from '@/lib/og/palette';
import { resolveTheme } from '@/lib/theme';

export const alt = 'Hamro Ward — wards and elected representatives of this municipality';
export const size = OG_SIZE;
export const contentType = OG_CONTENT_TYPE;

export const revalidate = 120;

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
};

/**
 * The municipality share card.
 *
 * The plate carries the ward count rather than a ward number, because that is
 * the number a reader is deciding between when they open this page. The
 * coverage figure describes the two seats the whole local level elects — the
 * mayor and deputy, or the chair and vice-chair — which is what the page
 * itself shows (docs/02 §4.1), not a total across every ward.
 */
export default async function Image({ params }: { params: Promise<PageParams> }) {
  const { locale: raw, province, district, localLevel } = await params;

  const locale = isLocale(raw) ? raw : DEFAULT_LOCALE;
  const palette = OG_PALETTES[resolveTheme()];
  const [fonts, messages] = await Promise.all([ogFonts(), getMessages(locale)]);
  const t = translator(messages);

  const result = await fetchLocalLevel(`${province}/${district}/${localLevel}`);
  const place = result.ok ? result.data : null;

  const coverage =
    place !== null && place.coverage.total > 0
      ? `${formatNumber(place.coverage.held, locale)}/${formatNumber(
          place.coverage.total,
          locale,
        )} ${t('coverage.confirmed')}`
      : null;

  return new ImageResponse(
    (
      <ShareCard
        palette={palette}
        plateLabel={place === null ? null : t('picker.wards')}
        plateValue={place === null ? t('site.name') : formatNumber(place.wards.length, locale)}
        title={place === null ? t('site.name') : pick(place.name, locale)}
        subtitle={
          place === null
            ? t('site.tagline')
            : `${pick(place.district, locale)} · ${pick(place.province, locale)}`
        }
        coverage={coverage}
        siteName={t('site.name')}
        footer={t('beta.banner')}
      />
    ),
    { ...size, fonts },
  );
}
