import type { ReactNode } from 'react';

/**
 * A headed block of prose on the trust pages (About, Sources, Privacy).
 *
 * The id is explicit rather than derived from the heading: these headings are
 * Nepali by default, and generating an anchor from Devanagari text produces an
 * id that is legal HTML but impossible to share, link to or use in a bug
 * report — and one that changes the day someone rewords the heading.
 *
 * Text is capped at --measure. These are the only pages on the site with real
 * paragraphs, and a full-width line of Devanagari is hard to track back to the
 * start of the next one (docs/09 §3.2).
 */
export function ProseSection({
  id,
  heading,
  children,
}: {
  id: string;
  heading: string;
  children: ReactNode;
}) {
  return (
    <section className="space-y-2" aria-labelledby={`${id}-heading`}>
      <h2 id={`${id}-heading`} className="font-display text-[21px] font-semibold">
        {heading}
      </h2>
      <div className="max-w-[var(--measure)] space-y-2">{children}</div>
    </section>
  );
}
