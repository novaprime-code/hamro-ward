<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| CENTRAL — every tenant is exactly one local level (D-010, docs/12 §2).
| Completes the tenants table created in HW-E29-F01-T02.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_admin_unit_fk
                    FOREIGN KEY (admin_unit_id) REFERENCES admin_units (id) ON DELETE RESTRICT
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION tenants_require_local_level() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT level FROM admin_units WHERE id = NEW.admin_unit_id) IS DISTINCT FROM 'local_level' THEN
                    RAISE EXCEPTION 'tenants: admin unit % is not a local level', NEW.admin_unit_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER tenants_local_level
                BEFORE INSERT OR UPDATE OF admin_unit_id ON tenants
                FOR EACH ROW EXECUTE FUNCTION tenants_require_local_level();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tenants_local_level ON tenants;
            DROP FUNCTION IF EXISTS tenants_require_local_level();
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_admin_unit_fk;
            SQL);
    }
};
