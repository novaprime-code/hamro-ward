import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.test.{ts,tsx}'],
  },
  /*
   * Vite searches for a PostCSS config whenever it starts a transform pipeline,
   * finds postcss.config.mjs, and fails to load "@tailwindcss/postcss" — the
   * Tailwind 4 plugin is not a plugin Vite 5's PostCSS loader accepts by name.
   * The whole run then dies in an unhandled rejection before a single test file
   * is collected.
   *
   * Nothing under test imports CSS: the suite runs in the node environment on
   * pure modules. Declaring an empty plugin list stops the search, so the
   * Tailwind pipeline stays exactly as it is for `next build` and the tests
   * stop depending on it.
   */
  css: { postcss: { plugins: [] } },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
});
