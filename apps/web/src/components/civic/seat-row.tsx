import Link from 'next/link';

import { ProvenanceBadge } from '@/components/civic/provenance-badge';
import type { ProvenanceType } from '@/components/civic/provenance-badge';

/**
 * One seat in the ward, in ballot order (FR-OFF-01). Exactly three states exist:
 * held, vacant, not yet verified — and the template is identical for everyone,
 * whatever their party (NFR-NEU-01).
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
      className="block border-b border-line py-3 hover:bg-surface-2 focus-visible:bg-surface-2"
    >
      <span className="float-right text-muted" aria-hidden="true">
        ›
      </span>
      <span className="block text-sm text-muted">{role}</span>
      <span className="mt-0.5 block font-display text-[19px] font-semibold">{name}</span>
      {party ? <span className="block text-sm text-muted">{party}</span> : null}
      <ProvenanceBadge type={provenance} label={provenanceLabel} />
    </Link>
  );
}
