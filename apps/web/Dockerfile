# syntax=docker/dockerfile:1
#
# Production image for the Hamro Ward site and staff dashboard: the Next.js
# standalone server on port 3000.
#
# Build context: the repository root (this is a pnpm workspace), so the build can
# see pnpm-workspace.yaml, the root package.json and, later, packages/api-client.
# Built for linux/arm64 by .github/workflows/image-web.yml
#
# Two kinds of build-time values, both baked into the image:
#   NEXT_PUBLIC_*     end up in the browser bundle
#   API_INTERNAL_URL  is used by next.config.ts rewrites, which Next.js compiles
#                     into the routes manifest at build time — changing it needs
#                     a rebuild, not just a restart

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

ARG NEXT_PUBLIC_SITE_URL
ARG NEXT_PUBLIC_ADMIN_HOST
ARG API_INTERNAL_URL=http://hamroward-app:8080
ENV NEXT_PUBLIC_SITE_URL=${NEXT_PUBLIC_SITE_URL} \
    NEXT_PUBLIC_ADMIN_HOST=${NEXT_PUBLIC_ADMIN_HOST} \
    API_INTERNAL_URL=${API_INTERNAL_URL} \
    NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /repo/node_modules ./node_modules
COPY --from=deps /repo/apps/web/node_modules ./apps/web/node_modules
COPY . .

RUN pnpm --filter web build
RUN mkdir -p apps/web/public
RUN test -f apps/web/.next/standalone/apps/web/server.js \
    || { echo "ERROR: .next/standalone/apps/web/server.js is missing. Keep output: 'standalone' in next.config.ts."; exit 1; }

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
