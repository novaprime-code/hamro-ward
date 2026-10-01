<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — provenance for this local level's facts: ward offices, office
| holdings, issues, promises (docs/05 §4, 12 §3).
|
| source_types here is a read-only replica of the central table, kept in step by
| SyncTenantReferenceData. A source link points either at a local source
| (source_scope = 'tenant', enforced by a trigger) or at a national one in the
| central database (source_scope = 'central', checked by the application,
| because PostgreSQL cannot enforce foreign keys across databases).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_types', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->smallInteger('authority_rank')->unique();
            $table->string('label_ne', 120);
            $table->string('label_en', 120);
            $table->string('default_provenance_type', 40);
            $table->timestampTz('synced_at')->nullable();
        });

        Schema::create('sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type_key', 40);
            $table->string('title', 300);
            $table->string('publisher', 200)->nullable();
            $table->text('url')->nullable();
            $table->uuid('document_media_id')->nullable()->comment('media.id — FK added with the media table (HW-E12-F01)');
            $table->text('archive_url')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('language', 10)->nullable();
            $table->date('published_at')->nullable();
            $table->string('published_as_written', 120)->nullable();
            $table->timestampTz('retrieved_at');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->comment('central ref: staff_users.id');
            $table->timestampsTz();

            $table->foreign('source_type_key')->references('key')->on('source_types')->restrictOnDelete();
            $table->index('source_type_key');
            $table->index('published_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sources
                ADD CONSTRAINT sources_has_a_locator
                    CHECK (url IS NOT NULL OR document_media_id IS NOT NULL),
                ADD CONSTRAINT sources_url_scheme
                    CHECK (url IS NULL OR url ~ '^https?://'),
                ADD CONSTRAINT sources_language_check
                    CHECK (language IS NULL OR language IN ('ne', 'en', 'mixed', 'other'))
            SQL);

        Schema::create('source_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_scope', 10)->default('tenant');
            $table->uuid('source_id')->comment('sources.id in this database, or in central when source_scope = central');
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('field_path', 60)->nullable();
            $table->string('provenance_type', 40);
            $table->jsonb('asserted_value')->nullable();
            $table->string('locator', 200)->nullable();
            $table->string('excerpt', 300)->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('second_approved_by')->nullable();
            $table->text('evidence_note')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id', 'field_path']);
            $table->index('verification_status');
            $table->index(['source_scope', 'source_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE source_links
                ADD CONSTRAINT source_links_scope_check CHECK (source_scope IN ('central', 'tenant')),
                ADD CONSTRAINT source_links_subject_check
                    CHECK (subject_type IN (
                        'ward_office', 'office_holding', 'vacancy', 'issue', 'promise',
                        'candidacy', 'contest', 'project')),
                ADD CONSTRAINT source_links_provenance_check
                    CHECK (provenance_type IN (
                        'official', 'candidate_submitted', 'public_record', 'verified_community_report',
                        'community_report', 'media_report', 'ai_generated_summary', 'unverified_claim')),
                ADD CONSTRAINT source_links_verification_check
                    CHECK (verification_status IN ('unverified', 'verified', 'disputed', 'rejected')),
                ADD CONSTRAINT source_links_verified_has_timestamp
                    CHECK (verification_status <> 'verified' OR (verified_at IS NOT NULL AND verified_by IS NOT NULL)),
                ADD CONSTRAINT source_links_second_approver_differs
                    CHECK (second_approved_by IS NULL OR second_approved_by <> verified_by)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION source_links_local_source_exists() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.source_scope = 'tenant'
                   AND NOT EXISTS (SELECT 1 FROM sources WHERE id = NEW.source_id) THEN
                    RAISE EXCEPTION 'source_links: local source % does not exist in this tenant', NEW.source_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_links_local_source
                BEFORE INSERT OR UPDATE OF source_scope, source_id ON source_links
                FOR EACH ROW EXECUTE FUNCTION source_links_local_source_exists();
            SQL);

        Schema::create('fact_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('field_path', 60)->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('blocks_publication')->default(false);
            $table->text('resolution')->nullable();
            $table->jsonb('resolved_value')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id', 'field_path']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE fact_conflicts
                ADD CONSTRAINT fact_conflicts_status_check
                    CHECK (status IN ('open', 'resolved', 'dismissed')),
                ADD CONSTRAINT fact_conflicts_resolved_has_reason
                    CHECK (status <> 'resolved' OR (resolution IS NOT NULL AND resolved_at IS NOT NULL))
            SQL);

        Schema::create('fact_conflict_links', function (Blueprint $table): void {
            $table->foreignUuid('fact_conflict_id')->constrained('fact_conflicts')->cascadeOnDelete();
            $table->foreignUuid('source_link_id')->constrained('source_links')->cascadeOnDelete();

            $table->primary(['fact_conflict_id', 'source_link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_conflict_links');
        Schema::dropIfExists('fact_conflicts');
        Schema::dropIfExists('source_links');
        DB::unprepared('DROP FUNCTION IF EXISTS source_links_local_source_exists() CASCADE');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('source_types');
    }
};
