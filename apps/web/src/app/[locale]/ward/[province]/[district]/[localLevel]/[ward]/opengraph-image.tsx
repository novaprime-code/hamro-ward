import { ImageResponse } from 'next/og';

import { DEFAULT_LOCALE, formatNumber, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchWard, pick } from '@/lib/api';
import { OG_CONTENT_TYPE, OG_SIZE, ShareCard } from '@/lib/og/card';
import { ogFonts } from '@/lib/og/fonts';
import { OG_PALETTES } from '@/lib/og/palette';
import { resolveTheme } from '@/lib/theme';

export const alt = 'Hamro Ward — who represents this ward, and the sources behind it';
export const size = OG_SIZE;
export const contentType = OG_CONTENT_TYPE;

/** Matched to the ward page, so the card and the page it previews never disagree. */
export const revalidate = 60;

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
  ward: string;
};

/**
 * The ward share card (Milestone A, A4).
 *
 * A failed fetch still returns an image. Throwing here would leave the crawler
 * with a 500 and the share with no preview at all — and the most likely moment
 * for the API to be briefly unreachable is a deploy, which is exactly when
 * people are being sent links.
 */
export default async function Image({ params }: { params: Promise<PageParams> }) {
  const { locale: raw, province, district, localLevel, ward } = await params;

  const locale = isLocale(raw) ? raw : DEFAULT_LOCALE;
  const palette = OG_PALETTES[resolveTheme()];
  const [fonts, messages] = await Promise.all([ogFonts(), getMessages(locale)]);
  const t = translator(messages);

  const wardNumber = Number(ward);
  const result = Number.isInteger(wardNumber)
    ? await fetchWard(`${province}/${district}/${localLevel}`, wardNumber)
    : ({ ok: false, status: 404 } as const);

  const place = result.ok ? result.data.local_level : null;

  // Seats we can stand behind, over seats this ward is supposed to have. Both
  // numbers in the reader's own digits (§16).
  const coverage =
    result.ok && result.data.coverage.total > 0
      ? `${formatNumber(result.data.coverage.held, locale)}/${formatNumber(
          result.data.coverage.total,
          locale,
        )} ${t('coverage.confirmed')}`
      : null;

  return new ImageResponse(
    (
      <ShareCard
        palette={palette}
        plateLabel={t('home.wardPlateLabel')}
        plateValue={formatNumber(Number.isInteger(wardNumber) ? wardNumber : 0, locale)}
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
