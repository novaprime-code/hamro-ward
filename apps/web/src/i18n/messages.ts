import 'server-only';

import type { Locale } from '@/i18n/config';
import { DEFAULT_LOCALE } from '@/i18n/config';

export type Messages = Record<string, string>;

const loaders: Record<Locale, () => Promise<{ default: Messages }>> = {
  ne: () => import('../../messages/ne.json'),
  en: () => import('../../messages/en.json'),
};

export async function getMessages(locale: Locale): Promise<Messages> {
  const [target, fallback] = await Promise.all([
    loaders[locale](),
    loaders[DEFAULT_LOCALE](),
  ]);

  return { ...fallback.default, ...target.default };
}

/**
 * Minimal translator. A library (for example next-intl) is evaluated once we need
 * plurals, dates and rich text — tracked in HW-E07-F01-T01 follow-ups.
 */
export function translator(messages: Messages) {
  return (key: string, values?: Record<string, string | number>): string => {
    const template = messages[key] ?? key;

    if (!values) {
      return template;
    }

    return Object.entries(values).reduce(
      (text, [name, value]) => text.replaceAll(`{${name}}`, String(value)),
      template,
    );
  };
}
