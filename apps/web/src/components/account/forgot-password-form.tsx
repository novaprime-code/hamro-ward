'use client';

import Link from 'next/link';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, Field, FormError } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Locale } from '@/i18n/config';
import { ensureCsrfCookie, fortifyRequest, interpret, waitMessage } from '@/lib/account-auth';

/**
 * Ask for a reset link (HW-E30-F01-T03; docs/09 §5.6).
 *
 * The confirmation is conditional — "if that address has an account" — and
 * that wording is load-bearing, not hedging: the API answers an unknown
 * address exactly as it answers a known one, and a screen that said "sent!"
 * outright would turn this form back into the account-checker the API
 * refuses to be.
 */
export function ForgotPasswordForm({ copy, locale }: { copy: Copy; locale: Locale }) {
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    setBusy(true);
    setError(null);

    void (async () => {
      try {
        await ensureCsrfCookie();

        const response = await fortifyRequest('POST', '/forgot-password', {
          email: String(form.get('email') ?? ''),
        });
        const outcome = interpret(response.status, response.body, response.retryAfter, copy.offline);

        if (outcome.kind === 'ok') {
          setSent(true);
        } else if (outcome.kind === 'throttled') {
          setError(waitMessage(outcome.retryAfterSeconds, copy.wait));
        } else {
          setError(outcome.message);
        }
      } catch {
        setError(copy.offline);
      } finally {
        setBusy(false);
      }
    })();
  }

  return (
    <AccountPanel title={copy['forgot.title']} description={sent ? undefined : copy['forgot.intro']}>
      <FormError message={error} />

      {sent ? (
        <Alert>
          <AlertDescription>{copy['forgot.sent']}</AlertDescription>
        </Alert>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          <Field label={copy.email} htmlFor="email">
            <Input id="email" name="email" type="email" autoComplete="username" required autoFocus />
          </Field>
          <Button type="submit" className="w-full" disabled={busy}>
            {busy ? copy.working : copy['forgot.submit']}
          </Button>
        </form>
      )}

      <p className="mt-4 text-sm">
        <Link href={`/${locale}/account/sign-in`} className="underline underline-offset-4">
          {copy['forgot.backToSignIn']}
        </Link>
      </p>
    </AccountPanel>
  );
}
