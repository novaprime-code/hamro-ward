import Link from 'next/link';
import { notFound } from 'next/navigation';

import { CoverageLine } from '@/components/civic/coverage-line';
import { SeatList } from '@/components/civic/seat-list';
import { StateNotice } from '@/components/civic/state-notice';
import { WardPlate } from '@/components/civic/ward-plate';
import { Card, CardContent } from '@/components/ui/card';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchWard, pick } from '@/lib/api';

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
  ward: string;
};

export const revalidate = 60;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, ward } = await params;
  const result = await fetchWard(`${province}/${district}/${localLevel}`, Number(ward));

  if (!result.ok) {
    return {};
  }

  const place = pick(result.data.local_level.name, locale);

  return {
    title: `${pick(result.data.name, locale)} · ${place}`,
    alternates: {
      canonical: `/${locale}/ward/${province}/${district}/${localLevel}/${ward}`,
    },
  };
}

/**
 * The ward page — the reason the platform exists (§24).
 *
 * It answers one question, "who represents me here", and shows its working.
 * Two constituencies appear, separated and labelled: the ward's own five seats,
 * and the two elected by the whole municipality. Merging them would imply the
 * mayor is a ward official (docs/02 §4.1).
 *
 * Every seat the catalogue says should exist is listed, whatever is known about
 * it, and the coverage line says how much is confirmed before the reader starts
 * trusting what follows.
 */
export default async function WardPage({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, ward } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const wardNumber = Number(ward);

  if (!Number.isInteger(wardNumber) || wardNumber < 1) {
    notFound();
  }

  const path = `${province}/${district}/${localLevel}`;
  const t = translator(await getMessages(locale));
  const result = await fetchWard(path, wardNumber);

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

  const data = result.data;
  const place = data.local_level;
  const basePath = `/${locale}/ward/${path}/${wardNumber}`;

  const wardSeats = data.seats.filter((seat) => seat.constituency_level === 'ward');
  const localLevelSeats = data.seats.filter((seat) => seat.constituency_level === 'local_level');

  return (
    <div className="space-y-8">
      <WardPlate
        locale={locale}
        wardNumber={data.ward_number}
        label={t('home.wardPlateLabel')}
        place={`${pick(place.name, locale)} · ${pick(place.district, locale)} · ${pick(place.province, locale)}`}
      />

      <section className="space-y-3" aria-labelledby="ward-seats-heading">
        <h2 id="ward-seats-heading" className="font-display text-[21px] font-semibold">
          {t('ward.representatives')}
        </h2>
        <CoverageLine
          coverage={data.coverage}
          locale={locale}
          label={(confirmed, total) => `${confirmed}/${total} ${t('coverage.confirmed')}`}
        />
        <SeatList seats={wardSeats} locale={locale} t={t} basePath={basePath} />
      </section>

      {localLevelSeats.length === 0 ? null : (
        <section className="space-y-3" aria-labelledby="local-level-seats-heading">
          <h2 id="local-level-seats-heading" className="font-display text-[21px] font-semibold">
            {t('ward.localLevelSeats')}
          </h2>
          {/* Said plainly, because a voter fills these on the same ballot but
              they are not ward officials (docs/02 §4.1). */}
          <p className="text-sm text-muted-foreground">
            {t('ward.localLevelSeatsHelp').replace('{place}', pick(place.name, locale))}
          </p>
          <SeatList seats={localLevelSeats} locale={locale} t={t} basePath={basePath} />
        </section>
      )}

      <section className="space-y-3" aria-labelledby="ward-office-heading">
        <h2 id="ward-office-heading" className="font-display text-[21px] font-semibold">
          {t('ward.office')}
        </h2>

        {data.ward_office === null ? (
          <StateNotice tone="unverified" title={t('ward.officeUnknown')}>
            {t('ward.officeUnknownHelp')}
          </StateNotice>
        ) : (
          <Card>
            <CardContent className="text-[15px]">
              <dl>
                <Detail label={t('ward.address')} value={pick(data.ward_office.address, locale)} />
                <Detail
                  label={t('ward.phone')}
                  value={data.ward_office.phone}
                  href={data.ward_office.phone === null ? undefined : `tel:${data.ward_office.phone}`}
                />
                <Detail
                  label={t('ward.email')}
                  value={data.ward_office.email}
                  href={data.ward_office.email === null ? undefined : `mailto:${data.ward_office.email}`}
                />
                <Detail label={t('ward.hours')} value={pick(data.ward_office.office_hours, locale)} />
              </dl>
            </CardContent>
          </Card>
        )}
      </section>

      <p>
        <Link className="underline" href={`/${locale}/palika/${place.slug_path}`}>
          {t('ward.backToLocalLevel').replace('{place}', pick(place.name, locale))}
        </Link>
      </p>
    </div>
  );
}

/** A field nobody has filled in is skipped, not shown as an empty row. */
function Detail({
  label,
  value,
  href,
}: {
  label: string;
  value: string | null;
  href?: string;
}) {
  if (value === null || value === '') {
    return null;
  }

  return (
    <div className="border-b border-border py-3 first:pt-0 last:border-b-0 last:pb-0">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-0.5">{href === undefined ? value : <a className="underline" href={href}>{value}</a>}</dd>
    </div>
  );
}
