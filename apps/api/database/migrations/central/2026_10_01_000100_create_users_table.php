<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — citizen accounts (docs/12 §11.1, §12.1, D-011).
|
| Central rather than per-tenant, and that is the whole reason this table
| exists at all: one person reports a pothole where they live and a broken
| streetlight where they work, and those are two different municipalities with
| two different databases. An account per tenant would make them two different
| people (docs/12 §13).
|
| WHAT THIS TABLE DELIBERATELY DOES NOT HOLD, and must not grow:
|
|   street, tole, house number, GPS home location, citizenship or national ID
|   number, date of birth, phone number.
|
| A saved WARD is precise enough to route a report and keep the account useful
| (docs/12 §12.1). Anything finer turns a civic platform into an address
| registry of citizens who reported on their local government — which is a
| different and far more dangerous object, and one that a change of operator,
| a subpoena or a breach would hand to someone else entirely.
|
| There is no phone column because there is no SMS login in v0–v1 (docs/12
| §11.3): a phone number in Nepal is SIM-linked identity, and collecting one
| to send a verification code means holding it forever.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('display_name', 60);

            /*
             * citext, so Nabin@example.com and nabin@example.com are one
             * account rather than two. Case-folding in the application instead
             * would work until the one code path that forgets.
             */
            $table->string('email')->unique();

            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('preferred_locale', 5)->default('ne');

            // Optional for citizens in v1.0; mandatory for staff, who are a
            // separate table on a separate guard (docs/12 §11.1).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            $table->string('status', 20)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('status');
        });

        // citext comes from the template database (D-013), so the column can be
        // converted rather than the application lower-casing on every read.
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_display_name_length
                    CHECK (char_length(btrim(display_name)) BETWEEN 2 AND 60),
                ADD CONSTRAINT users_email_shape
                    CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
                ADD CONSTRAINT users_locale_check
                    CHECK (preferred_locale IN ('ne', 'en')),
                ADD CONSTRAINT users_status_check
                    CHECK (status IN ('active', 'locked', 'deleted_pending')),
                ADD CONSTRAINT users_two_factor_confirmed_has_secret
                    CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL)
            SQL);

        /*
         * Password reset tokens. Keyed by email rather than by user id, because
         * the request arrives before anyone is identified, and the response has
         * to look identical whether or not the address exists (docs/12 §11.3).
         */
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('ALTER TABLE password_reset_tokens ALTER COLUMN email TYPE citext');

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
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

            DB::unprepared(<<<SQL
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                        EXECUTE format('GRANT {$grant} ON TABLE users, password_reset_tokens TO %I', '{$role}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }
};
