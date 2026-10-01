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
 *
 * The row holds TWO destinations, which is why it is no longer a single link
 * wrapping everything: the name goes to the person, and the badge goes to the
 * sources. An anchor inside an anchor is invalid HTML — browsers recover from
 * it by dropping the inner one, which is exactly the link the badge needs — so
 * they are siblings, each its own tap target.
 */
export function SeatRow({
  role,
  name,
  party,
  href,
  provenance,
  provenanceLabel,
  evidenceHref,
  conflictLabel,
}: {
  role: string;
  name: string;
  party?: string;
  /** The person, when there is one to link to; null leaves the name as text. */
  href: string | null;
  provenance: ProvenanceType;
  provenanceLabel: string;
  /** The sources page for this seat, when a record exists to have sources. */
  evidenceHref?: string;
  /** Rendered when the sources disagree about one of this seat's fields. */
  conflictLabel?: string;
}) {
  const body = (
    <>
      <span className="min-w-0 flex-1">
        <span className="block text-sm text-muted-foreground">{role}</span>
        <span className="mt-0.5 block font-display text-[19px] font-semibold">{name}</span>
        {party ? <span className="block text-sm text-muted-foreground">{party}</span> : null}
      </span>
      {href === null ? null : (
        <ChevronRight className="mt-1 size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
      )}
    </>
  );

  return (
    <div className="border-b border-border py-3">
      {href === null ? (
        <div className="flex items-start gap-3">{body}</div>
      ) : (
        <Link
          href={href}
          className="-mx-2 flex items-start gap-3 rounded-control px-2 py-1 transition-colors hover:bg-muted focus-visible:bg-muted"
        >
          {body}
        </Link>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <ProvenanceBadge type={provenance} label={provenanceLabel} href={evidenceHref} />

        {/* Said next to the value it is about, not buried on the evidence page.
            A reader who sees a party under an "official source" badge and is not
            told the sources disagree has been given a silent choice (§4). */}
        {conflictLabel === undefined ? null : (
          <ProvenanceBadge type="unverified_claim" label={conflictLabel} href={evidenceHref} />
        )}
      </div>
    </div>
  );
}
