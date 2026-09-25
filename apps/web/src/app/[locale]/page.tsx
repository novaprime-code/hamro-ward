import { notFound } from 'next/navigation';

import { MunicipalityPicker } from '@/components/civic/municipality-picker';
import { StateNotice } from '@/components/civic/state-notice';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { fetchLocalLevels } from '@/lib/api';

/*
 * Rendered on demand rather than prerendered at build.
 *
 * It used to export generateStaticParams, which baked the page — and with it
 * anything read from the environment — into the image at build time. That is
 * incompatible with one image serving several environments (D-015), and it
 * would freeze the municipality list at whatever it was when the image was
 * built. The fetch layer caches instead, which expires.
 */
export const revalidate = 300;

export default async function HomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const t = translator(await getMessages(locale));
  const result = await fetchLocalLevels();

  return (
    <div className="space-y-6 pt-6">
      <header className="space-y-2">
        <h1 className="font-display text-[28px] font-bold leading-tight">{t('home.title')}</h1>
        <p className="text-muted">{t('home.intro')}</p>
      </header>

      <section className="space-y-3" aria-labelledby="picker-heading">
        <h2 id="picker-heading" className="font-display text-[21px] font-semibold">
          {t('picker.heading')}
        </h2>

        {!result.ok ? (
          // Distinguishes "nothing here yet" from "we could not ask".
          <StateNotice tone="neutral" title={t('error.unavailable')}>
            {t('error.unavailableHelp')}
          </StateNotice>
        ) : result.data.length === 0 ? (
          <StateNotice tone="neutral" title={t('picker.none')}>
            {t('picker.noneHelp')}
          </StateNotice>
        ) : (
          <MunicipalityPicker
            localLevels={result.data}
            locale={locale}
            labels={{
              search: t('picker.search'),
              wards: t('picker.wards'),
              empty: t('picker.empty'),
              typeOf: (type) => (type === null ? '' : t(`type.${type}`)),
            }}
          />
        )}
      </section>

      <StateNotice tone="paused" title={t('picker.notListed')}>
        {t('picker.notListedHelp')}
      </StateNotice>
    </div>
  );
}
