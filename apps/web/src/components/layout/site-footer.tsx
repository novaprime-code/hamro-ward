import Link from 'next/link';

import { Separator } from '@/components/ui/separator';

export function SiteFooter({ links }: { links: Array<{ href: string; label: string }> }) {
  return (
    <footer className="mt-12 bg-card">
      <Separator />
      <nav className="mx-auto flex w-full max-w-[var(--content-width)] flex-wrap gap-x-5 px-4 py-4 text-sm text-muted-foreground">
        {links.map((link) => (
          <Link
            key={link.href}
            href={link.href}
            className="inline-flex min-h-[var(--tap-target)] items-center underline underline-offset-4"
          >
            {link.label}
          </Link>
        ))}
      </nav>
    </footer>
  );
}
