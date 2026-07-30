-- ---------------------------------------------------------------------------
-- Application database role for filiale Row-Level Security.
--
-- READ THIS FIRST. PostgreSQL ignores every RLS policy for a role that is
-- SUPERUSER or has BYPASSRLS. This project's .env currently connects as
-- `postgres`, which is both:
--
--     select rolsuper, rolbypassrls from pg_roles where rolname = current_user;
--      rolsuper | rolbypassrls
--     ----------+--------------
--      t        | t
--
-- Connecting as that role means the policies exist, look correct in \d+, and
-- filter absolutely nothing. `kh_app` below exists to be the role the
-- application actually connects as.
--
-- Run once, as a superuser:
--     psql -h 127.0.0.1 -U postgres -d knowledge_hub -f database/sql/create_rls_app_role.sql
-- ---------------------------------------------------------------------------

-- 1. The runtime role. NOSUPERUSER + NOBYPASSRLS are the whole point.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kh_app') THEN
        CREATE ROLE kh_app LOGIN PASSWORD 'change-me-in-env'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;

-- 2. Enough privilege to run the application, and no more. Note that DDL stays
--    with the owner: migrations are run separately (see step 5).
GRANT CONNECT ON DATABASE knowledge_hub TO kh_app;
GRANT USAGE ON SCHEMA public TO kh_app;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kh_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kh_app;

-- 3. Same grants for tables created by future migrations. Must be run as the
--    role that will own them (postgres), which is why this file is superuser-only.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kh_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO kh_app;

-- 4. Verify. Both columns must read `f`, otherwise RLS is decorative.
SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'kh_app';

-- ---------------------------------------------------------------------------
-- 5. Wire it up
--
-- .env — the application connects as the restricted role:
--     DB_USERNAME=kh_app
--     DB_PASSWORD=<the password used above>
--
-- Migrations still need DDL rights, so they keep running as the owner. Add an
-- admin connection to config/database.php that reuses the pgsql block with
-- DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD, then:
--     php artisan migrate --database=pgsql_admin
--
-- Because every table is created with FORCE ROW LEVEL SECURITY, even the owner
-- is subject to the policies — so `postgres` bypasses them only by virtue of
-- being a superuser, and nothing else in the system does.
-- ---------------------------------------------------------------------------
