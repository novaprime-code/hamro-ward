import 'server-only';

import type { Copy } from '@/components/account/account-panel';
import type { Locale } from '@/i18n/config';
import { getMessages } from '@/i18n/messages';

/**
 * The account strings for one locale, ready to hand to a client component.
 *
 * The catalogues are server-only on purpose (i18n/messages.ts): a client
 * component that imported them would ship both languages to every phone. The
 * account screens are client components with nowhere to read a locale from,
 * so the page reads the strings here and passes them down as one object.
 *
 * The `account.` prefix is dropped, so a component asks for `signIn.title`.
 */
export async function accountCopy(locale: Locale): Promise<Copy> {
  const messages = await getMessages(locale);
  const copy: Copy = {};

  for (const [key, value] of Object.entries(messages)) {
    if (key.startsWith('account.')) {
      copy[key.slice('account.'.length)] = value;
    }
  }

  return copy;
}
