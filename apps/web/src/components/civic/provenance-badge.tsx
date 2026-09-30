import {
  CircleCheck,
  CircleDashed,
  FileText,
  MessageSquareQuote,
  Newspaper,
  Sparkles,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import Link from 'next/link';

import { Badge } from '@/components/ui/badge';
import type { badgeVariants } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * The eight trust categories (project instructions §3, FR-SRC-04).
 *
 * Icon and words always travel together: colour alone never carries the
 * meaning, so the badge reads the same to someone who cannot distinguish the
 * palette's teal from its amber (NFR-ACC-01).
 *
 * The categories are never collapsed. "Official" and "a claim someone made on
 * Facebook" are different kinds of thing, and flattening them into a single
 * "source" chip is exactly the failure this product exists to avoid.
 */
export type ProvenanceType =
  | 'official'
  | 'candidate_submitted'
  | 'public_record'
  | 'verified_community_report'
  | 'community_report'
  | 'media_report'
  | 'ai_generated_summary'
  | 'unverified_claim';

type BadgeVariant = NonNullable<Parameters<typeof badgeVariants>[0]>['variant'];

const PRESENTATION: Record<ProvenanceType, { icon: LucideIcon; variant: BadgeVariant }> = {
  official: { icon: CircleCheck, variant: 'verified' },
  verified_community_report: { icon: CircleCheck, variant: 'verified' },
  public_record: { icon: FileText, variant: 'neutral' },
  candidate_submitted: { icon: MessageSquareQuote, variant: 'neutral' },
  community_report: { icon: Users, variant: 'neutral' },
  media_report: { icon: Newspaper, variant: 'neutral' },
  ai_generated_summary: { icon: Sparkles, variant: 'ai' },
  unverified_claim: { icon: CircleDashed, variant: 'unverified' },
};

export function ProvenanceBadge({
  type,
  label,
  href,
  onOpen,
  className,
}: {
  type: ProvenanceType;
  label: string;
  /**
   * The sources page for this fact (HW-E04-F02).
   *
   * A link rather than a dialog, deliberately. The evidence page is a real
   * address a reader can share, a search engine can index and a phone can
   * render without waiting for JavaScript — and on a small screen a full page
   * beats a sheet anyway (§17, §18). A modal would have been none of those.
   */
  href?: string;
  /** Legacy hook for an in-page sheet. Prefer `href`. */
  onOpen?: () => void;
  className?: string;
}) {
  const { icon: Icon, variant } = PRESENTATION[type];

  const content = (
    <>
      <Icon aria-hidden="true" />
      {label}
    </>
  );

  if (href) {
    /* asChild so the badge keeps its own markup and the anchor keeps its
       semantics. min-h-9 because a badge is small and a thumb is not: this is
       a real navigation target, not decoration (--tap-target, NFR-ACC-03). */
    return (
      <Badge
        variant={variant}
        className={cn('mt-2 min-h-9 px-3 underline-offset-4 hover:underline', className)}
        asChild
      >
        <Link href={href}>{content}</Link>
      </Badge>
    );
  }

  if (!onOpen) {
    return (
      <Badge variant={variant} className={cn('mt-2', className)}>
        {content}
      </Badge>
    );
  }

  /* asChild so the badge keeps its own markup and the button keeps its
     semantics — rather than a div with an onClick, which no keyboard reaches. */
  return (
    <Badge variant={variant} className={cn('mt-2 cursor-pointer', className)} asChild>
      <button type="button" onClick={onOpen} aria-haspopup="dialog">
        {content}
      </button>
    </Badge>
  );
}
