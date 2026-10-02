<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — staff authorization (docs/12 §11.5, docs/05 §9, docs/06 §12).
|
| TWO KINDS OF AUTHORIZATION, deliberately not one mechanism:
|
|   * `operator_admin` is a GLOBAL role, held through spatie/laravel-permission,
|     and it implies access to every municipality. There is exactly one such
|     role and there should stay exactly one — it is the person who runs the
|     platform, not a job title.
|
|   * moderator, verifier, data_editor and viewer are NOT roles. They are
|     MEMBERSHIPS of one tenant, in `staff_memberships`, checked by
|     StaffTenantPolicy. A moderator in Koshara has no rights in Sonapur, and
|     that has to be structurally true rather than remembered: a global
|     `moderator` role would quietly grant the whole country.
|
| The spatie tables are hand-written here rather than published, for one
| reason: the published migration keys its morph column as
| `unsignedBigInteger`, and every identifier in this schema is a uuid. A
| bigint morph key against uuid staff_users does not fail at migration time —
| it fails later, silently, when a role lookup matches nothing.
|
| `teams` stays off in config/permission.php. It looks like the tenant feature
| and is not: it would scope the GLOBAL role per tenant, which is the opposite
| of what operator_admin means, and it would invite the four membership roles
| back into the role table where they do not belong.
|
| Not here, and intentionally: `affiliation_declarations` and `recusals`
| (D-002). They gate who may hold a moderator or verifier membership and which
| items they may act on, they are their own task (HW-E13-F01-T04), and
| StaffTenantPolicy is written so they slot in without changing its callers.
*/
return new class extends Migration
{
    public function up(): void
    {
        // ---- spatie/laravel-permission, with uuid keys throughout ----------

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->string('model_type');
            $table->uuid('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->string('model_type');
            $table->uuid('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->uuid('role_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // ---- tenant memberships --------------------------------------------

        Schema::create('staff_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('staff_user_id');
            $table->foreign('staff_user_id')->references('id')->on('staff_users')->cascadeOnDelete();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('role', 20);

            /*
             * Who granted it. Nullable and ON DELETE SET NULL rather than
             * cascade: if the operator admin who granted a membership is ever
             * removed, every membership they granted must not vanish with
             * them — that would revoke a working moderator because of an
             * unrelated change, and lose the record of how they got access.
             */
            $table->uuid('granted_by')->nullable();
            $table->foreign('granted_by')->references('id')->on('staff_users')->nullOnDelete();

            $table->timestampTz('granted_at');

            /*
             * Revocation is a timestamp, not a delete. Who could moderate
             * which municipality and when is part of the audit record
             * (docs/03 FR-AUD-01), and a deleted row cannot answer that.
             */
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE staff_memberships
                ADD CONSTRAINT staff_memberships_role_check
                    CHECK (role IN ('moderator', 'verifier', 'data_editor', 'viewer')),
                ADD CONSTRAINT staff_memberships_revoked_after_granted
                    CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
            SQL);

        /*
         * One ACTIVE membership per person per municipality (docs/05 §9).
         *
         * Partial, on revoked_at IS NULL, so the history is unconstrained: a
         * moderator whose membership was revoked and later re-granted has two
         * rows, which is the truth about what happened, while only one of them
         * can be live at a time.
         *
         * This also removes the question "which of their two roles applies",
         * which is the kind of ambiguity a permission check should never have
         * to resolve at runtime.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX staff_memberships_one_active
                ON staff_memberships (staff_user_id, tenant_id)
                WHERE revoked_at IS NULL
            SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_memberships');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    /**
     * The application role needs these at runtime. Default privileges on the
     * template cover tables created by hw_owner, but granting again is free and
     * idempotent, and a missing grant only shows up on the first real request.
     */
    private function grantRuntimeRoles(): void
    {
        $tables = [
            'permissions',
            'roles',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'staff_memberships',
        ];

        foreach (['tenancy.roles.app' => 'ALL', 'tenancy.roles.backup' => 'SELECT'] as $key => $privilege) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            $grant = $privilege === 'ALL' ? 'SELECT, INSERT, UPDATE, DELETE' : 'SELECT';

            foreach ($tables as $table) {
                DB::unprepared(<<<SQL
                    DO \$\$
                    BEGIN
                        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                            EXECUTE 'GRANT {$grant} ON TABLE {$table} TO {$role}';
                        END IF;
                    END
                    \$\$;
                    SQL);
            }
        }
    }
};
