import Link from 'next/link';
import { notFound } from 'next/navigation';

import { CoverageLine } from '@/components/civic/coverage-line';
import { SeatList } from '@/components/civic/seat-list';
import { StateNotice } from '@/components/civic/state-notice';
import { buttonVariants } from '@/components/ui/button';
import { formatNumber, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchLocalLevel, pick } from '@/lib/api';
import { cn } from '@/lib/utils';

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
  const name = pick(place.name, locale);

  /*
   * A local level's name already ends in its type in both languages, so
   * "Koshara Sub-Metropolitan City · Sub-metropolitan city" says the same word
   * twice. The type is printed only when the name does not already carry it,
   * which keeps it useful for an imported name that breaks the convention.
   * Case-folded: the labels are sentence case, the names title case.
   */
  const typeLabel = place.type === null ? '' : t(`type.${place.type}`);
  const subtitle = [
    typeLabel !== '' && !name.toLowerCase().includes(typeLabel.toLowerCase()) ? typeLabel : null,
    `${formatNumber(place.wards.length, locale)} ${t('picker.wards')}`,
  ]
    .filter((part): part is string => part !== null)
    .join(' · ');

  return (
    <div className="space-y-8 pt-6">
      <header className="space-y-1">
        <p className="text-sm text-muted-foreground">
          {pick(place.province, locale)} › {pick(place.district, locale)}
        </p>
        <h1 className="font-display text-[28px] font-bold leading-tight">{name}</h1>
        <p className="text-sm text-muted-foreground">{subtitle}</p>
      </header>

      <section className="space-y-4" aria-labelledby="wards-heading">
        <h2 id="wards-heading" className="font-display text-[21px] font-semibold">
          {t('localLevel.chooseWard')}
        </h2>

        {place.wards.length === 0 ? (
          <StateNotice tone="neutral" title={t('localLevel.noWards')}>
            {t('localLevel.noWardsHelp')}
          </StateNotice>
        ) : (
          <ul className="grid grid-cols-4 gap-3 sm:grid-cols-6">
            {place.wards.map((ward) => (
              <li key={ward.number}>
                <Link
                  href={`/${locale}/ward/${place.slug_path}/${ward.number}`}
                  className={cn(
                    buttonVariants({ variant: 'outline' }),
                    'h-14 w-full font-display text-[19px]',
                  )}
                >
                  {formatNumber(ward.number, locale)}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3" aria-labelledby="leadership-heading">
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
