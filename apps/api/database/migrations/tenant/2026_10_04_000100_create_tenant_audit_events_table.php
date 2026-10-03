<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — the append-only audit trail for changes inside one municipality
| (docs/05 §9.1, §12 tenant step 6, FR-AUD-01, FR-AUD-02).
|
| Same shape as the central table. Kept in the tenant database so that a
| per-tenant restore after a bad import (docs/12 §9) restores the record of
| what that import did along with the data it did it to.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->uuid('request_id')->nullable();
            $table->ipAddress('ip_truncated')->nullable();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('action');
            $table->index('request_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_actor_type_check
                    CHECK (actor_type IN ('staff', 'system', 'importer', 'public')),
                ADD CONSTRAINT audit_events_action_format
                    CHECK (action ~ '^[a-z_]+(\.[a-z_]+)+$'),
                ADD CONSTRAINT audit_events_subject_is_paired
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL))
            SQL);

        /*
         * Append-only for everyone, the owner included. The grants below keep
         * the application role from changing history; this keeps a migration,
         * a console session or a future maintenance script from doing it
         * either. TRUNCATE is a separate privilege and a separate trigger
         * event, so it is refused separately.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only: % is not allowed', TG_OP;
            END
            $$;

            CREATE TRIGGER audit_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only();
            SQL);

        $this->narrowRuntimeGrants();
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only() CASCADE');
    }

    /**
     * The database's default privileges hand the application role full DML on
     * every new table (infra/docker/postgres/init/00-roles.sql). Here that is
     * exactly wrong, so it is taken back explicitly: INSERT and SELECT only
     * (docs/05 §9.1, FR-AUD-02). The backup role, when there is one, reads.
     */
    private function narrowRuntimeGrants(): void
    {
        foreach (['tenancy.roles.app' => 'SELECT, INSERT', 'tenancy.roles.backup' => 'SELECT'] as $key => $grant) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            DB::unprepared(<<<SQL
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                        EXECUTE format('REVOKE ALL ON TABLE audit_events FROM %I', '{$role}');
                        EXECUTE format('GRANT {$grant} ON TABLE audit_events TO %I', '{$role}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }
};
