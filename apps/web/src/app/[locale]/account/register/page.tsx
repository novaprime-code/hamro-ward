import { notFound } from 'next/navigation';

import { RegisterForm } from '@/components/account/register-form';
import { isLocale } from '@/i18n/config';
import { getMessages, translator } from '@/i18n/messages';
import { accountCopy } from '@/lib/account-copy';

/**
 * Create an account (HW-E30-F01-T03).
 *
 * A server component that does one thing: pick up the strings for this locale
 * and hand them to the screen, which is a client component because every one
 * of these forms is a conversation with the API rather than a render.
 *
 * The Turnstile site key is read per request rather than baked into the
 * bundle as a NEXT_PUBLIC_ variable, so one image still serves every
 * environment (D-015). Unset means no widget, which is how a developer's
 * machine and CI run this form at all.
 */

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    return {};
  }

  const t = translator(await getMessages(locale));

  return { title: t('account.register.title'), robots: { index: false, follow: false } };
}

export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  if (!isLocale(locale)) {
    notFound();
  }

  const copy = await accountCopy(locale);

  return (
    <RegisterForm copy={copy} locale={locale} turnstileSiteKey={process.env.TURNSTILE_SITE_KEY || null} />
  );
}
