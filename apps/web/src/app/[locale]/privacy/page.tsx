import { notFound } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { ProseSection } from '@/components/layout/prose-section';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = { locale: string };

export const revalidate = 300;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const title = t('privacy.title');
  const description = t('privacy.draftNotice');
  const path = `/${locale}/privacy`;

  return {
    title,
    description,
    alternates: { canonical: path },
    ...shareMetadata({ locale, title, description, path, type: 'website', siteName: t('site.name') }),
  };
}

/**
 * Privacy — deliberately a statement of fact about today, not a legal notice.
 *
 * The real notice needs the operator's legal review (D-003 G5), and none of
 * the things a privacy notice normally governs exist yet: no accounts, no
 * uploads, no reporter contact details, no analytics. Publishing boilerplate
 * now would mean publishing promises nobody has checked and no one is
 * accountable for — on the page whose entire job is to be checkable.
 *
 * So this page says three true things and marks itself as incomplete. Every
 * claim below is a claim about the application, which is verifiable from the
 * source: there is no analytics script, no advertising, and the public read
 * path is unauthenticated and sets no session cookie. The hosting layer's
 * log retention is not the application's to state, so it is named as open
 * rather than guessed at.
 *
 * This page must be rewritten, not extended, before v0.2 opens citizen
 * accounts and issue reporting (HW-E30, HW-E11).
 */
export default async function PrivacyPage({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const t = translator(await getMessages(locale));

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('privacy.title')}</h1>
      </header>

      <StateNotice tone="unverified" title={t('privacy.draftNotice')}>
        {t('privacy.draftNoticeHelp')}
      </StateNotice>

      <ProseSection id="today" heading={t('privacy.todayHeading')}>
        <ul className="list-disc space-y-2 pl-5">
          <li>{t('privacy.noAccounts')}</li>
          <li>{t('privacy.noTracking')}</li>
          <li>{t('privacy.serverLogs')}</li>
        </ul>
      </ProseSection>

      <ProseSection id="next" heading={t('privacy.nextHeading')}>
        <p>{t('privacy.nextBody')}</p>
      </ProseSection>
    </div>
  );
}
