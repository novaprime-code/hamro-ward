<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — name history, slug paths, aliases, external codes, lineage
| (docs/05 §3.2, R3–R5). ward_offices is a TENANT table and arrives with the
| tenant geography replica (HW-E29-F02-T02).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_unit_names', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_unit_id')->constrained('admin_units')->cascadeOnDelete();
            $table->string('name_ne', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->index(['admin_unit_id', 'valid_to']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_unit_names
                ADD CONSTRAINT admin_unit_names_has_a_name CHECK (name_ne IS NOT NULL OR name_en IS NOT NULL),
                ADD CONSTRAINT admin_unit_names_validity_range CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_unit_names_one_current
                ON admin_unit_names (admin_unit_id) WHERE valid_to IS NULL
            SQL);

        Schema::create('admin_unit_slugs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_unit_id')->constrained('admin_units')->cascadeOnDelete();
            $table->string('slug_path', 400)->unique();
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_unit_slugs
                ADD CONSTRAINT admin_unit_slugs_path_format
                    CHECK (slug_path ~ '^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*){0,3}$')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_unit_slugs_one_current
                ON admin_unit_slugs (admin_unit_id) WHERE is_current
            SQL);

        Schema::create('admin_unit_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_unit_id')->constrained('admin_units')->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('script', 4);
            $table->string('kind', 20);
            $table->string('normalized', 200);
            $table->timestampsTz();

            $table->unique(['admin_unit_id', 'normalized']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_unit_aliases
                ADD CONSTRAINT admin_unit_aliases_script_check CHECK (script IN ('deva', 'latn')),
                ADD CONSTRAINT admin_unit_aliases_kind_check
                    CHECK (kind IN ('legacy', 'variant', 'misspelling', 'abbreviation'))
            SQL);

        DB::statement('CREATE INDEX admin_unit_aliases_normalized_trgm ON admin_unit_aliases USING gin (normalized gin_trgm_ops)');

        Schema::create('admin_unit_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_unit_id')->constrained('admin_units')->cascadeOnDelete();
            $table->string('scheme', 30);
            $table->string('code', 50);
            $table->timestampsTz();

            $table->unique(['scheme', 'code']);
            $table->unique(['admin_unit_id', 'scheme']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_unit_codes
                ADD CONSTRAINT admin_unit_codes_scheme_check
                    CHECK (scheme IN ('cbs_census_2021', 'ecn', 'mofaga', 'survey_dept', 'legacy_province_no'))
            SQL);

        Schema::create('admin_unit_lineage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('predecessor_id')->constrained('admin_units')->restrictOnDelete();
            $table->foreignUuid('successor_id')->constrained('admin_units')->restrictOnDelete();
            $table->string('event', 30);
            $table->date('effective_date');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['predecessor_id', 'successor_id', 'event']);
            $table->index('successor_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_unit_lineage
                ADD CONSTRAINT admin_unit_lineage_distinct CHECK (predecessor_id <> successor_id),
                ADD CONSTRAINT admin_unit_lineage_event_check
                    CHECK (event IN ('restructure_2017', 'merge', 'split', 'rename', 'type_change'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_unit_lineage');
        Schema::dropIfExists('admin_unit_codes');
        Schema::dropIfExists('admin_unit_aliases');
        Schema::dropIfExists('admin_unit_slugs');
        Schema::dropIfExists('admin_unit_names');
    }
};
