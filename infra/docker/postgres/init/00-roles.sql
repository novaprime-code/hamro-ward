-- Hamro Ward — database roles and base databases (docs/12 §7)
--
--   hw_owner        owns schemas, runs migrations (created by the image as POSTGRES_USER)
--   hw_app          application DML
--   hw_provisioner  creates/drops tenant databases (CLI only); member of hw_owner so
--                   it can create databases OWNED BY hw_owner
--   hw_backup       read-only for dumps
--
-- Runs automatically on a fresh Docker volume, and explicitly in CI:
--   psql -h 127.0.0.1 -U hw_owner -d hw_central -v ON_ERROR_STOP=1 -f 00-roles.sql

CREATE ROLE hw_app         LOGIN PASSWORD 'secret';
CREATE ROLE hw_provisioner LOGIN PASSWORD 'secret' CREATEDB;
CREATE ROLE hw_backup      LOGIN PASSWORD 'secret';

GRANT hw_owner TO hw_provisioner;

-- Every database created later (test database, tenant databases) is copied from
-- template1, so installing the extensions here means the provisioner never
-- needs superuser rights to get PostGIS.
\connect template1
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;

-- Separate database for automated tests (phpunit.xml uses it).
\connect postgres
CREATE DATABASE hw_central_test OWNER hw_owner TEMPLATE template1;

-- ---------------------------------------------------------------------------
-- hw_central (created by the image before this script, so extensions are added here)
-- ---------------------------------------------------------------------------
\connect hw_central
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;

GRANT CONNECT ON DATABASE hw_central TO hw_app, hw_backup;
GRANT USAGE ON SCHEMA public TO hw_app, hw_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO hw_backup;

-- ---------------------------------------------------------------------------
-- hw_central_test (same grants)
-- ---------------------------------------------------------------------------
\connect hw_central_test
GRANT CONNECT ON DATABASE hw_central_test TO hw_app, hw_backup;
GRANT USAGE ON SCHEMA public TO hw_app, hw_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO hw_backup;

-- Append-only tables (audit_events, moderation_decisions, issue_status_events)
-- narrow these grants to INSERT + SELECT in their own migrations.
