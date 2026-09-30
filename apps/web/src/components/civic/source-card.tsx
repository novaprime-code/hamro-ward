import { ProvenanceBadge } from '@/components/civic/provenance-badge';
import type { ProvenanceType } from '@/components/civic/provenance-badge';
import { Card } from '@/components/ui/card';
import type { Locale } from '@/i18n/config';
import type { EvidenceSource } from '@/lib/api';
import { pick } from '@/lib/api';

/**
 * One source, laid out so a sceptical reader can check it (FR-SRC-03).
 *
 * The order of the fields is the argument: what kind of source it is first,
 * because that is how a reader should weigh everything below it; then the title
 * and publisher; then the dates; then, last, the words themselves.
 *
 * Two dates, not one, and both matter. `published_at` is when the document
 * said what it says. `retrieved_at` is when we looked — the difference is the
 * window in which a page could have changed under us, and a platform that
 * shows only the first is quietly claiming a freshness it has not checked
 * (§13).
 *
 * The link out is a plain external anchor. Somebody checking our working
 * should land on the real document, on the real publisher's site, not on our
 * rendering of it.
 */
export function SourceCard({
  source,
  locale,
  t,
}: {
  source: EvidenceSource;
  locale: Locale;
  t: (key: string) => string;
}) {
  const provenance = source.provenance_type as ProvenanceType;

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-center gap-2">
        <ProvenanceBadge
          type={provenance}
          label={pick(source.source_type.label, locale)}
          className="mt-0"
        />
        {source.verification_status === 'verified' ? null : (
          /* Said in words on the card itself, not only implied by the badge's
             colour. A reader who scrolled past the badge must not be able to
             read an unverified source as a confirmed one (NFR-ACC-01). */
          <span className="text-sm text-muted-foreground">{t('evidence.notVerified')}</span>
        )}
      </div>

      <h3 className="mt-2 font-display text-[17px] font-semibold">{source.title}</h3>

      {source.publisher === null ? null : (
        <p className="mt-0.5 text-sm text-muted-foreground">{source.publisher}</p>
      )}

      <dl className="mt-3 space-y-1 text-sm">
        {source.asserted_value === null ? null : (
          <Field label={t('evidence.saysThat')} value={source.asserted_value} emphasis />
        )}
        {source.locator === null ? null : <Field label={t('evidence.locator')} value={source.locator} />}
        {source.published_at === null ? null : (
          <Field label={t('evidence.published')} value={source.published_at} />
        )}
        {source.retrieved_at === null ? null : (
          <Field label={t('evidence.retrieved')} value={source.retrieved_at.slice(0, 10)} />
        )}
      </dl>

      {source.excerpt === null ? null : (
        <blockquote className="mt-3 border-l-2 border-border pl-3 text-[15px] italic">
          {source.excerpt}
        </blockquote>
      )}

      {source.url === null ? null : (
        <p className="mt-3">
          <a
            className="inline-flex min-h-9 items-center text-sm underline underline-offset-4"
            href={source.url}
            target="_blank"
            /* noreferrer as well as noopener: the destination is often a
               government site, and it has no business learning which ward page
               a citizen was reading. */
            rel="noopener noreferrer"
          >
            {t('evidence.openSource')}
          </a>
        </p>
      )}
    </Card>
  );
}

function Field({ label, value, emphasis }: { label: string; value: string; emphasis?: boolean }) {
  return (
    <div className="flex flex-wrap gap-x-2">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={emphasis ? 'font-semibold' : undefined}>{value}</dd>
    </div>
  );
}
