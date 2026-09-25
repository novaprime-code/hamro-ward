# syntax=docker/dockerfile:1
#
# Production image for the Hamro Ward site and staff dashboard: the Next.js
# standalone server on port 3000.
#
# Build context: the repository root (this is a pnpm workspace), so the build can
# see pnpm-workspace.yaml, the root package.json and, later, packages/api-client.
# Built for linux/arm64 by .github/workflows/image-{staging,production}.yml
#
# ---------------------------------------------------------------------------
# This image takes NO environment-specific build arguments, and that is the
# point (D-015).
#
# It used to take NEXT_PUBLIC_SITE_URL and NEXT_PUBLIC_ADMIN_HOST. Anything
# prefixed NEXT_PUBLIC_ is compiled into the browser bundle, which made the
# image specific to one environment: promoting the staging image to production
# would have served production visitors a bundle carrying staging's hostnames.
# Building a second image instead would mean production runs bytes that were
# never tested. Neither is acceptable, so the values moved to runtime —
# HW_SITE_URL is read by the server when it renders metadata.
#
# API_INTERNAL_URL is still baked, because Next.js resolves rewrites into the
# routes manifest during `next build`. It is safe to bake because it names the
# Compose SERVICE ("app"), not a container: service names are scoped to the
# stack's own network, so the same value is correct in every environment and
# two stacks can sit on one host without colliding.
# ---------------------------------------------------------------------------

ARG NODE_VERSION=22

# ---- 1. Dependencies --------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS deps
RUN apk add --no-cache libc6-compat
RUN corepack enable
WORKDIR /repo
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/web/package.json apps/web/
RUN pnpm install --frozen-lockfile --filter web...

# ---- 2. Build ---------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS builder
RUN corepack enable
WORKDIR /repo

# Same in every environment: "app" is the Compose service name on the stack's
# private backend network.
ENV API_INTERNAL_URL=http://app:8080 \
    NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /repo/node_modules ./node_modules
COPY --from=deps /repo/apps/web/node_modules ./apps/web/node_modules
COPY . .

RUN pnpm --filter web build
RUN mkdir -p apps/web/public
RUN test -f apps/web/.next/standalone/apps/web/server.js \
    || { echo "ERROR: .next/standalone/apps/web/server.js is missing. Keep output: 'standalone' in next.config.ts."; exit 1; }

# A build that leaked an environment name into the bundle would be promotable
# in theory and wrong in practice, so the build fails instead.
RUN ! grep -rqlE 'hw\.jayshyampatel|staging\.hamroward' apps/web/.next/static 2>/dev/null \
    || { echo "ERROR: an environment hostname was compiled into the browser bundle. Keep environment values out of NEXT_PUBLIC_* (D-015)."; exit 1; }

# ---- 3. Runtime -------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

# The standalone output of a workspace keeps the repository layout, so the
# server lives under apps/web and the shared node_modules sit beside it.
COPY --from=builder --chown=node:node /repo/apps/web/.next/standalone ./
COPY --from=builder --chown=node:node /repo/apps/web/.next/static ./apps/web/.next/static
COPY --from=builder --chown=node:node /repo/apps/web/public ./apps/web/public

USER node
EXPOSE 3000
CMD ["node", "apps/web/server.js"]
