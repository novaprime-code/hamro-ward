-- Hamro Ward — local database setup, mirroring the shared server
-- (infra/postgres/create-hamroward-db.sh). Runs automatically on a fresh Docker
-- volume, and explicitly in CI.
--
--   hw_owner        owns schemas, runs migrations (created by the image as POSTGRES_USER)
--   hw_app          application access
--   hw_provisioner  CREATEDB; creates municipality databases (CLI only)
--
--   hw_central           central database (named "hamroward" on the server)
--   hw_central_test      automated tests
--   template_hamroward   template every municipality database is copied from

CREATE ROLE hw_app         LOGIN PASSWORD 'secret';
CREATE ROLE hw_provisioner LOGIN PASSWORD 'secret' CREATEDB;

GRANT hw_owner TO hw_provisioner;

-- Test database and the template, both copied from template1 while it is still plain.
\connect postgres
CREATE DATABASE hw_central_test OWNER hw_owner;
CREATE DATABASE template_hamroward OWNER hw_owner;
REVOKE ALL ON DATABASE template_hamroward FROM PUBLIC;

\connect hw_central
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT CONNECT ON DATABASE hw_central TO hw_app;
GRANT USAGE ON SCHEMA public TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;

\connect hw_central_test
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT CONNECT ON DATABASE hw_central_test TO hw_app;
GRANT USAGE ON SCHEMA public TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;

-- The template carries the extensions and the default privileges into every
-- municipality database that is copied from it, so hw_provisioner never needs
-- superuser rights and template1 stays untouched.
\connect template_hamroward
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT USAGE ON SCHEMA public TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;

\connect postgres
UPDATE pg_database SET datistemplate = true WHERE datname = 'template_hamroward';

-- Append-only tables (audit_events, moderation_decisions, issue_status_events)
-- narrow these grants to INSERT + SELECT in their own migrations.
