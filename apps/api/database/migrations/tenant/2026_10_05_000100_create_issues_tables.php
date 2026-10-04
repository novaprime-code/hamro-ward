<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — citizen reports (docs/05 §6, docs/12 §12.3, HW-E11-F01-T01).
|
| A report lives in the database of the municipality it is about, like every
| other fact about that municipality: a tenant export carries its issues, and
| a tenant's database never holds another's.
|
| Two states, deliberately separate (docs/05 §6.2):
|   moderation_state  may the public see this report?
|   lifecycle_status  what has happened to the problem it describes?
| A resolved problem can still be a rejected report, and an approved report can
| be about a problem nobody has touched. Folding them into one column is how a
| moderator's decision ends up looking like a municipality's response.
|
| Who reported it is kept to the minimum that moderation needs. reporter_user_id
| names a central account but has no foreign key — PostgreSQL cannot reference
| across databases — and is nulled after resolution or on account deletion
| (NFR-PRV-03, NFR-PRV-05). reporter_relationship is a snapshot for moderators
| and is never public. There is no name, email or phone here at all.
|
| issue_media waits for the media table (HW-E12); issue_confirmations (v0.4)
| and content_flags (v0.3) wait for their versions.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_categories', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->string('label_ne', 80);
            $table->string('label_en', 80);
            $table->string('icon', 40);
            $table->smallInteger('sort');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('synced_at')->nullable();
        });

        Schema::create('issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_id', 15)->unique();
            $table->uuid('ward_id');
            $table->string('category_key', 40);
            $table->text('title');
            $table->text('description');
            $table->string('language', 5);
            $table->string('location_public_precision', 15)->default('approximate');
            $table->text('location_text')->nullable();
            $table->string('moderation_state', 20)->default('pending');
            $table->string('lifecycle_status', 30)->default('open');
            $table->integer('confirmations_count')->default(0);
            $table->uuid('reporter_user_id')->nullable();
            $table->string('reporter_relationship', 20);
            $table->date('reporter_link_purge_after')->nullable();
            $table->string('client_fingerprint', 128);
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->foreign('ward_id')->references('id')->on('admin_units')->restrictOnDelete();
            $table->foreign('category_key')->references('key')->on('issue_categories')->restrictOnDelete();

            $table->index(['ward_id', 'moderation_state', 'lifecycle_status']);
            $table->index(['reporter_user_id', 'submitted_at']);
            $table->index(['client_fingerprint', 'submitted_at']);
        });

        DB::statement('ALTER TABLE issues ADD COLUMN location geography(Point, 4326)');
        DB::statement('CREATE INDEX issues_location ON issues USING gist (location)');

        DB::statement(<<<'SQL'
            ALTER TABLE issues
                ADD CONSTRAINT issues_public_id_format
                    CHECK (public_id ~ '^[0-9a-f]{4}-[0-9A-HJKMNP-TV-Z]{10}$'),
                ADD CONSTRAINT issues_title_length
                    CHECK (char_length(title) BETWEEN 5 AND 120),
                ADD CONSTRAINT issues_description_length
                    CHECK (char_length(description) BETWEEN 10 AND 2000),
                ADD CONSTRAINT issues_language_check
                    CHECK (language IN ('ne', 'en', 'other')),
                ADD CONSTRAINT issues_location_precision_check
                    CHECK (location_public_precision IN ('exact', 'approximate', 'ward_only')),
                ADD CONSTRAINT issues_moderation_state_check
                    CHECK (moderation_state IN ('pending', 'approved', 'rejected', 'needs_review', 'flagged', 'archived')),
                ADD CONSTRAINT issues_lifecycle_status_check
                    CHECK (lifecycle_status IN ('open', 'community_confirmed', 'reported_to_authority', 'acknowledged', 'in_progress', 'resolved')),
                ADD CONSTRAINT issues_reporter_relationship_check
                    CHECK (reporter_relationship IN ('permanent_address', 'temporary_address', 'workplace', 'other', 'visitor')),
                ADD CONSTRAINT issues_confirmations_non_negative
                    CHECK (confirmations_count >= 0),
                ADD CONSTRAINT issues_approved_has_published_at
                    CHECK (moderation_state <> 'approved' OR published_at IS NOT NULL)
            SQL);

        /*
         * A report is about a ward, not about the municipality or the
         * district: the ward is what routes it to the people who answer for it.
         * The replica carries level, so the database can hold that line.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION issues_validate_ward() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM admin_units WHERE id = NEW.ward_id AND level = 'ward') THEN
                    RAISE EXCEPTION 'issues: ward_id % is not a ward of this municipality', NEW.ward_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER issues_ward_is_ward
                BEFORE INSERT OR UPDATE OF ward_id ON issues
                FOR EACH ROW EXECUTE FUNCTION issues_validate_ward();
            SQL);

        Schema::create('issue_status_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('issue_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note_ne')->nullable();
            $table->text('note_en')->nullable();
            $table->uuid('source_id')->nullable();
            // Central staff_users.id (HW-E13-F01-T01); no cross-database foreign key.
            $table->uuid('actor_staff_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('issue_id')->references('id')->on('issues')->restrictOnDelete();
            $table->foreign('source_id')->references('id')->on('sources')->restrictOnDelete();
            $table->index(['issue_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE issue_status_events
                ADD CONSTRAINT issue_status_events_status_check
                    CHECK (to_status IN ('open', 'community_confirmed', 'reported_to_authority', 'acknowledged', 'in_progress', 'resolved')
                       AND (from_status IS NULL OR from_status IN ('open', 'community_confirmed', 'reported_to_authority', 'acknowledged', 'in_progress', 'resolved'))),
                ADD CONSTRAINT issue_status_events_is_a_change
                    CHECK (from_status IS DISTINCT FROM to_status)
            SQL);

        /*
         * The public timeline (FR-ISS-08) is a record of what was said and
         * when. Correcting it means adding an event, never editing one.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION issue_status_events_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'issue_status_events is append-only: % is not allowed', TG_OP;
            END
            $$;

            CREATE TRIGGER issue_status_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON issue_status_events
                FOR EACH ROW EXECUTE FUNCTION issue_status_events_append_only();

            CREATE TRIGGER issue_status_events_no_truncate
                BEFORE TRUNCATE ON issue_status_events
                FOR EACH STATEMENT EXECUTE FUNCTION issue_status_events_append_only();
            SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_status_events');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('issue_categories');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS issue_status_events_append_only() CASCADE;
            DROP FUNCTION IF EXISTS issues_validate_ward() CASCADE;
            SQL);
    }

    /**
     * Default privileges hand the application role full DML on every new
     * table. Two of these need less: the category replica is written only by
     * the sync (owner role), and the status timeline is append-only.
     */
    private function grantRuntimeRoles(): void
    {
        $grants = [
            'tenancy.roles.app' => [
                'issues' => 'SELECT, INSERT, UPDATE',
                'issue_status_events' => 'SELECT, INSERT',
                'issue_categories' => 'SELECT',
            ],
            'tenancy.roles.backup' => [
                'issues' => 'SELECT',
                'issue_status_events' => 'SELECT',
                'issue_categories' => 'SELECT',
            ],
        ];

        foreach ($grants as $key => $tables) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            foreach ($tables as $table => $grant) {
                DB::unprepared(<<<SQL
                    DO \$\$
                    BEGIN
                        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                            EXECUTE format('REVOKE ALL ON TABLE {$table} FROM %I', '{$role}');
                            EXECUTE format('GRANT {$grant} ON TABLE {$table} TO %I', '{$role}');
                        END IF;
                    END
                    \$\$;
                    SQL);
            }
        }
    }
};
