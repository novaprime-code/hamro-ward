'use client';

import { Star, Trash2 } from 'lucide-react';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, Field, FormError } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatNumber } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import { pick } from '@/lib/api';
import type { LocalLevelSummary } from '@/lib/api';
import { interpret, waitMessage } from '@/lib/account-auth';
import {
  addWard,
  canBePrimary,
  listWards,
  makeWardPrimary,
  readList,
  removeWard,
  RELATIONSHIPS,
  wardNumbers,
  type Relationship,
  type SavedWard,
} from '@/lib/account-wards';

/**
 * My wards (HW-E30-F02-T02; docs/09 §5.6, 12 §12).
 *
 * The one place this platform records where somebody is, and it records a
 * ward — never a street, never a house. The screen says so, because a form
 * asking where you live owes the person an account of what it is keeping.
 *
 * Adding is two choices and a reason: which municipality, which ward, and
 * what the ward is to you. The relationship is the part that matters most and
 * is the part a citizen is least likely to expect, so it is explained rather
 * than offered as four bare words.
 */
export function MyWards({
  copy,
  locale,
  localLevels,
  typeLabels,
}: {
  copy: Copy;
  locale: Locale;
  localLevels: LocalLevelSummary[];
  typeLabels: Record<string, string>;
}) {
  const [wards, setWards] = useState<SavedWard[] | null>(null);
  const [maxSaved, setMaxSaved] = useState(5);
  const [signedOut, setSignedOut] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [slugPath, setSlugPath] = useState('');
  const [wardNumber, setWardNumber] = useState('');
  const [relationship, setRelationship] = useState<Relationship>('permanent_address');

  const chosen = localLevels.find((level) => level.slug_path === slugPath) ?? null;

  const apply = useCallback((status: number, body: unknown, retryAfter: string | null) => {
    if (status === 401) {
      setSignedOut(true);

      return;
    }

    const outcome = interpret(status, body, retryAfter, copy.offline);

    if (outcome.kind === 'ok') {
      const list = readList(outcome.body);
      setWards(list.wards);
      setMaxSaved(list.maxSaved);
      setError(null);
    } else if (outcome.kind === 'throttled') {
      setError(waitMessage(outcome.retryAfterSeconds, copy.wait));
    } else {
      setError(outcome.message);
    }
  }, [copy.offline, copy.wait]);

  const run = useCallback(
    async (call: () => Promise<{ status: number; body: unknown; retryAfter: string | null }>) => {
      setBusy(true);

      try {
        const response = await call();
        apply(response.status, response.body, response.retryAfter);
      } catch {
        setError(copy.offline);
      } finally {
        setBusy(false);
      }
    },
    [apply, copy.offline],
  );

  useEffect(() => {
    void run(listWards);
  }, [run]);

  function add(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (chosen === null || wardNumber === '') {
      return;
    }

    void run(async () => {
      const response = await addWard({
        slugPath: chosen.slug_path,
        wardNumber: Number(wardNumber),
        relationship,
        // The first ward that can be primary becomes primary on its own in
        // the API, so this never has to be asked for on an empty list.
        makePrimary: false,
      });

      if (response.status === 201) {
        setWardNumber('');
      }

      return response;
    });
  }

  if (signedOut) {
    return (
      <AccountPanel title={copy['wards.title']}>
        <Alert>
          <AlertDescription>{copy['wards.signedOut']}</AlertDescription>
        </Alert>
        <p className="mt-4">
          <Link href={`/${locale}/account/sign-in`} className="underline underline-offset-4">
            {copy['signIn.title']}
          </Link>
        </p>
      </AccountPanel>
    );
  }

  const full = wards !== null && wards.length >= maxSaved;

  return (
    <div className="mx-auto w-full max-w-md space-y-4">
      <AccountPanel title={copy['wards.title']} description={copy['wards.intro']}>
        <FormError message={error} />

        {wards === null ? (
          <p className="text-muted-foreground">{copy.working}</p>
        ) : wards.length === 0 ? (
          <p className="text-muted-foreground">{copy['wards.empty']}</p>
        ) : (
          <ul className="space-y-3">
            {wards.map((ward) => (
              <li key={ward.id}>
                <SavedWardRow
                  ward={ward}
                  copy={copy}
                  locale={locale}
                  typeLabels={typeLabels}
                  busy={busy}
                  onPrimary={() => void run(() => makeWardPrimary(ward.id))}
                  onRemove={() => void run(() => removeWard(ward.id))}
                />
              </li>
            ))}
          </ul>
        )}
      </AccountPanel>

      <AccountPanel title={copy['wards.addTitle']}>
        {full ? (
          <Alert>
            <AlertDescription>
              {copy['wards.full'].replace('{max}', formatNumber(maxSaved, locale))}
            </AlertDescription>
          </Alert>
        ) : (
          <form onSubmit={add} className="space-y-4">
            <Field label={copy['wards.municipality']} htmlFor="slug_path">
              <select
                id="slug_path"
                value={slugPath}
                onChange={(event) => {
                  setSlugPath(event.target.value);
                  setWardNumber('');
                }}
                className="flex h-12 w-full rounded-control border border-input bg-card px-3 py-2 text-base text-foreground"
                required
              >
                <option value="">{copy['wards.choose']}</option>
                {localLevels.map((level) => (
                  <option key={level.slug_path} value={level.slug_path}>
                    {pick(level.name, locale)}
                    {level.type === null ? '' : ` · ${typeLabels[level.type] ?? ''}`}
                  </option>
                ))}
              </select>
            </Field>

            <Field label={copy['wards.wardNumber']} htmlFor="ward_number">
              <select
                id="ward_number"
                value={wardNumber}
                onChange={(event) => setWardNumber(event.target.value)}
                disabled={chosen === null}
                className="flex h-12 w-full rounded-control border border-input bg-card px-3 py-2 text-base text-foreground disabled:opacity-50"
                required
              >
                <option value="">{copy['wards.choose']}</option>
                {wardNumbers(chosen?.wards ?? 0).map((number) => (
                  <option key={number} value={number}>
                    {formatNumber(number, locale)}
                  </option>
                ))}
              </select>
            </Field>

            <fieldset className="space-y-1.5">
              <legend className="block text-sm font-medium">{copy['wards.relationship']}</legend>
              <div className="space-y-2">
                {RELATIONSHIPS.map((option) => (
                  <label key={option} className="flex items-start gap-3 text-base">
                    <input
                      type="radio"
                      name="relationship"
                      value={option}
                      checked={relationship === option}
                      onChange={() => setRelationship(option)}
                      className="mt-1 size-5"
                    />
                    <span>
                      {copy[`wards.relationship.${option}`]}
                      {canBePrimary(option) ? null : (
                        <span className="block text-sm text-muted-foreground">
                          {copy['wards.notPrimary']}
                        </span>
                      )}
                    </span>
                  </label>
                ))}
              </div>
            </fieldset>

            <Button type="submit" className="w-full" disabled={busy || chosen === null || wardNumber === ''}>
              {busy ? copy.working : copy['wards.add']}
            </Button>
          </form>
        )}

        <p className="mt-4 text-sm text-muted-foreground">{copy['wards.privacy']}</p>
      </AccountPanel>
    </div>
  );
}

function SavedWardRow({
  ward,
  copy,
  locale,
  typeLabels,
  busy,
  onPrimary,
  onRemove,
}: {
  ward: SavedWard;
  copy: Copy;
  locale: Locale;
  typeLabels: Record<string, string>;
  busy: boolean;
  onPrimary: () => void;
  onRemove: () => void;
}) {
  const place = pick(ward.local_level.name, locale);
  const heading =
    ward.ward_number === null
      ? place
      : `${copy['wards.ward']} ${formatNumber(ward.ward_number, locale)} · ${place}`;

  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="font-display text-[17px] font-semibold">{heading}</p>
            {ward.local_level.type === null ? null : (
              <p className="text-sm text-muted-foreground">{typeLabels[ward.local_level.type] ?? ''}</p>
            )}
          </div>
          <div className="flex shrink-0 flex-wrap justify-end gap-1">
            {ward.is_primary ? <Badge>{copy['wards.primary']}</Badge> : null}
            {/* A ward can be saved before its municipality opens, and a row
                that silently does nothing would be worse than one that says
                why (12 §12.2). */}
            {ward.open ? null : <Badge variant="neutral">{copy['wards.notOpen']}</Badge>}
          </div>
        </div>

        <p className="text-sm">{copy[`wards.relationship.${ward.relationship}`]}</p>

        <div className="flex flex-wrap gap-2 pt-1">
          {ward.is_primary || !ward.can_be_primary ? null : (
            <Button type="button" variant="outline" size="sm" onClick={onPrimary} disabled={busy}>
              <Star aria-hidden="true" />
              {copy['wards.makePrimary']}
            </Button>
          )}
          <Button type="button" variant="outline" size="sm" onClick={onRemove} disabled={busy}>
            <Trash2 aria-hidden="true" />
            {copy['wards.remove']}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
