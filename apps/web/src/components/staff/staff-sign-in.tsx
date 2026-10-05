'use client';

import { useCallback, useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  ensureCsrfCookie,
  firstError,
  interpretSignIn,
  lockoutMessage,
  staffRequest,
  type StaffMe,
} from '@/lib/staff-auth';

/**
 * Staff sign-in, two-step verification and first-time enrolment
 * (HW-E13-F02-T02; 09 §6). One screen, one state machine:
 *
 *   loading → sign-in → challenge ─────────────────────────┐
 *                    └→ enrol: password → scan → codes ─────┴→ signed-in
 *
 * Enrolment is forced: Laravel answers every staff endpoint with 403
 * `two_factor_required` until two-factor is confirmed, and this screen reads
 * that as "start enrolment". The recovery codes cannot be dismissed until the
 * person says they have saved them (the acceptance criterion), and a lockout
 * says when the next attempt is possible.
 */

type Step =
  | { name: 'loading' }
  | { name: 'sign-in'; notice?: string }
  | { name: 'challenge' }
  | { name: 'enrol-password' }
  | { name: 'enrol-scan'; qrSvg: string; secret: string }
  | { name: 'enrol-codes'; codes: string[] }
  | { name: 'signed-in'; me: StaffMe };

export function StaffSignIn() {
  const [step, setStep] = useState<Step>({ name: 'loading' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /** Where the session stands now, according to the API. */
  const refresh = useCallback(async () => {
    const me = await staffRequest('GET', '/api/v1/staff/me');

    if (me.status === 200) {
      setStep({ name: 'signed-in', me: (me.body as { data: StaffMe }).data });
    } else if (me.status === 403 && (me.body as { two_factor_required?: boolean })?.two_factor_required) {
      setStep({ name: 'enrol-password' });
    } else {
      setStep({ name: 'sign-in' });
    }
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
      setError('Something went wrong. Check your connection and try again.');
    } finally {
      setBusy(false);
    }
  }

  function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    // A fresh attempt replaces any earlier note ("You have signed out.").
    setStep({ name: 'sign-in' });

    void run(async () => {
      await ensureCsrfCookie();
      const response = await staffRequest('POST', '/login', {
        email: String(form.get('email') ?? ''),
        password: String(form.get('password') ?? ''),
      });
      const outcome = interpretSignIn(response.status, response.body, response.retryAfter);

      if (outcome.kind === 'challenge') {
        setStep({ name: 'challenge' });
      } else if (outcome.kind === 'signed-in') {
        await refresh();
      } else if (outcome.kind === 'locked') {
        setError(lockoutMessage(outcome.lockedUntil));
      } else if (outcome.kind === 'throttled') {
        setError(`Too many attempts from here. Wait ${Math.ceil(outcome.retryAfterSeconds / 60)} minute(s) and try again.`);
      } else {
        setError(outcome.message);
      }
    });
  }

  function challenge(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const code = String(form.get('code') ?? '').trim();
    const usingRecovery = !/^\d{6}$/.test(code.replace(/\s/g, ''));

    void run(async () => {
      const response = await staffRequest(
        'POST',
        '/two-factor-challenge',
        usingRecovery ? { recovery_code: code } : { code: code.replace(/\s/g, '') },
      );

      if (response.status === 204 || response.status === 200) {
        await refresh();
      } else if (response.status === 429) {
        setError('Too many codes tried. Wait a minute and try again.');
      } else {
        setError(firstError(response.body) ?? 'That code was not accepted.');
      }
    });
  }

  function startEnrolment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    void run(async () => {
      await ensureCsrfCookie();
      const confirmed = await staffRequest('POST', '/user/confirm-password', { password: String(form.get('password') ?? '') });

      if (confirmed.status !== 201 && confirmed.status !== 200) {
        setError(firstError(confirmed.body) ?? 'That password is not right.');

        return;
      }

      await staffRequest('POST', '/user/two-factor-authentication');
      const qr = await staffRequest('GET', '/user/two-factor-qr-code');
      const secret = await staffRequest('GET', '/user/two-factor-secret-key');

      setStep({
        name: 'enrol-scan',
        qrSvg: String((qr.body as { svg?: string })?.svg ?? ''),
        secret: String((secret.body as { secretKey?: string })?.secretKey ?? ''),
      });
    });
  }

  function confirmEnrolment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);

    void run(async () => {
      const response = await staffRequest('POST', '/user/confirmed-two-factor-authentication', {
        code: String(form.get('code') ?? '').replace(/\s/g, ''),
      });

      if (response.status !== 200) {
        setError(firstError(response.body) ?? 'That code was not accepted. Use the newest code in the app.');

        return;
      }

      const codes = await staffRequest('GET', '/user/two-factor-recovery-codes');
      setStep({ name: 'enrol-codes', codes: Array.isArray(codes.body) ? (codes.body as string[]) : [] });
    });
  }

  function signOut() {
    void run(async () => {
      await staffRequest('POST', '/logout');
      setStep({ name: 'sign-in', notice: 'You have signed out.' });
    });
  }

  return (
    <Panel step={step.name}>
      {error === null ? null : (
        <Alert variant="destructive" role="alert" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {step.name === 'loading' ? <p className="text-muted-foreground">Checking your session…</p> : null}

      {step.name === 'sign-in' ? (
        <form onSubmit={signIn} className="space-y-4">
          {step.notice ? <p className="text-sm text-muted-foreground">{step.notice}</p> : null}
          <Field label="Email" htmlFor="email">
            <Input id="email" name="email" type="email" autoComplete="username" required />
          </Field>
          <Field label="Password" htmlFor="password">
            <Input id="password" name="password" type="password" autoComplete="current-password" required />
          </Field>
          <Button type="submit" className="w-full" disabled={busy}>
            Sign in
          </Button>
        </form>
      ) : null}

      {step.name === 'challenge' ? (
        <form onSubmit={challenge} className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Enter the six-digit code from your authenticator app. Lost your phone? Enter one of your recovery codes
            instead.
          </p>
          <Field label="Code" htmlFor="code">
            <Input id="code" name="code" inputMode="text" autoComplete="one-time-code" autoFocus required />
          </Field>
          <Button type="submit" className="w-full" disabled={busy}>
            Verify
          </Button>
        </form>
      ) : null}

      {step.name === 'enrol-password' ? (
        <form onSubmit={startEnrolment} className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Staff accounts need two-step verification before anything else opens. Confirm your password to start.
          </p>
          <Field label="Password" htmlFor="confirm-password">
            <Input id="confirm-password" name="password" type="password" autoComplete="current-password" required />
          </Field>
          <Button type="submit" className="w-full" disabled={busy}>
            Continue
          </Button>
        </form>
      ) : null}

      {step.name === 'enrol-scan' ? (
        <form onSubmit={confirmEnrolment} className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Scan this with an authenticator app, such as Google Authenticator, Aegis or 1Password, then enter the code
            it shows.
          </p>
          {step.qrSvg === '' ? null : (
            // An <img> from a data: URL rather than injected markup: the SVG is
            // our API's, but the strict CSP and the habit both say never insert
            // HTML.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={`data:image/svg+xml;utf8,${encodeURIComponent(step.qrSvg)}`}
              alt="QR code for your authenticator app"
              width={192}
              height={192}
              className="mx-auto rounded-control border border-border bg-white p-2"
            />
          )}
          {step.secret === '' ? null : (
            <p className="text-center text-sm text-muted-foreground">
              Can’t scan? Enter this key: <code className="break-all font-mono text-foreground">{step.secret}</code>
            </p>
          )}
          <Field label="Code from the app" htmlFor="enrol-code">
            <Input id="enrol-code" name="code" inputMode="numeric" autoComplete="one-time-code" required />
          </Field>
          <Button type="submit" className="w-full" disabled={busy}>
            Turn on two-step verification
          </Button>
        </form>
      ) : null}

      {step.name === 'enrol-codes' ? <RecoveryCodes codes={step.codes} onDone={() => void refresh()} /> : null}

      {step.name === 'signed-in' ? (
        <div className="space-y-4">
          <p>
            Signed in as <strong>{step.me.name}</strong> ({step.me.email}).
          </p>
          {step.me.operator_admin ? <Badge variant="neutral">Operator admin</Badge> : null}
          <div>
            <h2 className="mb-2 font-semibold">Where you can act</h2>
            {step.me.memberships.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {step.me.operator_admin ? 'Every municipality.' : 'No municipality yet. An operator admin assigns roles.'}
              </p>
            ) : (
              <ul className="space-y-1 text-sm">
                {step.me.memberships.map((membership) => (
                  <li key={membership.tenant_key}>
                    <code className="font-mono">{membership.tenant_key}</code> · {membership.role.replace('_', ' ')}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <Button type="button" variant="outline" onClick={signOut} disabled={busy}>
            Sign out
          </Button>
        </div>
      ) : null}
    </Panel>
  );
}

/** The codes, shown once, and a confirmation that must be ticked to leave. */
function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const [saved, setSaved] = useState(false);

  return (
    <div className="space-y-4">
      <Alert>
        <AlertTitle>Save your recovery codes now</AlertTitle>
        <AlertDescription>
          Each code signs you in once if you lose your phone. They will not be shown again. Keep them somewhere that is
          not the phone.
        </AlertDescription>
      </Alert>
      <ul className="grid gap-2 rounded-control border border-border bg-muted p-3 font-mono text-sm sm:grid-cols-2" aria-label="Recovery codes">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <label className="flex items-start gap-3 text-sm">
        <input type="checkbox" className="mt-1 size-5" checked={saved} onChange={(event) => setSaved(event.target.checked)} />
        <span>I have saved these codes somewhere safe.</span>
      </label>
      <Button type="button" className="w-full" disabled={!saved} onClick={onDone}>
        Continue
      </Button>
    </div>
  );
}

const TITLES: Record<Step['name'], { title: string; description: string }> = {
  loading: { title: 'Staff sign-in', description: '' },
  'sign-in': { title: 'Staff sign-in', description: 'For moderators, verifiers and data editors.' },
  challenge: { title: 'Two-step verification', description: 'One more step.' },
  'enrol-password': { title: 'Set up two-step verification', description: 'Step 1 of 3' },
  'enrol-scan': { title: 'Set up two-step verification', description: 'Step 2 of 3' },
  'enrol-codes': { title: 'Set up two-step verification', description: 'Step 3 of 3' },
  'signed-in': { title: 'Staff dashboard', description: 'The moderation queue arrives with v0.2.' },
};

function Panel({ step, children }: { step: Step['name']; children: ReactNode }) {
  const { title, description } = TITLES[step];

  return (
    <Card className="mx-auto w-full max-w-md">
      <CardHeader>
        <CardTitle className="text-[22px]">{title}</CardTitle>
        {description === '' ? null : <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent className="pt-2">{children}</CardContent>
    </Card>
  );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="block text-sm font-medium">
        {label}
      </label>
      {children}
    </div>
  );
}
