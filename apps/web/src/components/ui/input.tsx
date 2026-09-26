import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * h-12 rather than the registry's h-9: a search field is the first thing a
 * citizen touches on the picker, and 48px is comfortable on a phone held in
 * one hand. text-base also keeps iOS from zooming the viewport on focus, which
 * it does below 16px.
 */
function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        'flex h-12 w-full rounded-control border border-input bg-card px-3 py-2 text-base text-foreground',
        'placeholder:text-muted-foreground',
        'disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    />
  );
}

export { Input };
