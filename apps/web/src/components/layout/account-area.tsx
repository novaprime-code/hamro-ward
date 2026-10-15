'use client';

import { UserRound } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';

import { buttonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import type { Locale } from '@/i18n/config';
import { fetchMe, fortifyRequest, type Me } from '@/lib/account-auth';

/**
 * The account corner of the header (HW-E30-F01-T04; docs/12 §11.4, 09 §5.6).
 *
 * Every public page is cached and served identically to everyone, which is
 * what keeps the site fast on a slow connection — and it means the header
 * cannot know who is reading while it renders. So this renders a placeholder
 * on the server and asks `/api/v1/me` once the page is interactive. The
 * placeholder is the same width as what replaces it, so the header does not
 * jump when the answer arrives.
 *
 * A menu rather than a row of links: signed in, there are three destinations
 * and a header on a phone has room for none of them.
 */
export function AccountArea({
  locale,
  labels,
}: {
  locale: Locale;
  labels: {
    signIn: string;
    account: string;
    myWards: string;
    signOut: string;
    verifyPending: string;
  };
}) {
  // undefined is "we have not asked yet" — distinct from null, "nobody is
  // signed in". Only the second should show a Sign in link.
  const [me, setMe] = useState<Me | null | undefined>(undefined);
  const [open, setOpen] = useState(false);
  const area = useRef<HTMLDivElement>(null);

  useEffect(() => {
    void (async () => {
      try {
        setMe(await fetchMe());
      } catch {
        // An unreachable API is not a signed-out visitor, but there is
        // nothing useful to show either; the rest of the page still works.
        setMe(null);
      }
    })();
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }

    function onPointerDown(event: PointerEvent) {
      if (!area.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    }

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  async function signOut() {
    await fortifyRequest('POST', '/logout');
    setOpen(false);
    setMe(null);
    // A full reload, not a router refresh: signing out changes nothing that
    // is rendered on the server, and the pages this visitor has cached in
    // the client router were never user-specific to begin with.
    window.location.assign(`/${locale}`);
  }

  if (me === undefined) {
    // Fixed size, so the header keeps its shape until the answer lands.
    return <div className="h-8 w-20" aria-hidden="true" />;
  }

  if (me === null) {
    return (
      <Link
        href={`/${locale}/account/sign-in`}
        className={buttonVariants({ variant: 'ghost', size: 'sm' })}
      >
        {labels.signIn}
      </Link>
    );
  }

  return (
    <div ref={area} className="relative">
      <Button
        type="button"
        variant="ghost"
        size="sm"
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => setOpen((was) => !was)}
      >
        {/* The name is worth the width only when there is width to spare. On
            a phone the header already carries a logo, search and the language
            switch, and a fourth label pushes the site name onto two lines. */}
        <UserRound aria-hidden="true" className="sm:hidden" />
        <span className="hidden max-w-24 truncate sm:inline">{me.display_name}</span>
        <span className="sr-only sm:hidden">{labels.account}</span>
        {me.email_verified ? null : (
          // A dot, not a word: the header is not where this is explained, and
          // the label carries the meaning for anyone not seeing colour.
          <span
            className="size-2 rounded-full bg-destructive"
            role="img"
            aria-label={labels.verifyPending}
          />
        )}
      </Button>

      {open ? (
        <div
          role="menu"
          className="absolute end-0 z-10 mt-1 w-48 rounded-card border border-border bg-card p-1 shadow-lg"
        >
          <Link
            role="menuitem"
            href={`/${locale}/account/wards`}
            onClick={() => setOpen(false)}
            className="block rounded-control px-3 py-2 text-base hover:bg-muted"
          >
            {labels.myWards}
          </Link>
          {me.email_verified ? null : (
            <Link
              role="menuitem"
              href={`/${locale}/account/verify`}
              onClick={() => setOpen(false)}
              className="block rounded-control px-3 py-2 text-base hover:bg-muted"
            >
              {labels.verifyPending}
            </Link>
          )}
          <button
            role="menuitem"
            type="button"
            onClick={() => void signOut()}
            className="block w-full rounded-control px-3 py-2 text-start text-base hover:bg-muted"
          >
            {labels.signOut}
          </button>
        </div>
      ) : null}
    </div>
  );
}
