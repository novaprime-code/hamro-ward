# syntax=docker/dockerfile:1
#
# PostgreSQL 17 with both pgvector and PostGIS, for the shared database server.
#
# Why this image exists: the server currently runs pgvector/pgvector:pg17, which
# has no PostGIS. Hamro Ward stores ward boundaries and issue locations as
# geometry, and the AI assistant will need pgvector later, so both extensions
# have to live in the same server. Same major version and same data directory as
# the image it replaces, so the swap is a restart, not a migration.
#
# Built for linux/amd64 (development machines) and linux/arm64 (the server).

ARG PG_MAJOR=17
FROM pgvector/pgvector:pg${PG_MAJOR}

ARG PG_MAJOR=17

# The base image already has the PostgreSQL apt repository configured.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        "postgresql-${PG_MAJOR}-postgis-3" \
        "postgresql-${PG_MAJOR}-postgis-3-scripts"; \
    rm -rf /var/lib/apt/lists/*

# Fail the build if an extension is missing, rather than at CREATE EXTENSION time
# on the server.
RUN set -eux; \
    for ext in vector postgis postgis_topology pg_trgm btree_gist citext; do \
        test -f "/usr/share/postgresql/${PG_MAJOR}/extension/${ext}.control" \
            || { echo "missing extension: ${ext}"; exit 1; }; \
    done

LABEL org.opencontainers.image.title="Hamro Ward PostgreSQL" \
      org.opencontainers.image.description="PostgreSQL 17 with pgvector and PostGIS 3" \
      org.opencontainers.image.source="https://github.com/novaprime-code/hamro-ward"
