import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';

/**
 * The ward-office signboard: the one bold element on the site (docs/09 §1).
 * Ward numbers use Devanagari digits in Nepali content.
 */
export function WardPlate({
  locale,
  wardNumber,
  label,
  place,
}: {
  locale: Locale;
  wardNumber: number;
  label: string;
  place: string;
}) {
  return (
    <section className="ward-plate -mx-4" aria-label={`${label} ${wardNumber}`}>
      <span className="text-sm opacity-80">{label}</span>
      <span className="ward-plate__number" aria-hidden="true">
        {formatNumber(wardNumber, locale)}
      </span>
      <span className="text-sm opacity-90">{place}</span>
    </section>
  );
}
