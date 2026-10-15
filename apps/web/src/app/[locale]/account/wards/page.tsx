import { notFound } from 'next/navigation';

import { MyWards } from '@/components/account/my-wards';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { accountCopy } from '@/lib/account-copy';
import { fetchLocalLevels } from '@/lib/api';

/**
 * My wards (HW-E30-F02-T02).
 *
 * The list of municipalities is fetched on the server, as the home page does:
 * it is the same public, cacheable list, and sending it with the page means
 * the picker works without a second round trip on a connection that may not
 * have one to spare (§17). Everything user-specific is fetched by the client
 * component afterwards, so nothing here varies by who is asking.
 */
export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));

  return { title: t('account.wards.title'), robots: { index: false, follow: false } };
}

export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const messages = await getMessages(locale);
  const t = translator(messages);
  const [copy, result] = await Promise.all([accountCopy(locale), fetchLocalLevels()]);

  const localLevels = result.ok ? result.data : [];

  // Resolved here, into a plain object: a client component cannot call the
  // translator across the serialisation boundary.
  const typeLabels: Record<string, string> = {};

  for (const level of localLevels) {
    if (level.type !== null) {
      typeLabels[level.type] = t(`type.${level.type}`);
    }
  }

  return <MyWards copy={copy} locale={locale} localLevels={localLevels} typeLabels={typeLabels} />;
}
