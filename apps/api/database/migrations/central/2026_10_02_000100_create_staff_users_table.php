<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — staff accounts (docs/12 §11.1, §11.5, docs/05 §9).
|
| A separate table from `users`, and the separation is the point. The same
| person may be a citizen who reports a blocked drain and a moderator who
| reviews it. When they are, they hold two accounts with two passwords and two
| sessions on two hosts, and nothing in the schema links them. That is what
| keeps "who approved this" a different question from "who reported it", and
| what stops moderation privileges from following somebody into the account
| they file reports from.
|
| Central because staff may work for several municipalities, and because the
| D-002 affiliation and recusal checks have to span tenants (docs/12 §3).
|
| No `status` enum and no email verification here, unlike `users`:
|
|   * Staff accounts are created by an operator admin, so there is no
|     unverified state to represent — nobody self-registers (docs/12 §11.1),
|     and the admin host registers no registration route at all.
|   * `is_active` carries deactivation on its own. Someone who has left is
|     deactivated rather than deleted, because their past moderation decisions
|     reference them and an audit trail pointing at a missing actor is not an
|     audit trail (docs/03 FR-AUD-01).
|
| Two-factor is MANDATORY for staff (docs/12 §11.1). The columns are here; the
| enforcement is middleware on every staff endpoint (HW-E13-F01-T03), because a
| table cannot refuse to be read. What the schema can guarantee is that a
| confirmation never exists without a secret, which is the CHECK below.
|
| Roles, tenant memberships and the policy that reads them arrive in
| HW-E13-F01-T01.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);

            // citext, as on users: one address is one account whatever the
            // phone keyboard capitalised.
            $table->string('email')->unique();

            $table->string('password');

            /*
             * Encrypted at the application layer (the model casts both to
             * `encrypted`). A TOTP secret in the clear is a second factor that
             * anyone with a database dump also holds, which is not a second
             * factor.
             */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            $table->boolean('is_active')->default(true);

            // 5 failed logins → 15 minutes (docs/12 §11.5). Stored rather than
            // kept in the cache so a lockout survives a cache flush, which is
            // otherwise a one-command way to clear it.
            $table->timestampTz('locked_until')->nullable();

            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('is_active');
        });

        DB::statement('ALTER TABLE staff_users ALTER COLUMN email TYPE citext');

        DB::statement(<<<'SQL'
            ALTER TABLE staff_users
                ADD CONSTRAINT staff_users_name_length
                    CHECK (char_length(btrim(name)) BETWEEN 2 AND 80),
                ADD CONSTRAINT staff_users_email_shape
                    CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
                ADD CONSTRAINT staff_users_two_factor_confirmed_has_secret
                    CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL)
            SQL);

        /*
         * The staff password broker's token table.
         *
         * No route reaches it today: the admin host does not register
         * Fortify's forgot-password or reset-password routes, because a reset
         * form on the host that holds moderation is a larger surface than
         * asking an operator admin (docs/12 §11.1). The table exists because
         * ConfigureAuthForHost points `auth.defaults.passwords` at the
         * `staff_users` broker on that host, and a broker configured against a
         * table that does not exist fails at the moment it is first resolved
         * rather than at deploy time.
         *
         * Separate from `password_reset_tokens`: that table is keyed by email
         * alone, so sharing it would let a citizen's reset token be presented
         * for a staff account with the same address.
         */
        Schema::create('staff_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('ALTER TABLE staff_password_reset_tokens ALTER COLUMN email TYPE citext');

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_reset_tokens');
        Schema::dropIfExists('staff_users');
    }

    /**
     * The application role needs these at runtime. Default privileges on the
     * template cover tables created by hw_owner, but granting again is free and
     * idempotent, and a missing grant only shows up on the first real request.
     */
    private function grantRuntimeRoles(): void
    {
        foreach (['tenancy.roles.app' => 'ALL', 'tenancy.roles.backup' => 'SELECT'] as $key => $privilege) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            $grant = $privilege === 'ALL' ? 'SELECT, INSERT, UPDATE, DELETE' : 'SELECT';

            foreach (['staff_users', 'staff_password_reset_tokens'] as $table) {
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
