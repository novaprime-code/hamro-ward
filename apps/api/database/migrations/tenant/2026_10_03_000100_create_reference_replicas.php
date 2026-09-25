<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — read-only replicas of central reference data (docs/05 §12, docs/12 §9).
|
| PostgreSQL cannot join across databases, so anything a tenant query needs in a
| WHERE or a JOIN has to exist inside the tenant database. Three kinds of data
| qualify:
|
|   source_types      created with the provenance tables (HW-E04-F01-T01)
|   positions         the seat catalogue, below
|   admin_units       this local level's slice of the hierarchy, below
|
| Every row here is written by SyncTenantReferenceData and by nothing else. The
| central table stays the only place a human edits. These tables therefore carry
| no triggers and no business rules: the central schema already enforced them,
| and re-enforcing them here would mean a rejected replication rather than a
| rejected edit.
|
| The geography replica is a SUBTREE, not a copy: country, province, district,
| this local level and its wards. A municipality database never learns the names
| of the other 752.
|
| bs_calendar_months and issue_categories join this file's pattern when their
| central tables arrive (HW-E03-F01 follow-up, HW-E11-F01-T01).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->string('title_ne', 120);
            $table->string('title_en', 120);
            $table->string('body', 30);
            $table->string('constituency_level', 20);
            $table->string('seat_category', 20);
            $table->string('election_method', 10);
            $table->string('appointment_type', 20);
            $table->jsonb('seats_per_constituency');
            $table->smallInteger('ballot_order')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampTz('synced_at')->nullable();

            $table->index(['constituency_level', 'ballot_order']);
        });

        DB::statement("ALTER TABLE positions ADD COLUMN applies_to_local_level_types text[] NOT NULL DEFAULT '{}'");

        Schema::create('admin_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level', 20);
            $table->uuid('parent_id')->nullable();
            $table->string('local_level_type', 30)->nullable();
            $table->smallInteger('ward_number')->nullable();
            $table->string('slug', 80);
            $table->string('slug_path', 400)->nullable();
            $table->string('name_ne', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampTz('synced_at')->nullable();

            $table->index('parent_id');
            $table->index(['level', 'is_published']);
            $table->index('slug_path');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE admin_units
                ADD COLUMN ancestor_ids uuid[] NOT NULL DEFAULT '{}',
                ADD CONSTRAINT admin_units_level_check
                    CHECK (level IN ('country', 'province', 'district', 'local_level', 'ward'))
            SQL);

        /*
         * The self foreign key is deferrable because the sync writes the
         * subtree in one transaction and a whole-subtree refresh deletes and
         * re-inserts; ordering rows inside the transaction would otherwise be
         * the replicator's problem rather than the database's.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE admin_units
                ADD CONSTRAINT admin_units_parent_id_foreign
                    FOREIGN KEY (parent_id) REFERENCES admin_units (id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED
            SQL);

        DB::statement('CREATE INDEX admin_units_ancestor_ids ON admin_units USING gin (ancestor_ids)');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_units');
        Schema::dropIfExists('positions');
    }
};
