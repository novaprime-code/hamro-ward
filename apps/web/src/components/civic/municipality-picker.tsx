'use client';

import { ChevronRight, Search } from 'lucide-react';
import Link from 'next/link';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import { pick } from '@/lib/api';
import type { LocalLevelSummary } from '@/lib/api';

/**
 * Choosing a municipality (FR-GEO-01, FR-GEO-07).
 *
 * The whole list arrives with the page and filtering happens in the browser.
 * With 753 local levels at most, that is a small payload once, rather than a
 * request per keystroke over a connection that may not have one to spare (§17).
 *
 * Matching runs across both scripts and the slug, so a citizen who knows the
 * name in Devanagari, in romanised form, or only from the URL all reach the
 * same place.
 *
 * Every label arrives as a finished string. This is the app's only client
 * component, so anything it receives is serialised across the boundary — a
 * function would fail the render, not degrade.
 */
export function MunicipalityPicker({
  localLevels,
  locale,
  labels,
}: {
  localLevels: LocalLevelSummary[];
  locale: Locale;
  labels: {
    search: string;
    wards: string;
    empty: string;
    typeLabels: Record<string, string>;
  };
}) {
  const [query, setQuery] = useState('');

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
      return localLevels;
    }

    return localLevels.filter((level) =>
      [level.name.ne, level.name.en, level.district.ne, level.district.en, level.slug_path]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle)),
    );
  }, [localLevels, query]);

  return (
    <div>
      <label className="relative block">
        <span className="sr-only">{labels.search}</span>
        <Search
          className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={labels.search}
          className="pl-10"
        />
      </label>

      {matches.length === 0 ? (
        <p className="mt-6 text-muted-foreground">{labels.empty}</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {matches.map((level) => {
            const name = pick(level.name, locale);
            const typeLabel = level.type === null ? '' : (labels.typeLabels[level.type] ?? '');

            /*
             * A Nepali local level's name already ends in its type —
             * "कोशारा उपमहानगरपालिका" is the name AND the type, and the English
             * form behaves the same way. Printing the type underneath was
             * saying the same word twice and adding a third muted line to
             * every row. It is shown only when the name does not already
             * carry it, which keeps the label useful for any imported name
             * that turns out not to follow the convention.
             *
             * Case-folded because the English labels are sentence case
             * ("Rural municipality") and the names are title case ("Sainli
             * Rural Municipality"); a literal comparison matches in Nepali,
             * where case does not exist, and silently never matches in English.
             */
            const showType =
              typeLabel !== '' && !name.toLowerCase().includes(typeLabel.toLowerCase());

            return (
              <li key={level.slug_path}>
                <Card asChild className="transition-colors hover:bg-accent focus-visible:bg-accent">
                  <Link href={`/${locale}/palika/${level.slug_path}`}>
                    <CardContent className="flex items-center gap-3">
                      <span className="min-w-0 flex-1">
                        <span className="block font-display text-[19px] font-semibold leading-snug">
                          {name}
                        </span>
                        <span className="mt-1 block text-sm text-muted-foreground">
                          {pick(level.district, locale)} · {pick(level.province, locale)} ·{' '}
                          {formatNumber(level.wards, locale)} {labels.wards}
                        </span>
                        {showType ? (
                          <Badge variant="neutral" className="mt-2">
                            {typeLabel}
                          </Badge>
                        ) : null}
                      </span>
                      <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    </CardContent>
                  </Link>
                </Card>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
