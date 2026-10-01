import { notFound } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { ProseSection } from '@/components/layout/prose-section';
import { Badge } from '@/components/ui/badge';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { operator } from '@/lib/operator';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = { locale: string };

export const revalidate = 300;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const title = t('sources.title');
  const description = t('sources.intro');
  const path = `/${locale}/sources`;

  return {
    title,
    description,
    alternates: { canonical: path },
    ...shareMetadata({ locale, title, description, path, type: 'website', siteName: t('site.name') }),
  };
}

/**
 * Sources — the page a sceptical reader goes to after seeing a badge they do
 * not recognise (Milestone A, A6, A7).
 *
 * Unlike About and Privacy, nothing on this page waits on the operator: the
 * source hierarchy, the three seat states and the conflict rule are the
 * platform's own policy and are already implemented in the schema. So this one
 * is written in full rather than stubbed.
 *
 * The three states are described in the same words and the same badges the
 * ward pages use, because the point of the page is to decode what a reader has
 * already seen. "Not yet verified" gets the longest explanation: it is the
 * state people misread as "vacant", and the difference between the two is the
 * single distinction the whole product rests on (docs/05 §5.5, FR-SRC-02).
 */
export default async function SourcesPage({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const t = translator(await getMessages(locale));
  const { correctionsEmail } = operator();

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('sources.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('sources.intro')}</p>
      </header>

      <ProseSection id="order" heading={t('sources.orderHeading')}>
        <p>{t('sources.orderBody')}</p>
      </ProseSection>

      <ProseSection id="states" heading={t('sources.stateHeading')}>
        <dl className="space-y-3">
          <State
            badge={<Badge variant="verified">{t('sources.stateHeld')}</Badge>}
            help={t('sources.stateHeldHelp')}
          />
          <State
            badge={<Badge variant="neutral">{t('sources.stateVacant')}</Badge>}
            help={t('sources.stateVacantHelp')}
          />
          <State
            badge={<Badge variant="unverified">{t('sources.stateNotVerified')}</Badge>}
            help={t('sources.stateNotVerifiedHelp')}
          />
        </dl>
      </ProseSection>

      <ProseSection id="conflicts" heading={t('sources.conflictHeading')}>
        <p>{t('sources.conflictBody')}</p>
      </ProseSection>

      <ProseSection id="corrections" heading={t('sources.correctionsHeading')}>
        {correctionsEmail === null ? (
          // A "report an error" link that goes nowhere is worse than none: it
          // spends a reader's goodwill and returns nothing (D-003 G1).
          <StateNotice tone="unverified" title={t('sources.correctionsHeading')}>
            {t('sources.correctionsPending')}
          </StateNotice>
        ) : (
          <>
            <p>{t('sources.correctionsBody')}</p>
            <p>
              <a className="underline" href={`mailto:${correctionsEmail}`}>
                {correctionsEmail}
              </a>
            </p>
          </>
        )}
      </ProseSection>
    </div>
  );
}

function State({ badge, help }: { badge: React.ReactNode; help: string }) {
  return (
    <div className="rounded-control border border-border bg-muted p-3">
      <dt>{badge}</dt>
      <dd className="mt-1.5 text-[15px]">{help}</dd>
    </div>
  );
}
