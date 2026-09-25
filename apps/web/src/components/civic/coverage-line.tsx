import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import type { Coverage } from '@/lib/api';

/**
 * How much of this constituency is confirmed, stated plainly (FR-OFF-04).
 *
 * A page that shows four confirmed seats and silently omits three unconfirmed
 * ones is more misleading than one that says "4 of 7 confirmed". The number is
 * the honesty, so it goes above the list rather than in a footnote.
 */
export function CoverageLine({
  coverage,
  locale,
  label,
}: {
  coverage: Coverage;
  locale: Locale;
  label: (confirmed: string, total: string) => string;
}) {
  const confirmed = coverage.held + coverage.vacant;

  return (
    <p className="text-sm text-muted">
      {label(formatNumber(confirmed, locale), formatNumber(coverage.total, locale))}
    </p>
  );
}
