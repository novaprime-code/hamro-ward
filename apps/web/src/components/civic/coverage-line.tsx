import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import type { Coverage } from '@/lib/api';

/**
 * How much of this constituency is confirmed, stated plainly (FR-OFF-04).
 *
 * A page that shows four confirmed seats and silently omits three unconfirmed
 * ones is more misleading than one that says "4 of 7 confirmed". That sentence
 * is the single thing separating this from a directory, so it is set as a
 * statement rather than a caption — but without an icon or a colour, because
 * a tick beside "4/7" would imply the other three are fine.
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
    <p className="text-[15px] font-medium text-foreground">
      {label(formatNumber(confirmed, locale), formatNumber(coverage.total, locale))}
    </p>
  );
}
