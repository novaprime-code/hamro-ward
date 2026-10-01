import Link from 'next/link';
import { notFound } from 'next/navigation';

import { SourceCard } from '@/components/civic/source-card';
import { StateNotice } from '@/components/civic/state-notice';
import { ProseSection } from '@/components/layout/prose-section';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchEvidence } from '@/lib/api';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = {
  locale: string;
  province: string;
  district: string;
  localLevel: string;
  subjectType: string;
  subjectId: string;
};

export const revalidate = 60;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, subjectType, subjectId } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const title = t('evidence.title');
  const description = t('evidence.intro');
  const href = `/${locale}/source/${province}/${district}/${localLevel}/${subjectType}/${subjectId}`;

  return {
    title,
    description,
    alternates: { canonical: href },
    ...shareMetadata({ locale, title, description, path: href, siteName: t('site.name') }),
    /*
     * Not indexed, but followed and shareable.
     *
     * The address carries a uuid, so as a search result it is meaningless to a
     * reader — and there is one of these per record, which would swamp the ward
     * pages that should be found. Sharing still works: Open Graph tags are read
     * regardless of noindex, and this page being shareable is the whole reason
     * it is a page rather than a dialog (D-021).
     */
    robots: { index: false, follow: true },
  };
}

/**
 * Show the working (HW-E04-F02, FR-SRC-03).
 *
 * The page every provenance badge on the site now points at. It is the answer
 * to the only question that makes the rest of the platform worth trusting:
 * says who?
 *
 * Three things it refuses to do:
 *
 *  - It does not hide an empty result. A record with no sources gets a page
 *    saying exactly that, because "we have not confirmed this" is the honest
 *    state the seat list already reports and it deserves the same page as any
 *    other answer.
 *  - It does not resolve a disagreement. Where two sources conflict, both are
 *    shown, in authority order, under a heading that says they disagree. A
 *    platform that picked one quietly would be doing the thing it tells its
 *    readers not to accept (§4).
 *  - It does not rank by what we believe. The order is the source hierarchy —
 *    Election Commission before municipality before social media — and nothing
 *    else.
 */
export default async function EvidencePage({ params }: { params: Promise<PageParams> }) {
  const { locale, province, district, localLevel, subjectType, subjectId } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const path = `${province}/${district}/${localLevel}`;
  const t = translator(await getMessages(locale));
  const result = await fetchEvidence(path, subjectType, subjectId);

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

  const evidence = result.data;

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('evidence.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('evidence.intro')}</p>
      </header>

      {evidence.is_empty ? (
        <StateNotice tone="unverified" title={t('evidence.none')}>
          {t('evidence.noneHelp')}
        </StateNotice>
      ) : null}

      {evidence.record.length === 0 ? null : (
        <ProseSection id="record" heading={t('evidence.wholeRecord')}>
          <div className="space-y-3">
            {evidence.record.map((source) => (
              <SourceCard key={source.source_id} source={source} locale={locale} t={t} />
            ))}
          </div>
        </ProseSection>
      )}

      {evidence.fields.map((field) => (
        <ProseSection
          key={field.field_path}
          id={`field-${field.field_path}`}
          heading={fieldLabel(field.field_path, t)}
        >
          {field.in_conflict ? (
            /* The disagreement is announced before the sources, not after.
               A reader who stops at the first card must not leave believing
               the question is settled. */
            <StateNotice tone="unverified" title={t('evidence.conflict')}>
              {t('evidence.conflictHelp')}
            </StateNotice>
          ) : null}

          <div className="space-y-3">
            {field.sources.map((source) => (
              <SourceCard key={source.source_id} source={source} locale={locale} t={t} />
            ))}
          </div>
        </ProseSection>
      ))}

      <ProseSection id="how" heading={t('evidence.howHeading')}>
        <p>{t('evidence.howBody')}</p>
        <p>
          <Link className="underline" href={`/${locale}/sources`}>
            {t('sources.title')}
          </Link>
        </p>
      </ProseSection>
    </div>
  );
}

/**
 * A readable heading for a field, or an honest generic one.
 *
 * The translator returns the key itself when it has no entry, which would put
 * `evidence.field.some_column` on screen as a heading — a database column name,
 * shown to a citizen, on the page whose whole job is to be legible. A field we
 * have not written a label for yet gets "About one detail" instead, and the
 * sources below it still say what they claim.
 */
function fieldLabel(fieldPath: string, t: (key: string) => string): string {
  const key = `evidence.field.${fieldPath}`;
  const label = t(key);

  return label === key ? t('evidence.field.generic') : label;
}
