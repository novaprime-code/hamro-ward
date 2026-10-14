'use client';

import Link from 'next/link';
import Script from 'next/script';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { AccountPanel, Field, FormError, PasswordField } from '@/components/account/account-panel';
import type { Copy } from '@/components/account/account-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { LOCALES } from '@/i18n/config';
import type { Locale } from '@/i18n/config';
import { ensureCsrfCookie, fortifyRequest, interpret, waitMessage } from '@/lib/account-auth';

const LANGUAGE_NAMES: Record<Locale, string> = { ne: 'नेपाली', en: 'English' };

/**
 * Create an account (HW-E30-F01-T03; docs/09 §5.6).
 *
 * The API answers a taken address exactly as it answers a free one, so this
 * screen must too: success means "we have sent a link", never "your account
 * is ready". Saying more here would undo the whole point of the endpoint
 * (see RegisterController in the API).
 *
 * Nobody is signed in by registering, for the same reason, so the screen
 * after this one is "check your inbox" and not a dashboard.
 */
export function RegisterForm({
  copy,
  locale,
  turnstileSiteKey,
}: {
  copy: Copy;
  locale: Locale;
  turnstileSiteKey: string | null;
}) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    setBusy(true);
    setError(null);
    setErrors({});

    void (async () => {
      try {
        await ensureCsrfCookie();

        const response = await fortifyRequest('POST', '/register', {
          display_name: String(form.get('display_name') ?? ''),
          email: String(form.get('email') ?? ''),
          password: String(form.get('password') ?? ''),
          password_confirmation: String(form.get('password_confirmation') ?? ''),
          preferred_locale: String(form.get('preferred_locale') ?? locale),
          // Cloudflare's widget writes into this hidden input itself.
          turnstile_token: String(form.get('cf-turnstile-response') ?? ''),
        });
        const outcome = interpret(response.status, response.body, response.retryAfter, copy['register.failed']);

        if (outcome.kind === 'ok') {
          setSent(true);
        } else if (outcome.kind === 'invalid') {
          setErrors(outcome.errors);
          setError(outcome.message);
        } else if (outcome.kind === 'throttled') {
          setError(waitMessage(outcome.retryAfterSeconds, copy.wait));
        } else {
          setError(outcome.message);
        }
      } catch {
        setError(copy.offline);
      } finally {
        setBusy(false);
        // A Turnstile token is single-use: a refused submission needs a new
        // one before the next attempt can pass.
        window.turnstile?.reset();
      }
    })();
  }

  if (sent) {
    return (
      <AccountPanel title={copy['verify.title']}>
        <Alert>
          <AlertDescription>{copy['verify.sent']}</AlertDescription>
        </Alert>
        <p className="mt-4 text-sm text-muted-foreground">{copy['verify.why']}</p>
        <p className="mt-4 text-sm">
          <Link href={`/${locale}/account/verify`} className="underline underline-offset-4">
            {copy['verify.resend']}
          </Link>
        </p>
      </AccountPanel>
    );
  }

  return (
    <AccountPanel title={copy['register.title']} description={copy['register.intro']}>
      <FormError message={error} />

      <form onSubmit={register} className="space-y-4">
        <Field
          label={copy['register.displayName']}
          htmlFor="display_name"
          help={copy['register.displayNameHelp']}
          error={errors.display_name}
        >
          <Input id="display_name" name="display_name" autoComplete="name" maxLength={60} required />
        </Field>

        <Field label={copy.email} htmlFor="email" error={errors.email}>
          <Input id="email" name="email" type="email" autoComplete="email" required />
        </Field>

        <PasswordField
          label={copy.password}
          name="password"
          copy={copy}
          help={copy.passwordHelp}
          error={errors.password}
          autoComplete="new-password"
        />

        <PasswordField
          label={copy.passwordConfirm}
          name="password_confirmation"
          copy={copy}
          autoComplete="new-password"
        />

        <fieldset className="space-y-1.5">
          <legend className="block text-sm font-medium">{copy['register.language']}</legend>
          <div className="flex gap-4">
            {LOCALES.map((option) => (
              <label key={option} className="flex items-center gap-2 text-base">
                <input
                  type="radio"
                  name="preferred_locale"
                  value={option}
                  defaultChecked={option === locale}
                  className="size-5"
                />
                <span lang={option}>{LANGUAGE_NAMES[option]}</span>
              </label>
            ))}
          </div>
        </fieldset>

        {turnstileSiteKey === null ? null : (
          <div className="space-y-1.5">
            <div className="cf-turnstile" data-sitekey={turnstileSiteKey} data-language={locale} />
            {errors.turnstile_token === undefined ? null : (
              <p className="text-sm font-medium text-destructive">{errors.turnstile_token}</p>
            )}
          </div>
        )}

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? copy.working : copy['register.submit']}
        </Button>
      </form>

      <div className="mt-4 space-y-2 text-sm">
        <p>
          <Link href={`/${locale}/privacy`} className="underline underline-offset-4">
            {copy['register.privacy']}
          </Link>
        </p>
        <p className="text-muted-foreground">
          {copy['register.haveAccount']}{' '}
          <Link href={`/${locale}/account/sign-in`} className="text-foreground underline underline-offset-4">
            {copy['signIn.title']}
          </Link>
        </p>
      </div>

      {/*
        Loaded only when the deployment has a key, so a local machine and CI —
        which have none — do not wait on a third party to render a form.
      */}
      {turnstileSiteKey === null ? null : (
        <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js" strategy="lazyOnload" />
      )}
    </AccountPanel>
  );
}
