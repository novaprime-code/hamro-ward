'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, FormError, PasswordField } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { Locale } from '@/i18n/config';
import {
  ensureCsrfCookie,
  fortifyRequest,
  interpret,
  resetCredentials,
  waitMessage,
} from '@/lib/account-auth';

/**
 * Set a new password from a mailed link (HW-E30-F01-T03; docs/09 §5.6).
 *
 * The token and the address arrive in the query string, which means they
 * arrive from whoever sent the link: nothing here checks them, they go
 * straight back to the API, which is the only side that can tell a real pair
 * from a guessed one. All this screen decides is whether there is a pair to
 * send at all — a bare visit to this URL is a wrong turn, not an error.
 *
 * Fortify signs the person in as it saves the password, which is why the
 * confirmation says so.
 */
export function ResetPasswordForm({ copy, locale }: { copy: Copy; locale: Locale }) {
  const params = useSearchParams();
  const { token, email } = resetCredentials(new URLSearchParams(params.toString()));

  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    setBusy(true);
    setError(null);

    void (async () => {
      try {
        await ensureCsrfCookie();

        const response = await fortifyRequest('POST', '/reset-password', {
          token,
          email,
          password: String(form.get('password') ?? ''),
          password_confirmation: String(form.get('password_confirmation') ?? ''),
        });
        const outcome = interpret(response.status, response.body, response.retryAfter, copy['reset.badToken']);

        if (outcome.kind === 'ok') {
          setDone(true);
        } else if (outcome.kind === 'throttled') {
          setError(waitMessage(outcome.retryAfterSeconds, copy.wait));
        } else if (outcome.kind === 'invalid' && outcome.errors.email !== undefined) {
          // Fortify reports a dead token against the email field, which
          // would read as "your address is wrong" — it is the link that is.
          setError(copy['reset.badToken']);
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

  if (done) {
    return (
      <AccountPanel title={copy['reset.title']}>
        <Alert>
          <AlertDescription>{copy['reset.done']}</AlertDescription>
        </Alert>
        <p className="mt-4">
          <Link href={`/${locale}`} className="underline underline-offset-4">
            {copy['forgot.backToSignIn']}
          </Link>
        </p>
      </AccountPanel>
    );
  }

  if (token === '' || email === '') {
    return (
      <AccountPanel title={copy['reset.title']}>
        <Alert>
          <AlertDescription>{copy['reset.noToken']}</AlertDescription>
        </Alert>
        <p className="mt-4">
          <Link href={`/${locale}/account/forgot-password`} className="underline underline-offset-4">
            {copy['forgot.submit']}
          </Link>
        </p>
      </AccountPanel>
    );
  }

  return (
    <AccountPanel title={copy['reset.title']} description={copy['reset.intro'].replace('{email}', email)}>
      <FormError message={error} />

      <form onSubmit={submit} className="space-y-4">
        <PasswordField
          label={copy.password}
          name="password"
          copy={copy}
          help={copy.passwordHelp}
          autoComplete="new-password"
        />
        <PasswordField
          label={copy.passwordConfirm}
          name="password_confirmation"
          copy={copy}
          autoComplete="new-password"
        />
        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? copy.working : copy['reset.submit']}
        </Button>
      </form>
    </AccountPanel>
  );
}
