import { ChevronRight } from 'lucide-react';
import Link from 'next/link';

import { ProvenanceBadge } from '@/components/civic/provenance-badge';
import type { ProvenanceType } from '@/components/civic/provenance-badge';
import { Card, CardContent } from '@/components/ui/card';

/**
 * One seat in the ward, in ballot order (FR-OFF-01).
 *
 * The template is identical for everyone, whatever their party: same card,
 * same order of information, same weight (NFR-NEU-01, NFR-NEU-02). A reader
 * should not be able to tell from the styling which party someone belongs to.
 *
 * The whole card is the link rather than the name alone — a 44px-plus target
 * on a phone, and one focus outline instead of a ring around a word inside a
 * box (NFR-ACC-01).
 */
export function SeatRow({
  role,
  name,
  party,
  href,
  provenance,
  provenanceLabel,
}: {
  role: string;
  name: string;
  party?: string;
  href: string;
  provenance: ProvenanceType;
  provenanceLabel: string;
}) {
  return (
    <Card asChild className="transition-colors hover:bg-accent focus-visible:bg-accent">
      <Link href={href}>
        <CardContent className="flex items-start gap-3">
          <span className="min-w-0 flex-1">
            <span className="block text-sm text-muted-foreground">{role}</span>
            <span className="mt-1 block font-display text-[19px] font-semibold leading-snug">{name}</span>
            {party ? <span className="mt-0.5 block text-sm text-muted-foreground">{party}</span> : null}
            <ProvenanceBadge type={provenance} label={provenanceLabel} />
          </span>
          <ChevronRight className="mt-1 size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
        </CardContent>
      </Link>
    </Card>
  );
}
