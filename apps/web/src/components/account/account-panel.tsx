'use client';

import { Eye, EyeOff } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/**
 * The frame every account screen shares (HW-E30-F01-T03; docs/09 §5.6).
 *
 * One card, narrow, centred: these are the only pages on the public site that
 * ask for something rather than tell the reader something, and looking
 * different from the ward pages is the point — it is how someone can see at a
 * glance that they are on a form that will take their password.
 */

/**
 * The account strings for one locale, with the `account.` prefix dropped.
 * Built on the server (the catalogues are server-only) and handed down, so
 * these components never import a catalogue of their own.
 */
export type Copy = Record<string, string>;

export function AccountPanel({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <Card className="mx-auto w-full max-w-md">
      <CardHeader>
        <CardTitle className="font-display text-[22px]">{title}</CardTitle>
        {description === undefined || description === '' ? null : (
          <CardDescription>{description}</CardDescription>
        )}
      </CardHeader>
      <CardContent className="pt-2">{children}</CardContent>
    </Card>
  );
}

/** A label, its control, and the API's complaint about it. */
export function Field({
  label,
  htmlFor,
  help,
  error,
  children,
}: {
  label: string;
  htmlFor: string;
  help?: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="block text-sm font-medium">
        {label}
      </label>
      {children}
      {help === undefined ? null : <p className="text-sm text-muted-foreground">{help}</p>}
      {error === undefined ? null : (
        <p id={`${htmlFor}-error`} className="text-sm font-medium text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * A password box with a show/hide toggle (09 §5.6).
 *
 * The toggle matters more here than it looks: the minimum is ten characters,
 * the advice is to use a sentence, and a long passphrase typed blind on a
 * phone keyboard is a long passphrase typed wrong.
 */
export function PasswordField({
  label,
  name,
  copy,
  help,
  error,
  autoComplete = 'current-password',
}: {
  label: string;
  name: string;
  copy: Copy;
  help?: string;
  error?: string;
  autoComplete?: string;
}) {
  const id = useId();
  const [shown, setShown] = useState(false);

  return (
    <Field label={label} htmlFor={id} help={help} error={error}>
      <div className="relative">
        <Input
          id={id}
          name={name}
          type={shown ? 'text' : 'password'}
          autoComplete={autoComplete}
          aria-describedby={error === undefined ? undefined : `${id}-error`}
          aria-invalid={error === undefined ? undefined : true}
          className="pe-11"
          required
        />
        <button
          type="button"
          onClick={() => setShown((was) => !was)}
          // Labelled, not titled: a title is invisible to a touch screen, and
          // the icon alone says nothing to a screen reader.
          aria-label={shown ? copy.hidePassword : copy.showPassword}
          aria-pressed={shown}
          className="absolute inset-y-0 end-0 flex w-11 items-center justify-center rounded-e-control text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {shown ? <EyeOff aria-hidden="true" className="size-5" /> : <Eye aria-hidden="true" className="size-5" />}
        </button>
      </div>
    </Field>
  );
}

/** Whatever went wrong, said once, at the top, and announced. */
export function FormError({ message }: { message: string | null }) {
  if (message === null) {
    return null;
  }

  return (
    <Alert variant="destructive" role="alert" className="mb-4">
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}
