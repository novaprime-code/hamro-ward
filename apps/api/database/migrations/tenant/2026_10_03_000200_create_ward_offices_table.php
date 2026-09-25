<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — where the ward office is and how to reach it (docs/05 §3.2,
| FR-GEO-08, FR-SRC-02).
|
| This is the single most-used fact on a ward page and the one most often wrong
| on the open web, so every displayable field needs its own source link. The
| table holds the values; source_links.field_path decides which of them the
| public sees (HW-E04-F02-T01).
|
| It lives in the tenant because it hangs off the tenant's geography replica and
| is maintained by that municipality's editors.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ward_offices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ward_id')->unique();
            $table->string('address_ne', 300)->nullable();
            $table->string('address_en', 300)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('alternate_phone', 60)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('office_hours_ne', 200)->nullable();
            $table->string('office_hours_en', 200)->nullable();
            $table->timestampsTz();

            $table->foreign('ward_id')->references('id')->on('admin_units')->cascadeOnDelete();
        });

        // geography(Point, 4326) has no Blueprint helper that keeps the geography type.
        DB::statement('ALTER TABLE ward_offices ADD COLUMN location geography(Point, 4326)');
        DB::statement('CREATE INDEX ward_offices_location ON ward_offices USING gist (location)');

        DB::statement(<<<'SQL'
            ALTER TABLE ward_offices
                ADD CONSTRAINT ward_offices_email_format
                    CHECK (email IS NULL OR email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
                ADD CONSTRAINT ward_offices_phone_digits
                    CHECK (phone IS NULL OR phone ~ '^[0-9+][0-9 +()-]{4,}$'),
                ADD CONSTRAINT ward_offices_alternate_phone_digits
                    CHECK (alternate_phone IS NULL OR alternate_phone ~ '^[0-9+][0-9 +()-]{4,}$')
            SQL);

        /*
         * A ward office belongs to a ward. Without this an editor could attach
         * one to the municipality and every ward page would inherit it.
         */
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION ward_offices_subject_is_a_ward() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                unit_level text;
            BEGIN
                SELECT level INTO unit_level FROM admin_units WHERE id = NEW.ward_id;

                IF NOT FOUND THEN
                    RETURN NEW; -- the foreign key reports the missing unit
                END IF;

                IF unit_level <> 'ward' THEN
                    RAISE EXCEPTION 'ward_offices: ward_id must point at a ward, got a %', unit_level
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER ward_offices_is_a_ward
                BEFORE INSERT OR UPDATE OF ward_id ON ward_offices
                FOR EACH ROW EXECUTE FUNCTION ward_offices_subject_is_a_ward();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ward_offices');
        DB::unprepared('DROP FUNCTION IF EXISTS ward_offices_subject_is_a_ward()');
    }
};
