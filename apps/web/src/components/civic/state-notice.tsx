import type { ReactNode } from 'react';

/**
 * "Not yet verified" and "Vacant" are answers, not errors (docs/09 §2).
 * They get the same care as a filled-in value, and an action where one exists.
 */
export function StateNotice({
  tone = 'unverified',
  title,
  children,
  action,
}: {
  tone?: 'unverified' | 'neutral' | 'paused';
  title: string;
  children?: ReactNode;
  action?: ReactNode;
}) {
  const base = 'rounded-[var(--radius-control)] p-3';
  const tones = {
    unverified: 'state-unverified',
    neutral: 'border border-line bg-surface-2',
    paused: 'border border-line bg-accent-wash text-accent-ink',
  } as const;

  return (
    <div className={`${base} ${tones[tone]}`}>
      <strong className="block font-semibold">{title}</strong>
      {children ? <p className="mt-1 text-sm text-muted">{children}</p> : null}
      {action ? <div className="mt-2">{action}</div> : null}
    </div>
  );
}
