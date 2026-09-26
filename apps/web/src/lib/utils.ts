import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge class names, last one wins on conflicts.
 *
 * clsx flattens conditionals; tailwind-merge then resolves Tailwind conflicts,
 * so a caller passing `className="px-6"` actually overrides a component's own
 * `px-4` instead of both landing in the attribute and the order deciding.
 *
 * Every component in components/ui expects this to exist at this path — it is
 * the one import shadcn generates into each file.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
