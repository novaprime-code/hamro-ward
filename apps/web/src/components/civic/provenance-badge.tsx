import type { ReactNode } from 'react';

/**
 * The eight trust categories (project instructions §3, FR-SRC-04).
 * Icon and words always travel together: colour alone never carries the meaning.
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

const TONE: Record<ProvenanceType, string> = {
  official: 'text-verified border-verified/35',
  public_record: 'text-muted border-line',
  verified_community_report: 'text-verified border-verified/35',
  community_report: 'text-muted border-line',
  candidate_submitted: 'text-muted border-line',
  media_report: 'text-muted border-line',
  ai_generated_summary: 'text-accent-ink border-accent/50',
  unverified_claim: 'text-pending border-pending/60 border-dashed',
};

function Icon({ type }: { type: ProvenanceType }): ReactNode {
  const common = { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2 };

  switch (type) {
    case 'official':
    case 'verified_community_report':
      return (
        <svg {...common} aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="m8 12 3 3 5-6" />
        </svg>
      );
    case 'public_record':
      return (
        <svg {...common} aria-hidden="true">
          <path d="M7 3h7l4 4v14H7z" />
          <path d="M14 3v5h4" />
        </svg>
      );
    case 'candidate_submitted':
      return (
        <svg {...common} aria-hidden="true">
          <path d="M5 5h14v10H9l-4 4z" />
        </svg>
      );
    case 'media_report':
      return (
        <svg {...common} aria-hidden="true">
          <path d="M4 5h13v14H4z" />
          <path d="M17 9h3v8a2 2 0 0 1-3 2" />
          <path d="M7 9h7M7 13h7" />
        </svg>
      );
    case 'ai_generated_summary':
      return (
        <svg {...common} aria-hidden="true">
          <path d="M5 8h14M5 12h9M5 16h11" />
        </svg>
      );
    default:
      return (
        <svg {...common} strokeDasharray="3 3" aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
        </svg>
      );
  }
}

export function ProvenanceBadge({
  type,
  label,
  onOpen,
}: {
  type: ProvenanceType;
  label: string;
  onOpen?: () => void;
}) {
  const className =
    `mt-2 inline-flex items-center gap-1.5 rounded-full border bg-surface-2 px-2.5 py-1 text-sm ${TONE[type]}`;

  if (!onOpen) {
    return (
      <span className={className}>
        <Icon type={type} />
        {label}
      </span>
    );
  }

  return (
    <button type="button" onClick={onOpen} className={className} aria-haspopup="dialog">
      <Icon type={type} />
      {label}
    </button>
  );
}
