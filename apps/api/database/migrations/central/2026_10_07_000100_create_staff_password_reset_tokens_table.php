<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — password reset tokens for staff, separate from citizens'
| (docs/12 §11.1). Fortify's reset flow uses the broker the host selects
| (`staff_users` on the admin host), and a token issued to one kind of
| account must never be redeemable as the other.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('ALTER TABLE staff_password_reset_tokens ALTER COLUMN email TYPE citext');

        foreach (['tenancy.roles.app' => 'SELECT, INSERT, UPDATE, DELETE', 'tenancy.roles.backup' => 'SELECT'] as $key => $grant) {
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
                        EXECUTE format('GRANT {$grant} ON TABLE staff_password_reset_tokens TO %I', '{$role}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_reset_tokens');
    }
};
