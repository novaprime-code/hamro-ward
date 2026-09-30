# D-017 — shadcn/ui, with its tokens mapped onto the palettes

> **Filing note:** this entry belongs at the end of `DECISIONS.md`, after D-016.
> It is written as a separate file so the decision log can be appended to
> rather than rewritten wholesale. Paste it in and delete this file when
> convenient.

* **Date:** 2026-09-26
* **Status:** Accepted
* **Decided by:** Nova
* **Relates to:** `09_UX_UI_SPEC.md`, `NFR-NEU-02`, `NFR-NEU-04`, `NFR-ACC-01`

## Context

The web app's components were hand-written against a set of semantic CSS
variables (`--surface`, `--accent`, `--state-verified`) with five switchable
palettes in `tokens.css`. That worked, but every control — buttons, inputs,
badges, notices — was bespoke, and each new screen meant more bespoke markup
with no shared definition of what a button is.

Nova asked for shadcn/ui, keeping Himal as the default palette and keeping all
five switchable.

The obvious way to adopt shadcn is the way its documentation describes: run
`shadcn init`, which writes its own colour tokens as hex values into `:root` and
`.dark`. That would have produced two colour systems in one application — the
five palettes, and shadcn's — and every registry component would have needed
per-palette overrides. It would also have overwritten `globals.css`.

## Decision

1. **shadcn's token names are mapped onto the palette variables** inside the
   existing `@theme inline` block, rather than defined as colours:

   ```css
   --color-primary: var(--accent);
   --color-background: var(--bg);
   --color-card: var(--surface-2);
   --color-border: var(--line);
   --color-muted-foreground: var(--text-muted);
   ```

   `tokens.css` remains the only file in the application containing a colour
   value. Verified in the compiled stylesheet: `.bg-primary{background-color:var(--accent)}`.

2. **`shadcn init` is never run.** `components.json` is committed by hand, so
   `shadcn add <component>` — which only writes new files into
   `components/ui/` — continues to work.

3. **`raat` is the dark palette, and the `dark:` variant is bound to it**
   with `@custom-variant dark (&:is([data-theme="raat"] *))`. No `next-themes`,
   no `.dark` class that can disagree with `data-theme`.

4. **Primitives are added when a screen needs them,** not up front. Seven so
   far: button, input, card, badge, alert, separator, skeleton. Radix ships
   client components and the audience is on Android phones and inconsistent
   connections.

5. **The civic vocabulary stays ours.** Badge gains `verified`, `unverified`,
   `neutral` and `ai` variants; Alert gains `unverified` and `paused`. shadcn
   has no words for what this product is about, and "what verified looks like"
   needs exactly one definition.

6. **Three registry defaults are overridden, deliberately:**
   * focus is one `:focus-visible` outline in `globals.css` rather than
     per-component rings — an outline cannot be clipped by an ancestor's
     `overflow` (`NFR-ACC-01`);
   * every button size clears 44px, so `sm` differs by padding and type size
     rather than height;
   * inputs are `h-12` with `text-base`, below which iOS zooms the viewport on
     focus.

## Consequences

* An unmodified component from the registry renders correctly in all five
  palettes with no per-palette CSS. Adding a sixth palette is still a block in
  `tokens.css` plus a name in `lib/theme.ts`.
* Six small dependencies arrive: `clsx`, `tailwind-merge`,
  `class-variance-authority`, `lucide-react`, `@radix-ui/react-slot`,
  `@radix-ui/react-separator`. No component library is depended on — the
  components are source in the repository, which matches the no-vendor-lock-in
  principle better than a package that gets upgraded and fought.
* The token vocabulary changed across the whole app: `text-muted` →
  `text-muted-foreground`, `border-line` → `border-border`, `bg-surface-2` →
  `bg-card`, `text-accent-ink` → `text-accent-foreground`. **`text-muted` now
  means a background colour**, so any file still using it renders nearly
  invisible text.
* `09_UX_UI_SPEC.md` is now further out of date and needs rewriting against the
  built component set.
* Neutrality is unaffected. No party colours enter the palette, every template
  stays identical whatever the party, and the accent-versus-party-colour check
  (`NFR-NEU-04`) still applies to `tokens.css`, which is still the only place
  colours live.

## Alternatives considered

* **`shadcn init` as documented — rejected.** Two colour systems, per-palette
  overrides on every component, and `globals.css` overwritten.
* **Keep hand-written components — rejected.** It was working, but every new
  screen meant more bespoke controls with no shared definition, and the staff
  dashboard would have multiplied that.
* **A full component library (MUI, Mantine) — not considered seriously.**
  A runtime dependency that owns the markup is the opposite of what
  `06` §25's revisit triggers are for.
