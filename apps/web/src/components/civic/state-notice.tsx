import type { ReactNode } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { cn } from '@/lib/utils';

/**
 * "Not yet verified" and "Vacant" are answers, not errors (docs/09 §2).
 *
 * They get the same care as a filled-in value, and an action where one exists.
 * A blank space says the platform forgot; this says what is known and what is
 * not, which is the whole difference.
 */
export function StateNotice({
  tone = 'unverified',
  title,
  children,
  action,
  className,
}: {
  tone?: 'unverified' | 'neutral' | 'paused';
  title: string;
  children?: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  const variant = tone === 'neutral' ? 'default' : tone;

  return (
    <Alert variant={variant} className={cn(className)}>
      <AlertTitle>{title}</AlertTitle>
      {children ? <AlertDescription>{children}</AlertDescription> : null}
      {action ? <div className="mt-2">{action}</div> : null}
    </Alert>
  );
}
