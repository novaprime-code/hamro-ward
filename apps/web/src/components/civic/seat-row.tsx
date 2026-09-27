import { ChevronRight } from 'lucide-react';
import Link from 'next/link';

import { ProvenanceBadge } from '@/components/civic/provenance-badge';
import type { ProvenanceType } from '@/components/civic/provenance-badge';

/**
 * One seat in the ward, in ballot order (FR-OFF-01). Exactly three states
 * exist: held, vacant, not yet verified — and the template is identical for
 * everyone, whatever their party (NFR-NEU-01).
 *
 * No Card here either. A list of seven cards on a phone is seven boxes to
 * scroll past; a ruled list is a list, and the ward is a list.
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
    <Link
      href={href}
      className="flex items-start gap-3 border-b border-border py-3 transition-colors hover:bg-muted focus-visible:bg-muted"
    >
      <span className="min-w-0 flex-1">
        <span className="block text-sm text-muted-foreground">{role}</span>
        <span className="mt-0.5 block font-display text-[19px] font-semibold">{name}</span>
        {party ? <span className="block text-sm text-muted-foreground">{party}</span> : null}
        <ProvenanceBadge type={provenance} label={provenanceLabel} />
      </span>
      <ChevronRight className="mt-1 size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
    </Link>
  );
}
