import { notFound } from 'next/navigation';

import { ForgotPasswordForm } from '@/components/account/forgot-password-form';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { accountCopy } from '@/lib/account-copy';

/**
 * Reset your password (HW-E30-F01-T03).
 *
 * A server component that does one thing: pick up the strings for this locale
 * and hand them to the screen, which is a client component because every one
 * of these forms is a conversation with the API rather than a render.
 */

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));

  return { title: t('account.forgot.title'), robots: { index: false, follow: false } };
}

export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const copy = await accountCopy(locale);

  return (
    <ForgotPasswordForm copy={copy} locale={locale} />
  );
}
