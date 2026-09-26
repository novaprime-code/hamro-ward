export function BetaBanner({ message }: { message: string }) {
  return (
    <p className="border-b border-border bg-accent px-4 py-2 text-center text-sm text-accent-foreground">
      {message}
    </p>
  );
}
