import Link from 'next/link';

export function SiteFooter({ links }: { links: Array<{ href: string; label: string }> }) {
  return (
    <footer className="mt-12 border-t border-line bg-surface">
      <nav className="mx-auto flex w-full max-w-[var(--content-width)] flex-wrap gap-4 px-4 py-6 text-sm text-muted">
        {links.map((link) => (
          <Link
            key={link.href}
            href={link.href}
            className="inline-flex min-h-[var(--tap-target)] items-center underline"
          >
            {link.label}
          </Link>
        ))}
      </nav>
    </footer>
  );
}
