<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — administrative hierarchy (docs/05 §3.1, R1–R6).
|
| One table for every level. The database enforces:
|  - level-specific columns (local_level_type, ward_number) via CHECKs
|  - parent level = level directly above, via trigger
|  - level and parent never change after insert (restructures close the old
|    unit and open a new one, linked in admin_unit_lineage)
|  - a current unit never sits under a closed parent, and a unit with current
|    children cannot be closed
|  - ancestor_ids (root-first) maintained by the trigger, used by the
|    publication rule and tenant subtree replication
|
| The self-referencing foreign key is added after the table exists: Laravel
| appends fluent index commands (uuid('id')->primary()) after the other
| commands, so declaring the key inside Schema::create would run the ALTER
| before the primary key it points at.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level', 20);
            $table->uuid('parent_id')->nullable();
            $table->string('local_level_type', 30)->nullable();
            $table->smallInteger('ward_number')->nullable();
            $table->string('slug', 80);
            $table->string('name_ne', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->index('parent_id');
            $table->index(['level', 'is_published']);
        });

        Schema::table('admin_units', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('admin_units')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_units
                ADD COLUMN ancestor_ids uuid[] NOT NULL DEFAULT '{}',
                ADD CONSTRAINT admin_units_level_check
                    CHECK (level IN ('country', 'province', 'district', 'local_level', 'ward')),
                ADD CONSTRAINT admin_units_root_is_country
                    CHECK ((level = 'country') = (parent_id IS NULL)),
                ADD CONSTRAINT admin_units_local_level_type_check
                    CHECK ((level = 'local_level') = (local_level_type IS NOT NULL)
                       AND (local_level_type IS NULL OR local_level_type IN
                           ('metropolitan_city', 'sub_metropolitan_city', 'municipality', 'rural_municipality'))),
                ADD CONSTRAINT admin_units_ward_number_check
                    CHECK ((level = 'ward') = (ward_number IS NOT NULL)
                       AND (ward_number IS NULL OR ward_number BETWEEN 1 AND 99)),
                ADD CONSTRAINT admin_units_slug_format
                    CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT admin_units_ward_slug_is_number
                    CHECK (level <> 'ward' OR slug = ward_number::text),
                ADD CONSTRAINT admin_units_has_a_name
                    CHECK (name_ne IS NOT NULL OR name_en IS NOT NULL),
                ADD CONSTRAINT admin_units_validity_range
                    CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from),
                ADD CONSTRAINT admin_units_published_has_timestamp
                    CHECK (NOT is_published OR published_at IS NOT NULL)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_units_current_sibling_slug
                ON admin_units (parent_id, slug)
                WHERE valid_to IS NULL AND parent_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_units_current_root_slug
                ON admin_units (slug)
                WHERE valid_to IS NULL AND parent_id IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_units_one_current_country
                ON admin_units (level)
                WHERE level = 'country' AND valid_to IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_units_current_ward_number
                ON admin_units (parent_id, ward_number)
                WHERE level = 'ward' AND valid_to IS NULL
            SQL);

        DB::statement('CREATE INDEX admin_units_ancestor_ids ON admin_units USING gin (ancestor_ids)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION admin_units_enforce_hierarchy() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_row admin_units%ROWTYPE;
                expected_parent_level text;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.level <> OLD.level THEN
                        RAISE EXCEPTION 'admin_units %: level cannot change', OLD.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
                        RAISE EXCEPTION 'admin_units %: parent cannot change; close the unit and record lineage instead', OLD.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.valid_to IS NOT NULL AND OLD.valid_to IS NULL AND EXISTS (
                        SELECT 1 FROM admin_units child
                        WHERE child.parent_id = NEW.id AND child.valid_to IS NULL
                    ) THEN
                        RAISE EXCEPTION 'admin_units %: close its current children first', OLD.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    NEW.ancestor_ids := OLD.ancestor_ids;
                    RETURN NEW;
                END IF;

                IF NEW.parent_id IS NULL THEN
                    NEW.ancestor_ids := '{}';
                    RETURN NEW;
                END IF;

                expected_parent_level := CASE NEW.level
                    WHEN 'province' THEN 'country'
                    WHEN 'district' THEN 'province'
                    WHEN 'local_level' THEN 'district'
                    WHEN 'ward' THEN 'local_level'
                    ELSE NULL
                END;

                SELECT * INTO parent_row FROM admin_units WHERE id = NEW.parent_id;

                IF NOT FOUND THEN
                    RETURN NEW; -- the foreign key reports the missing parent
                END IF;

                IF parent_row.level IS DISTINCT FROM expected_parent_level THEN
                    RAISE EXCEPTION 'admin_units: a % needs a % parent, got %',
                        NEW.level, coalesce(expected_parent_level, 'no'), parent_row.level
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.valid_to IS NULL AND parent_row.valid_to IS NOT NULL THEN
                    RAISE EXCEPTION 'admin_units: a current unit cannot have a closed parent (%)', parent_row.id
                        USING ERRCODE = 'check_violation';
                END IF;

                NEW.ancestor_ids := parent_row.ancestor_ids || parent_row.id;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER admin_units_hierarchy
                BEFORE INSERT OR UPDATE ON admin_units
                FOR EACH ROW EXECUTE FUNCTION admin_units_enforce_hierarchy();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_units');
        DB::unprepared('DROP FUNCTION IF EXISTS admin_units_enforce_hierarchy()');
    }
};
