import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';

import { cn } from '@/lib/utils';

/**
 * A card, optionally rendered as something else.
 *
 * `asChild` is what lets a whole card be one link — a search hit, a picker row,
 * a ward tile — rather than a div containing a small anchor. The difference is
 * the size of the tap target: on a phone, a card with a link inside it means
 * aiming at a line of text, and a card that IS the link means hitting anywhere
 * in it (--tap-target, NFR-ACC-03).
 *
 * Same pattern as Button and Badge, which have had it since the registry
 * components arrived. This is the one that was described in the docs without
 * being implemented, so a card meant to be a link was silently a card with a
 * link in it.
 */
function Card({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<'div'> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'div';

  return (
    <Comp
      data-slot="card"
      className={cn('rounded-card border border-border bg-card text-card-foreground', className)}
      {...props}
    />
  );
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-header" className={cn('flex flex-col gap-1 p-4', className)} {...props} />;
}

function CardTitle({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card-title"
      className={cn('font-display text-[17px] font-semibold leading-snug', className)}
      {...props}
    />
  );
}

function CardDescription({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div data-slot="card-description" className={cn('text-sm text-muted-foreground', className)} {...props} />
  );
}

function CardContent({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-content" className={cn('p-4 pt-0', className)} {...props} />;
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-footer" className={cn('flex items-center p-4 pt-0', className)} {...props} />;
}

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
