import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

/**
 * The registry variants, plus the four this product actually needs.
 *
 * The civic ones are here rather than as ad-hoc classes at the call site so
 * that "what verified looks like" has exactly one definition. They are also
 * the reason colour is never the only signal: each is paired with an icon and
 * a word by ProvenanceBadge (project instructions §3, NFR-ACC-01).
 */
const badgeVariants = cva(
  'inline-flex w-fit shrink-0 items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-sm font-medium [&>svg]:size-3.5 [&>svg]:pointer-events-none',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-primary text-primary-foreground',
        secondary: 'border-transparent bg-secondary text-secondary-foreground',
        destructive: 'border-transparent bg-destructive text-destructive-foreground',
        outline: 'border-border bg-card text-foreground',

        /* A verified official source. */
        verified: 'border-verified/35 bg-card text-verified',
        /* Recorded, not confirmed. Dashed on purpose: an unfinished edge reads
           as unfinished at a glance, before the words are read. */
        unverified: 'border-dashed border-pending/60 bg-card text-pending',
        /* Known and uncontested, but carrying no particular authority. */
        neutral: 'border-border bg-card text-muted-foreground',
        /* Machine-written text, always marked as such. */
        ai: 'border-primary/50 bg-card text-accent-foreground',
      },
    },
    defaultVariants: { variant: 'default' },
  },
);

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'span';

  return <Comp data-slot="badge" className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
