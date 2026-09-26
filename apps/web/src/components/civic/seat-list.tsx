import { SeatRow } from '@/components/civic/seat-row';
import { StateNotice } from '@/components/civic/state-notice';
import { pick } from '@/lib/api';
import type { Seat } from '@/lib/api';
import type { Locale } from '@/i18n/config';

/**
 * A constituency's seats, rendered from the three states the platform can
 * honestly report (docs/05 §5.5).
 *
 * The mapping is the whole trust model in one component:
 *
 *   held          a name, a party, an "official source" badge
 *   vacant        a notice saying vacant, and why — this is an answer
 *   not_verified  either the name we have, clearly marked unconfirmed, or a
 *                 notice that nothing is confirmed yet
 *
 * A seat is never omitted. Dropping the ones we know nothing about would tell
 * a citizen their ward has three representatives when it has seven, which is a
 * worse lie than admitting ignorance.
 *
 * Every row uses the same template whatever the party: no colour, no ordering
 * advantage, no emphasis (NFR-NEU-01, NFR-NEU-02).
 */
export function SeatList({
  seats,
  locale,
  t,
  basePath,
}: {
  seats: Seat[];
  locale: Locale;
  t: (key: string) => string;
  basePath: string;
}) {
  if (seats.length === 0) {
    return (
      <StateNotice tone="neutral" title={t('seats.none')}>
        {t('seats.noneHelp')}
      </StateNotice>
    );
  }

  return (
    <div>
      {seats.map((seat) => (
        <Seat
          key={`${seat.position_key}-${seat.seat_index}-${seat.constituency_level}`}
          seat={seat}
          locale={locale}
          t={t}
          basePath={basePath}
        />
      ))}
    </div>
  );
}

function Seat({
  seat,
  locale,
  t,
  basePath,
}: {
  seat: Seat;
  locale: Locale;
  t: (key: string) => string;
  basePath: string;
}) {
  const role = pick(seat.title, locale);

  if (seat.state === 'vacant') {
    return (
      <div className="border-b border-border py-3">
        <span className="mb-2 block text-sm text-muted-foreground">{role}</span>
        {/* The reason is the point. "No candidate stood" and "resigned" are
            different facts about a ward, and the reserved-seat case is a real
            and common one (docs/02 §4.2). */}
        <StateNotice tone="neutral" title={t('state.vacant')}>
          {t(`vacancy.${seat.vacancy?.reason ?? 'other'}`)}
        </StateNotice>
      </div>
    );
  }

  if (seat.state === 'not_verified' && seat.person === null) {
    return (
      <div className="border-b border-border py-3">
        <span className="mb-2 block text-sm text-muted-foreground">{role}</span>
        <StateNotice tone="unverified" title={t('state.notVerified')}>
          {t('state.notVerifiedHelp')}
        </StateNotice>
      </div>
    );
  }

  const name = seat.person === null ? t('state.notVerified') : pick(seat.person.name, locale);
  const party = seat.is_independent
    ? t('seat.independent')
    : seat.party === null
      ? undefined
      : pick(seat.party.name, locale);

  return (
    <SeatRow
      role={role}
      name={name}
      party={party}
      href={seat.person === null ? basePath : `${basePath}#${seat.position_key}-${seat.seat_index}`}
      provenance={seat.state === 'held' ? 'official' : 'unverified_claim'}
      provenanceLabel={seat.state === 'held' ? t('provenance.official') : t('state.notVerified')}
    />
  );
}
