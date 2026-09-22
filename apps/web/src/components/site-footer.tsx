import Link from 'next/link';

export function SiteFooter({ links }: { links: Array<{ href: string; label: string }> }) {
  return (
    <footer className="mt-12 border-t border-rule">
      <nav className="mx-auto flex w-full max-w-[720px] flex-wrap gap-4 px-4 py-6 text-[15px] text-slate">
        {links.map((link) => (
          <Link key={link.href} href={link.href} className="min-h-11 content-center underline">
            {link.label}
          </Link>
        ))}
      </nav>
    </footer>
  );
}
