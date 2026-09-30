import { CircleDashed, CircleSlash } from 'lucide-react';

import { SeatRow } from '@/components/civic/seat-row';
import { StateNotice } from '@/components/civic/state-notice';
import { Card, CardContent } from '@/components/ui/card';
import { pick } from '@/lib/api';
import type { Seat } from '@/lib/api';
import type { Locale } from '@/i18n/config';
import { cn } from '@/lib/utils';

/**
 * A constituency's seats, rendered from the three states the platform can
 * honestly report (docs/05 §5.5).
 *
 * The mapping is the whole trust model in one component:
 *
 *   held          a name, a party, an "official source" badge
 *   vacant        a card saying vacant, and why — this is an answer
 *   not_verified  either the name we have, clearly marked unconfirmed, or a
 *                 card saying nothing is confirmed yet
 *
 * A seat is never omitted. Dropping the ones we know nothing about would tell
 * a citizen their ward has three representatives when it has seven, which is a
 * worse lie than admitting ignorance.
 *
 * All three states are the same card, the same size, in ballot order. An
 * unfilled seat that looked like a smaller or greyer thing would read as less
 * important than a filled one, and it is not — it is the part of the ward
 * nobody has answered for yet. Only the border tells them apart: a dashed edge
 * reads as unfinished before the words are read, and it is never the only
 * signal (NFR-ACC-01).
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
    <div className="space-y-3">
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
      <SeatStateCard
        role={role}
        // The reason is the point. "No candidate stood" and "resigned" are
        // different facts about a ward, and the reserved-seat case is a real
        // and common one (docs/02 §4.2).
        title={t('state.vacant')}
        help={t(`vacancy.${seat.vacancy?.reason ?? 'other'}`)}
        tone="vacant"
      />
    );
  }

  if (seat.state === 'not_verified' && seat.person === null) {
    return (
      <SeatStateCard
        role={role}
        title={t('state.notVerified')}
        help={t('state.notVerifiedHelp')}
        tone="unverified"
      />
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

/** A seat with no holder to link to: same card, no chevron, nothing to open. */
function SeatStateCard({
  role,
  title,
  help,
  tone,
}: {
  role: string;
  title: string;
  help: string;
  tone: 'vacant' | 'unverified';
}) {
  const Icon = tone === 'vacant' ? CircleSlash : CircleDashed;

  return (
    <Card className={cn(tone === 'unverified' && 'border-dashed border-pending/60')}>
      <CardContent>
        <span className="block text-sm text-muted-foreground">{role}</span>
        <span className="mt-1 flex items-center gap-2 font-display text-[19px] font-semibold leading-snug">
          <Icon
            className={cn('size-5 shrink-0', tone === 'vacant' ? 'text-vacant' : 'text-pending')}
            aria-hidden="true"
          />
          {title}
        </span>
        <span className="mt-1 block text-sm text-muted-foreground">{help}</span>
      </CardContent>
    </Card>
  );
}
