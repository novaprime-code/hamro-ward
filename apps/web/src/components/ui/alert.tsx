import * as React from 'react';
import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

/**
 * Used for the states this platform reports as answers rather than errors:
 * vacant, not yet verified, nothing known, submissions paused (docs/09 §2).
 *
 * Hence "unverified" and "paused" alongside the registry's two. None of them
 * uses role="alert": these are part of the page's content, not interruptions,
 * and announcing every unconfirmed seat would make a ward page unusable with a
 * screen reader.
 */
const alertVariants = cva('relative w-full rounded-control p-3 text-base', {
  variants: {
    variant: {
      default: 'border border-border bg-card text-card-foreground',
      destructive: 'border border-destructive/50 bg-card text-destructive',
      unverified: 'state-unverified text-foreground',
      paused: 'border border-border bg-accent text-accent-foreground',
    },
  },
  defaultVariants: { variant: 'default' },
});

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
  return <div data-slot="alert" className={cn(alertVariants({ variant }), className)} {...props} />;
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="alert-title" className={cn('font-semibold', className)} {...props} />;
}

function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="alert-description"
      className={cn('mt-1 text-sm text-muted-foreground', className)}
      {...props}
    />
  );
}

export { Alert, AlertTitle, AlertDescription, alertVariants };
