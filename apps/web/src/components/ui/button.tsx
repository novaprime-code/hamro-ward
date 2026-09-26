import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

/**
 * Two deliberate differences from the shadcn registry default:
 *
 *  1. No focus ring classes. globals.css gives every focusable element one
 *     outline, which cannot be clipped by an ancestor's overflow the way a
 *     ring can (NFR-ACC-01).
 *
 *  2. Every size clears 44px. The registry's default is h-9 (36px), below the
 *     minimum touch target this platform commits to. "sm" therefore differs by
 *     padding and type size, not by height — on a phone in a ward office,
 *     a smaller button is a missed button.
 */
const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-control text-base font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-border bg-card text-foreground hover:bg-accent hover:text-accent-foreground',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-accent hover:text-accent-foreground',
        ghost: 'text-foreground hover:bg-accent hover:text-accent-foreground',
        link: 'text-accent-foreground underline underline-offset-4',
      },
      size: {
        default: 'h-11 px-5 py-2',
        sm: 'h-11 px-3 text-sm',
        lg: 'h-12 px-7',
        icon: 'size-11',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
);

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<'button'> &
  VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'button';

  return <Comp data-slot="button" className={cn(buttonVariants({ variant, size, className }))} {...props} />;
}

export { Button, buttonVariants };
