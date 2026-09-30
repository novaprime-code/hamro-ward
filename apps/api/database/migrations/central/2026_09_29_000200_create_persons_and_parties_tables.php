<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — persons and parties (docs/05 §5.2–5.3, R9–R11, FR-OFF-05,
| NFR-NEU-02).
|
| Both are central on purpose. A person can hold office in one local level,
| stand in another and be written about in a third; a party is national. Keeping
| one row per person and per party is what makes de-duplication, cross-tenant
| search and "the same person" possible at all. Office holdings stay in the
| tenant database and refer to these rows by id (D-014).
|
| Deliberately absent from persons: gender, caste, ethnicity, religion, date of
| birth and home address. None of them is needed to say who holds a seat, and
| holding them would turn a civic directory into a profiling database (R9).
|
| Deliberately absent from parties: colour. Rendering parties in their own
| colours makes every page look like a campaign poster and puts the platform's
| neutrality in the hands of a palette (NFR-NEU-02).
|
| Nothing here is published by default. is_published is raised only once a
| verified source link supports the record (FR-SRC-02).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120);
            $table->string('name_ne', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->string('abbreviation_ne', 40)->nullable();
            $table->string('abbreviation_en', 40)->nullable();
            $table->string('ecn_registration_ref', 80)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->index('is_published');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE parties
                ADD CONSTRAINT parties_slug_format
                    CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT parties_has_a_name
                    CHECK (name_ne IS NOT NULL OR name_en IS NOT NULL),
                ADD CONSTRAINT parties_validity_range
                    CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from),
                ADD CONSTRAINT parties_published_has_timestamp
                    CHECK (NOT is_published OR published_at IS NOT NULL)
            SQL);

        DB::statement('CREATE UNIQUE INDEX parties_current_slug ON parties (slug) WHERE valid_to IS NULL');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX parties_ecn_registration_ref
                ON parties (ecn_registration_ref) WHERE ecn_registration_ref IS NOT NULL
            SQL);

        Schema::create('party_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('script', 4);
            $table->string('normalized', 200);
            $table->timestampsTz();

            $table->unique(['party_id', 'normalized']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE party_aliases
                ADD CONSTRAINT party_aliases_script_check CHECK (script IN ('deva', 'latn'))
            SQL);

        DB::statement('CREATE INDEX party_aliases_normalized_trgm ON party_aliases USING gin (normalized gin_trgm_ops)');

        Schema::create('persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('full_name_ne', 200)->nullable();
            $table->string('full_name_en', 200)->nullable();
            $table->uuid('photo_media_id')->nullable()->comment('media.id — FK added with the media table (HW-E12-F01)');
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->uuid('merged_into_person_id')->nullable();
            $table->timestampsTz();

            $table->index('is_published');
            $table->index('merged_into_person_id');
        });

        /*
         * The self-reference is added AFTER the table exists, not inside the
         * Schema::create closure above, and that is not a style choice.
         *
         * Laravel appends the commands implied by fluent modifiers — the
         * primary key from uuid('id')->primary() — when the blueprint is
         * compiled, which is after everything the closure added. A foreign key
         * written inside the closure is therefore emitted BEFORE the primary
         * key it points at:
         *
         *   create table "persons" (…)
         *   alter table "persons" add constraint …_foreign foreign key … references "persons" ("id")
         *   alter table "persons" add primary key ("id")
         *
         * and PostgreSQL refuses the middle statement with
         *
         *   SQLSTATE[42830]: there is no unique constraint matching given keys
         *   for referenced table "persons"
         *
         * A separate Schema::table call is a separate blueprint, compiled and
         * executed after the create has finished, so the key is there.
         * (The tenant admin_units replica avoids the same trap by adding its
         * parent_id self-reference through DB::statement.)
         */
        Schema::table('persons', function (Blueprint $table): void {
            $table->foreign('merged_into_person_id')->references('id')->on('persons')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE persons
                ADD CONSTRAINT persons_slug_format
                    CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT persons_has_a_name
                    CHECK (full_name_ne IS NOT NULL OR full_name_en IS NOT NULL),
                ADD CONSTRAINT persons_published_has_timestamp
                    CHECK (NOT is_published OR published_at IS NOT NULL),
                ADD CONSTRAINT persons_not_merged_into_self
                    CHECK (merged_into_person_id IS NULL OR merged_into_person_id <> id),
                ADD CONSTRAINT persons_merged_is_not_published
                    CHECK (merged_into_person_id IS NULL OR NOT is_published)
            SQL);

        Schema::create('person_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('script', 4);
            $table->string('normalized', 200);
            $table->timestampsTz();

            $table->unique(['person_id', 'normalized']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE person_aliases
                ADD CONSTRAINT person_aliases_script_check CHECK (script IN ('deva', 'latn'))
            SQL);

        DB::statement('CREATE INDEX person_aliases_normalized_trgm ON person_aliases USING gin (normalized gin_trgm_ops)');

        /*
         * Merges are recorded, never inferred (docs/02 §7.2). Nepali names
         * repeat often enough that automatic matching would silently fuse two
         * different representatives into one. Two approvers, as with
         * verification (D-002).
         */
        Schema::create('person_merges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('kept_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignUuid('merged_person_id')->constrained('persons')->restrictOnDelete();
            $table->text('reason');
            $table->uuid('approved_by')->comment('central ref: staff_users.id');
            $table->uuid('second_approved_by')->nullable()->comment('central ref: staff_users.id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('merged_person_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE person_merges
                ADD CONSTRAINT person_merges_distinct_people
                    CHECK (kept_person_id <> merged_person_id),
                ADD CONSTRAINT person_merges_second_approver_differs
                    CHECK (second_approved_by IS NULL OR second_approved_by <> approved_by)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_merges');
        Schema::dropIfExists('person_aliases');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('party_aliases');
        Schema::dropIfExists('parties');
    }
};
