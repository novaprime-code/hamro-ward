import { notFound } from 'next/navigation';

import { StateNotice } from '@/components/civic/state-notice';
import { ProseSection } from '@/components/layout/prose-section';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { isOperatorPublishable, operator } from '@/lib/operator';
import { shareMetadata } from '@/lib/share-metadata';

type PageParams = { locale: string };

/*
 * Rendered on demand and cached, not prerendered at build — the same reason as
 * the home page (D-015). The operator's details are read from the environment,
 * and a page baked at build time would carry whatever was set on the build
 * machine into every environment the image is promoted to.
 *
 * Five minutes is generous for a page whose content changes roughly once, on
 * the day the operator agreement is signed. Restarting the stack clears it.
 */
export const revalidate = 300;

export async function generateMetadata({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));
  const title = t('about.title');
  const description = t('about.intro');
  const path = `/${locale}/about`;

  return {
    title,
    description,
    alternates: { canonical: path },
    ...shareMetadata({ locale, title, description, path, type: 'website', siteName: t('site.name') }),
  };
}

/**
 * About — one of the three pages the footer has linked to since the first
 * deploy without any of them existing (Milestone A, A6).
 *
 * It names the operator, and where it cannot, it says why rather than showing
 * a placeholder. A civic platform that prints an unverifiable company name
 * while telling its readers not to accept unverifiable claims has argued
 * against itself on its own About page (D-003 G1–G2, §3).
 */
export default async function AboutPage({ params }: { params: Promise<PageParams> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const t = translator(await getMessages(locale));
  const who = operator();

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('about.title')}</h1>
        <p className="max-w-[var(--measure)] text-muted-foreground">{t('about.intro')}</p>
      </header>

      <ProseSection id="what" heading={t('about.whatHeading')}>
        <p>{t('about.whatBody')}</p>
      </ProseSection>

      <ProseSection id="neutrality" heading={t('about.notHeading')}>
        <p>{t('about.notBody')}</p>
      </ProseSection>

      <ProseSection id="operator" heading={t('about.operatorHeading')}>
        {isOperatorPublishable(who) ? (
          <dl className="rounded-control border border-border bg-muted p-3 text-[15px]">
            <div className="border-b border-border py-2">
              <dt className="text-sm text-muted-foreground">{t('about.operatorHeading')}</dt>
              <dd className="mt-0.5">{who.name}</dd>
            </div>
            <div className="py-2">
              <dt className="text-sm text-muted-foreground">{t('about.operatorRegistration')}</dt>
              <dd className="mt-0.5 font-mono">{who.registration}</dd>
            </div>
          </dl>
        ) : (
          <StateNotice tone="unverified" title={t('about.operatorPending')}>
            {t('about.operatorPendingHelp')}
          </StateNotice>
        )}
      </ProseSection>

      <ProseSection id="beta" heading={t('about.betaHeading')}>
        <p>{t('about.betaBody')}</p>
      </ProseSection>
    </div>
  );
}
