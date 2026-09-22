import { notFound } from 'next/navigation';

import { ApiStatus } from '@/components/api-status';
import { formatNumber, isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';

export const revalidate = 300;

export function generateStaticParams() {
  return [{ locale: 'ne' }, { locale: 'en' }];
}

async function fetchHealth(): Promise<'ok' | 'down'> {
  const origin = process.env.API_INTERNAL_URL ?? 'http://127.0.0.1:8000';

  try {
    const response = await fetch(`${origin}/api/v1/health`, {
      next: { revalidate: 30, tags: ['health'] },
    });

    if (!response.ok) {
      return 'down';
    }

    const body: { status?: string } = await response.json();

    return body.status === 'ok' ? 'ok' : 'down';
  } catch {
    return 'down';
  }
}

export default async function HomePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const t = translator(await getMessages(locale));
  const health = await fetchHealth();

  return (
    <div className="py-8">
      <h1 className="text-[28px]">{t('home.title')}</h1>
      <p className="mt-4 text-slate">{t('home.intro')}</p>

      {/* Placeholder ward plate; real ward data arrives with HW-E08-F01-T02. */}
      <section className="ward-plate mt-8" aria-label={`${t('home.wardPlateLabel')} 4`}>
        <span className="text-sm uppercase tracking-wide opacity-80">
          {t('home.wardPlateLabel')}
        </span>
        <span className="ward-plate__number">{formatNumber(4, locale)}</span>
      </section>

      <ApiStatus
        status={health}
        okLabel={t('status.apiOk')}
        downLabel={t('status.apiDown')}
      />
    </div>
  );
}
