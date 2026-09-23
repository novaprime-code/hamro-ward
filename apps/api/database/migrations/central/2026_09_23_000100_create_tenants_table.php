<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tenant registry (docs/12 §2, 05 §9). One row per onboarded local level.
| The foreign key to admin_units is added by the admin_units migration
| (HW-E03-F01-T01), which runs after this one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_unit_id')->unique()
                ->comment('Local level in admin_units (FK added in HW-E03-F01-T01)');
            $table->char('tenant_key', 8)->unique();
            $table->string('database_name', 63)->unique();
            $table->string('status', 20)->default('provisioning')->index();
            $table->string('schema_version')->nullable()
                ->comment('Last tenant migration applied');
            $table->integer('reference_version')->default(0)
                ->comment('Version of reference data replicated into the tenant');
            $table->timestampTz('onboarded_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_status_check
                    CHECK (status IN ('provisioning', 'active', 'maintenance', 'suspended', 'archived')),
                ADD CONSTRAINT tenants_key_format
                    CHECK (tenant_key ~ '^[0-9a-f]{8}$'),
                ADD CONSTRAINT tenants_database_matches_key
                    CHECK (right(database_name, 8) = tenant_key),
                ADD CONSTRAINT tenants_reference_version_nonnegative
                    CHECK (reference_version >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
