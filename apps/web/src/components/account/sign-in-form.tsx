'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, Field, FormError, PasswordField } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Locale } from '@/i18n/config';
import {
  ensureCsrfCookie,
  fetchMe,
  fortifyRequest,
  interpret,
  waitMessage,
  type Me,
} from '@/lib/account-auth';

/**
 * Sign in (HW-E30-F01-T03; docs/09 §5.6).
 *
 * There is no two-step challenge here, unlike the staff screen: a citizen
 * account may turn TOTP on later, and when it does Fortify answers
 * `two_factor: true` — until that screen exists, this says plainly that it
 * cannot finish rather than looping on a form that will not work.
 *
 * Signing in lands back here rather than on an account home, because there is
 * not one yet: My wards and My reports are HW-E30-F02-T02 and F03-T02.
 */
export function SignInForm({ copy, locale }: { copy: Copy; locale: Locale }) {
  const [me, setMe] = useState<Me | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    setMe(await fetchMe());
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);

    try {
      await action();
    } catch {
      setError(copy.offline);
    } finally {
      setBusy(false);
    }
  }

  function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setNotice(null);

    void run(async () => {
      await ensureCsrfCookie();

      const response = await fortifyRequest('POST', '/login', {
        email: String(form.get('email') ?? ''),
        password: String(form.get('password') ?? ''),
      });
      const outcome = interpret(response.status, response.body, response.retryAfter, copy['signIn.failed']);

      if (outcome.kind === 'ok') {
        if ((outcome.body as { two_factor?: boolean })?.two_factor === true) {
          // Nothing on this site turns citizen TOTP on yet, so this is a
          // dead end rather than a missing screen. Say so instead of
          // pretending the sign-in worked.
          setError(copy['signIn.failed']);

          return;
        }

        await refresh();
      } else if (outcome.kind === 'throttled') {
        setError(waitMessage(outcome.retryAfterSeconds, copy.wait));
      } else {
        setError(outcome.message);
      }
    });
  }

  function signOut() {
    void run(async () => {
      await fortifyRequest('POST', '/logout');
      setMe(null);
      setNotice(copy['signIn.signedOut']);
    });
  }

  if (me !== undefined && me !== null) {
    return (
      <AccountPanel title={copy['signIn.title']}>
        <div className="space-y-4">
          <p>{copy['signIn.signedIn'].replace('{name}', me.display_name)}</p>
          {me.email_verified ? null : (
            <Alert>
              <AlertDescription>
                {copy['verify.pending']}{' '}
                <Link href={`/${locale}/account/verify`} className="underline underline-offset-4">
                  {copy['verify.resend']}
                </Link>
              </AlertDescription>
            </Alert>
          )}
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={signOut} disabled={busy}>
              {copy['signIn.signOut']}
            </Button>
          </div>
        </div>
      </AccountPanel>
    );
  }

  return (
    <AccountPanel title={copy['signIn.title']} description={copy['signIn.intro']}>
      <FormError message={error} />
      {notice === null ? null : <p className="mb-4 text-sm text-muted-foreground">{notice}</p>}

      <form onSubmit={signIn} className="space-y-4">
        <Field label={copy.email} htmlFor="email">
          <Input id="email" name="email" type="email" autoComplete="username" required />
        </Field>

        <PasswordField label={copy.password} name="password" copy={copy} />

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? copy.working : copy['signIn.submit']}
        </Button>
      </form>

      <div className="mt-4 space-y-2 text-sm">
        <p>
          <Link href={`/${locale}/account/forgot-password`} className="underline underline-offset-4">
            {copy['signIn.forgot']}
          </Link>
        </p>
        <p className="text-muted-foreground">
          {copy['signIn.noAccount']}{' '}
          <Link href={`/${locale}/account/register`} className="text-foreground underline underline-offset-4">
            {copy['signIn.createOne']}
          </Link>
        </p>
      </div>
    </AccountPanel>
  );
}
