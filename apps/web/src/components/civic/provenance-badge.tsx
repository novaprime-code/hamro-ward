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
  onOpen,
  className,
}: {
  type: ProvenanceType;
  label: string;
  /** When given, the badge opens the source sheet (HW-E04-F02). */
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
