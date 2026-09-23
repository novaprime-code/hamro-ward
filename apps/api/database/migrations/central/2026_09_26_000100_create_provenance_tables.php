<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — provenance for central facts: geography, persons, parties
| (docs/05 §4, 12 §3). National documents (ECN, Government of Nepal) live here;
| local notices live in each tenant database with the same shape.
|
| source_types is the master copy; tenants hold a read-only replica.
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
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE source_types
                ADD CONSTRAINT source_types_rank_positive CHECK (authority_rank > 0),
                ADD CONSTRAINT source_types_provenance_check
                    CHECK (default_provenance_type IN (
                        'official', 'candidate_submitted', 'public_record', 'verified_community_report',
                        'community_report', 'media_report', 'ai_generated_summary', 'unverified_claim'))
            SQL);

        $this->createSourcesTable();
        $this->createSourceLinksTable();
        $this->createConflictTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_conflict_links');
        Schema::dropIfExists('fact_conflicts');
        Schema::dropIfExists('source_links');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('source_types');
    }

    private function createSourcesTable(): void
    {
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
            $table->string('published_as_written', 120)->nullable()->comment('e.g. a BS date exactly as printed (R14)');
            $table->timestampTz('retrieved_at');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->comment('staff_users.id (HW-E13-F01-T01); null for imports');
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
    }

    private function createSourceLinksTable(): void
    {
        Schema::create('source_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('sources')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('field_path', 60)->nullable()->comment('null = the whole record');
            $table->string('provenance_type', 40);
            $table->jsonb('asserted_value')->nullable()->comment('what this source says the value is');
            $table->string('locator', 200)->nullable()->comment('page, section or table row');
            $table->string('excerpt', 300)->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('second_approved_by')->nullable()->comment('restricted subjects need a second verifier (D-002)');
            $table->text('evidence_note')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id', 'field_path']);
            $table->index('verification_status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE source_links
                ADD CONSTRAINT source_links_subject_check
                    CHECK (subject_type IN ('admin_unit', 'admin_unit_name', 'admin_unit_code', 'person', 'party')),
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
    }

    private function createConflictTables(): void
    {
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
};
