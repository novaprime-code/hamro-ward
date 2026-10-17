'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, Field, FormError } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Locale } from '@/i18n/config';
import { fetchMe, fortifyRequest, interpret, waitMessage } from '@/lib/account-auth';

/**
 * "Check your inbox", and where a verification link lands
 * (HW-E30-F01-T03; docs/09 §5.6).
 *
 * The API redirects here after following the link, with ?verified=1 or
 * ?expired=1 — so this one screen is both the thing seen right after signing
 * up and the thing seen after clicking, which is also where a failed click
 * can offer another link without the person hunting for the page.
 *
 * Resending works signed out, because sign-up signs nobody in and the whole
 * reason to be here is that the first mail did not arrive. The answer is the
 * same whatever the address, matching the API.
 */
export function VerifyPanel({ copy, locale }: { copy: Copy; locale: Locale }) {
  const params = useSearchParams();
  const [me, setMe] = useState<{ verified: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [resent, setResent] = useState(false);
  const [busy, setBusy] = useState(false);

  const verified = params.get('verified') === '1';
  const expired = params.get('expired') === '1';

  useEffect(() => {
    void (async () => {
      const found = await fetchMe();

      setMe(found === null ? null : { verified: found.email_verified });
    })();
  }, []);

  function resend(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    setBusy(true);
    setError(null);

    void (async () => {
      try {
        const response = await fortifyRequest('POST', '/email/verification-notification', {
          email: String(form.get('email') ?? ''),
        });
        const outcome = interpret(response.status, response.body, response.retryAfter, copy.offline);

        if (outcome.kind === 'ok') {
          setResent(true);
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

  if (verified || me?.verified === true) {
    return (
      <AccountPanel title={copy['verify.title']}>
        <Alert>
          <AlertDescription>{verified ? copy['verify.done'] : copy['verify.verified']}</AlertDescription>
        </Alert>
        <p className="mt-4">
          <Link href={`/${locale}/account/sign-in`} className="underline underline-offset-4">
            {copy['signIn.title']}
          </Link>
        </p>
      </AccountPanel>
    );
  }

  return (
    <AccountPanel title={copy['verify.title']}>
      <FormError message={error} />

      <Alert className="mb-4">
        <AlertDescription>{expired ? copy['verify.expired'] : copy['verify.sent']}</AlertDescription>
      </Alert>

      <p className="mb-4 text-sm text-muted-foreground">{copy['verify.why']}</p>

      {resent ? (
        <p className="text-sm">{copy['verify.resent']}</p>
      ) : (
        <form onSubmit={resend} className="space-y-4">
          <Field label={copy['verify.resendLabel']} htmlFor="email">
            <Input id="email" name="email" type="email" autoComplete="email" required />
          </Field>
          <Button type="submit" variant="outline" className="w-full" disabled={busy}>
            {busy ? copy.working : copy['verify.resend']}
          </Button>
        </form>
      )}
    </AccountPanel>
  );
}
