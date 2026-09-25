import Link from 'next/link';
import { notFound } from 'next/navigation';

import { CoverageLine } from '@/components/civic/coverage-line';
import { SeatList } from '@/components/civic/seat-list';
import { StateNotice } from '@/components/civic/state-notice';
import { formatNumber, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchLocalLevel, pick } from '@/lib/api';

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
};

export const revalidate = 120;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel } = await params;
  const result = await fetchLocalLevel(`${province}/${district}/${localLevel}`);

  if (!result.ok) {
    return {};
  }

  return {
    title: pick(result.data.name, locale),
    alternates: { canonical: `/${locale}/palika/${result.data.slug_path}` },
  };
}

/**
 * The municipality page: pick a ward, and meet the two people the whole local
 * level elects (docs/02 §4.1).
 *
 * Ward numbers are tiles rather than a dropdown because the number is what
 * people recognise — a citizen knows they live in ward 4 long before they know
 * the name of anything else about their local government (docs/09).
 */
export default async function LocalLevelPage({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const path = `${province}/${district}/${localLevel}`;
  const t = translator(await getMessages(locale));
  const result = await fetchLocalLevel(path);

  if (!result.ok) {
    if (result.status === 404) {
      notFound();
    }

    return (
      <div className="pt-6">
        <StateNotice tone="neutral" title={t('error.unavailable')}>
          {t('error.unavailableHelp')}
        </StateNotice>
      </div>
    );
  }

  const place = result.data;

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-1">
        <p className="text-sm text-muted">
          {pick(place.province, locale)} › {pick(place.district, locale)}
        </p>
        <h1 className="font-display text-[28px] font-bold leading-tight">
          {pick(place.name, locale)}
        </h1>
        <p className="text-sm text-muted">
          {place.type === null ? null : t(`type.${place.type}`)} ·{' '}
          {formatNumber(place.wards.length, locale)} {t('picker.wards')}
        </p>
      </header>

      <section className="space-y-3" aria-labelledby="wards-heading">
        <h2 id="wards-heading" className="font-display text-[21px] font-semibold">
          {t('localLevel.chooseWard')}
        </h2>

        {place.wards.length === 0 ? (
          <StateNotice tone="neutral" title={t('localLevel.noWards')}>
            {t('localLevel.noWardsHelp')}
          </StateNotice>
        ) : (
          <ul className="grid grid-cols-4 gap-2 sm:grid-cols-6">
            {place.wards.map((ward) => (
              <li key={ward.number}>
                <Link
                  href={`/${locale}/ward/${place.slug_path}/${ward.number}`}
                  className="flex h-14 items-center justify-center rounded-[var(--radius-control)] border border-line bg-surface font-display text-[19px] font-semibold hover:bg-surface-2 focus-visible:bg-surface-2"
                >
                  {formatNumber(ward.number, locale)}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-2" aria-labelledby="leadership-heading">
        <h2 id="leadership-heading" className="font-display text-[21px] font-semibold">
          {t('localLevel.leadership')}
        </h2>
        <CoverageLine
          coverage={place.coverage}
          locale={locale}
          label={(confirmed, total) => `${confirmed}/${total} ${t('coverage.confirmed')}`}
        />
        <SeatList
          seats={place.leadership}
          locale={locale}
          t={t}
          basePath={`/${locale}/palika/${place.slug_path}`}
        />
      </section>
    </div>
  );
}
