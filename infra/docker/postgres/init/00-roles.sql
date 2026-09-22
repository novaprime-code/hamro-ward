-- Hamro Ward — local database roles (mirrors docs/12 §7)
-- hw_owner        owns schemas, runs migrations (created by the image)
-- hw_app          application DML
-- hw_provisioner  creates tenant databases (hw:tenant:create)
-- hw_backup       read-only for dumps

CREATE ROLE hw_app         LOGIN PASSWORD 'secret';
CREATE ROLE hw_provisioner LOGIN PASSWORD 'secret' CREATEDB;
CREATE ROLE hw_backup      LOGIN PASSWORD 'secret';

GRANT CONNECT ON DATABASE hw_central TO hw_app, hw_backup;

\connect hw_central

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;

GRANT USAGE ON SCHEMA public TO hw_app, hw_backup;

ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO hw_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hw_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO hw_backup;

-- Append-only tables (audit_events, moderation_decisions, issue_status_events)
-- narrow these grants to INSERT + SELECT in their own migrations.
