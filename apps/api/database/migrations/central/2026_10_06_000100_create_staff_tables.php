<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — staff identity and per-municipality authority (docs/05 §9,
| docs/12 §11.5, HW-E13-F01-T01).
|
| Staff are a separate table from citizens on a separate guard: the same person
| may hold both, with two accounts and two passwords, and nothing a citizen
| session holds can be presented as a staff one (docs/12 §11.1).
|
| Authority has two layers:
|   global_role      operator_admin, or nothing. Implies every tenant.
|   staff_memberships one role in one tenant. A moderator in Koshara has no
|                    rights in Sonapur, and a membership is the only way to
|                    get any.
|
| A membership is never edited or deleted. Changing someone's role revokes the
| current row and grants a new one, so "who could moderate here on 3 March"
| has an answer. The partial unique index allows one live membership per
| person per tenant and any number of revoked ones.
|
| Two-factor columns exist now; enrolment and the rule that nothing works
| without it arrive with staff login (HW-E13-F01-T03).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('global_role', 30)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE staff_users ALTER COLUMN email TYPE citext');

        DB::statement(<<<'SQL'
            ALTER TABLE staff_users
                ADD CONSTRAINT staff_users_name_length
                    CHECK (char_length(btrim(name)) BETWEEN 2 AND 100),
                ADD CONSTRAINT staff_users_email_shape
                    CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
                ADD CONSTRAINT staff_users_global_role_check
                    CHECK (global_role IS NULL OR global_role IN ('operator_admin')),
                ADD CONSTRAINT staff_users_two_factor_confirmed_has_secret
                    CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL)
            SQL);

        Schema::create('staff_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_user_id');
            $table->uuid('tenant_id');
            $table->string('role', 20);
            $table->uuid('granted_by')->nullable();
            $table->timestampTz('granted_at')->useCurrent();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->foreign('staff_user_id')->references('id')->on('staff_users')->restrictOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('granted_by')->references('id')->on('staff_users')->restrictOnDelete();
            $table->foreign('revoked_by')->references('id')->on('staff_users')->restrictOnDelete();

            $table->index(['tenant_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE staff_memberships
                ADD CONSTRAINT staff_memberships_role_check
                    CHECK (role IN ('moderator', 'verifier', 'data_editor', 'viewer')),
                ADD CONSTRAINT staff_memberships_revoker_needs_revocation
                    CHECK (revoked_by IS NULL OR revoked_at IS NOT NULL),
                ADD CONSTRAINT staff_memberships_revoked_after_granted
                    CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX staff_memberships_one_live_per_tenant
                ON staff_memberships (staff_user_id, tenant_id)
                WHERE revoked_at IS NULL
            SQL);

        /*
         * Revocation is the only change a membership may undergo, and it is
         * one-way. Anything else would rewrite who had authority, and when.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_memberships_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'staff_memberships are revoked, never deleted';
                END IF;

                IF OLD.revoked_at IS NOT NULL
                   OR NEW.staff_user_id IS DISTINCT FROM OLD.staff_user_id
                   OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.role IS DISTINCT FROM OLD.role
                   OR NEW.granted_by IS DISTINCT FROM OLD.granted_by
                   OR NEW.granted_at IS DISTINCT FROM OLD.granted_at THEN
                    RAISE EXCEPTION 'staff_memberships: only revoking a live membership is allowed';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER staff_memberships_revoke_only
                BEFORE UPDATE OR DELETE ON staff_memberships
                FOR EACH ROW EXECUTE FUNCTION staff_memberships_guard();
            SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_memberships');
        Schema::dropIfExists('staff_users');
        DB::unprepared('DROP FUNCTION IF EXISTS staff_memberships_guard() CASCADE');
    }

    private function grantRuntimeRoles(): void
    {
        $grants = [
            'tenancy.roles.app' => ['staff_users' => 'SELECT, INSERT, UPDATE', 'staff_memberships' => 'SELECT, INSERT, UPDATE'],
            'tenancy.roles.backup' => ['staff_users' => 'SELECT', 'staff_memberships' => 'SELECT'],
        ];

        foreach ($grants as $key => $tables) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            foreach ($tables as $table => $grant) {
                DB::unprepared(<<<SQL
                    DO \$\$
                    BEGIN
                        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                            EXECUTE format('REVOKE ALL ON TABLE {$table} FROM %I', '{$role}');
                            EXECUTE format('GRANT {$grant} ON TABLE {$table} TO %I', '{$role}');
                        END IF;
                    END
                    \$\$;
                    SQL);
            }
        }
    }
};
