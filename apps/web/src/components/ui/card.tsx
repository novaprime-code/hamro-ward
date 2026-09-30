import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';

import { cn } from '@/lib/utils';

/**
 * Two departures from the registry, both deliberate.
 *
 * `asChild` — a whole card is often a single link. Wrapping a Card in a Link
 * nests two boxes and gives the focus ring the wrong shape; putting a Link
 * inside a Card makes only the text clickable. Slot makes the card itself the
 * anchor: one element, the full area is the target, and the focus outline
 * follows the card's own border radius. `block` is in the base class for this
 * reason: an `<a>` is inline by default, so its background and border collapse
 * to a sliver and the card silently disappears. It is a no-op on a div.
 *
 * Padding — the registry's spacing (py-6, px-6) assumes a desktop dashboard.
 * These are mostly lists on a phone, so the scale is p-4 with CardHeader and
 * CardFooter trimming the edge they sit against. Change it here, not at call
 * sites, or the lists drift apart.
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
      className={cn('block rounded-card border border-border bg-card text-card-foreground', className)}
      {...props}
    />
  );
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-header" className={cn('flex flex-col gap-1 p-4 pb-0', className)} {...props} />;
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
  return <div data-slot="card-content" className={cn('p-4', className)} {...props} />;
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-footer" className={cn('flex items-center p-4 pt-0', className)} {...props} />;
}

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
