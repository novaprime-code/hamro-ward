'use client';

import Link from 'next/link';
import { useMemo, useState } from 'react';

import { pick } from '@/lib/api';
import type { LocalLevelSummary } from '@/lib/api';
import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';

/**
 * Choosing a municipality (FR-GEO-01, FR-GEO-07).
 *
 * The whole list arrives with the page and filtering happens in the browser.
 * With 753 local levels at most, that is a small payload once, rather than a
 * request per keystroke over a connection that may not have one to spare (§17).
 *
 * Matching is accent-blind across both scripts and the slug, so a citizen who
 * knows the name in Devanagari, in romanised form, or only from the URL all
 * reach the same place.
 *
 * Every label arrives as a finished string. This is a client component, so
 * anything it receives is serialised across the boundary — a function would
 * fail the render, not degrade.
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
      <label className="block">
        <span className="sr-only">{labels.search}</span>
        <input
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={labels.search}
          className="w-full rounded-[var(--radius-control)] border border-line bg-surface px-3 py-3 text-base"
        />
      </label>

      {matches.length === 0 ? (
        <p className="mt-4 text-muted">{labels.empty}</p>
      ) : (
        <ul className="mt-4">
          {matches.map((level) => (
            <li key={level.slug_path}>
              <Link
                href={`/${locale}/palika/${level.slug_path}`}
                className="block border-b border-line py-3 hover:bg-surface-2 focus-visible:bg-surface-2"
              >
                <span className="float-right text-muted" aria-hidden="true">
                  ›
                </span>
                <span className="block font-display text-[19px] font-semibold">
                  {pick(level.name, locale)}
                </span>
                <span className="block text-sm text-muted">
                  {pick(level.district, locale)} · {pick(level.province, locale)} ·{' '}
                  {formatNumber(level.wards, locale)} {labels.wards}
                </span>
                <span className="block text-sm text-muted">
                  {level.type ? (labels.typeLabels[level.type] ?? '') : ''}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}