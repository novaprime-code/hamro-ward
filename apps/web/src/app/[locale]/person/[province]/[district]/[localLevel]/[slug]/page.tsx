import Link from 'next/link';
import { notFound } from 'next/navigation';

import { SeatList } from '@/components/civic/seat-list';
import { ShareButton } from '@/components/civic/share-button';
import { StateNotice } from '@/components/civic/state-notice';
import { ProvenanceBadge } from '@/components/civic/provenance-badge';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchPerson, pick } from '@/lib/api';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
  slug: string;
};

export const revalidate = 60;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, slug } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const path = `${province}/${district}/${localLevel}`;
  const result = await fetchPerson(path, slug);

  if (!result.ok) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const name = pick(result.data.name, locale);
  const place = pick(result.data.local_level.name, locale);
  const title = `${name} · ${place}`;
  const role = result.data.seats[0] === undefined ? place : pick(result.data.seats[0].title, locale);
  const description = t('share.personDescription', { name, role, place });
  const href = `/${locale}/person/${path}/${slug}`;

  return {
    title,
    description,
    alternates: { canonical: href },
    ...shareMetadata({ locale, title, description, path: href, siteName: t('site.name') }),
  };
}

/**
 * One representative, and what they hold in this municipality
 * (HW-E05-F02, FR-OFF-05).
 *
 * Until this page existed, a held seat linked to an anchor on the page the
 * reader was already on.
 *
 * What it does not do is as deliberate as what it does. There is no biography,
 * no photograph gallery, no achievements, no "about" written by anyone. The
 * Person record carries no gender, caste, ethnicity, religion, date of birth or
 * address, so there is nothing here to render from — and a civic directory
 * needs none of it to say who holds a seat. A page that grew those fields would
 * become a profile, and profiles invite judgement of the person rather than
 * scrutiny of the record (§2).
 *
 * The page is scoped to this municipality and to current terms, and says so.
 * Silently showing part of a career while looking complete would be the kind of
 * unstated claim this platform exists to avoid.
 */
export default async function PersonPage({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, slug } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const path = `${province}/${district}/${localLevel}`;
  const t = translator(await getMessages(locale));
  const result = await fetchPerson(path, slug);

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

  const person = result.data;
  const place = person.local_level;
  const name = pick(person.name, locale);

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-1">
        <div className="flex justify-end">
          <ShareButton
            path={`/${locale}/person/${place.slug_path}/${person.slug}`}
            title={`${name} · ${pick(place.name, locale)}`}
            labels={{ share: t('share.button'), copied: t('share.copied'), failed: t('share.copyFailed') }}
          />
        </div>
        <p className="text-sm text-muted-foreground">
          {pick(place.province, locale)} › {pick(place.district, locale)} ›{' '}
          <Link className="underline" href={`/${locale}/palika/${place.slug_path}`}>
            {pick(place.name, locale)}
          </Link>
        </p>
        <h1 className="font-display text-[28px] font-bold leading-tight">{name}</h1>
        {/* Evidence that this name is this person — a separate claim from
            evidence that they hold a seat, and kept separate so that a verified
            holding cannot imply a verified identity. */}
        <ProvenanceBadge
          type="official"
          label={t('evidence.aboutThisRecord')}
          href={`/${locale}/source/${path}/${person.evidence.subject_type}/${person.evidence.subject_id}`}
        />
      </header>

      <section className="space-y-2" aria-labelledby="held-heading">
        <h2 id="held-heading" className="font-display text-[21px] font-semibold">
          {t('person.currentSeats')}
        </h2>
        <p className="max-w-[var(--measure)] text-sm text-muted-foreground">
          {t('person.scopeNote').replace('{place}', pick(place.name, locale))}
        </p>
        <SeatList
          seats={person.seats}
          locale={locale}
          t={t}
          localLevelPath={place.slug_path}
          linkPeople={false}
        />
      </section>

      <p>
        <Link className="underline" href={`/${locale}/palika/${place.slug_path}`}>
          {t('ward.backToLocalLevel').replace('{place}', pick(place.name, locale))}
        </Link>
      </p>
    </div>
  );
}
