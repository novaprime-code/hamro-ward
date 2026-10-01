import Link from 'next/link';

import { Separator } from '@/components/ui/separator';

/**
 * The footer, and the one place on every page that says "tell us we got it
 * wrong" (Milestone A, A7).
 *
 * The corrections link is a mailto when an address is configured and a link to
 * the Sources page when it is not — which is honest in both directions. A
 * "report an error" link that goes nowhere spends a reader's goodwill and
 * returns nothing, and the Sources page at least explains what a correction
 * needs to be useful.
 */
export function SiteFooter({
  links,
  corrections,
}: {
  links: Array<{ href: string; label: string }>;
  corrections: { href: string; label: string; external: boolean };
}) {
  const className =
    'inline-flex min-h-[var(--tap-target)] items-center underline underline-offset-4';

  return (
    <footer className="mt-12 bg-card">
      <Separator />
      <nav className="mx-auto flex w-full max-w-[var(--content-width)] flex-wrap gap-x-5 px-4 py-4 text-sm text-muted-foreground">
        {links.map((link) => (
          <Link key={link.href} href={link.href} className={className}>
            {link.label}
          </Link>
        ))}

        {corrections.external ? (
          /* A mailto is not a route, so it is a plain anchor. Next's Link would
             try to prefetch it. */
          <a href={corrections.href} className={className}>
            {corrections.label}
          </a>
        ) : (
          <Link href={corrections.href} className={className}>
            {corrections.label}
          </Link>
        )}
      </nav>
    </footer>
  );
}
