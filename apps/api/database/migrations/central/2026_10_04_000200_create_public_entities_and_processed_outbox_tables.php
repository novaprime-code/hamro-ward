<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — the cross-tenant index of public pages, and the ledger that makes
| filling it idempotent (docs/05 §9, docs/12 §4.3, HW-E29-F03-T02).
|
| public_entities answers "which pages exist?" for the sitemap and published
| paths without opening any municipality's database per request. Its first
| entity type is the person page, which is the one whose existence depends on
| tenant data: a person page lives at a municipality's address and exists only
| while that person holds a seat there (D-020).
|
| The unique key includes tenant_id, which docs/05 §9 does not: one person
| sitting in two municipalities has two pages, one under each address. NULLS
| NOT DISTINCT keeps the key working for central rows, whose tenant_id is null.
|
| processed_outbox_events records every tenant outbox event already applied
| here. Applying an event and recording it share one central transaction, so a
| crash between that commit and the tenant marking its row processed leaves an
| event that is skipped the next time, not applied twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->uuid('entity_id');
            $table->string('path_ne', 400);
            $table->string('path_en', 400);
            $table->string('title_ne', 300)->nullable();
            $table->string('title_en', 300)->nullable();
            $table->timestampTz('lastmod');
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'entity_type', 'is_published']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE public_entities
                ADD CONSTRAINT public_entities_type_check CHECK (entity_type IN ('person')),
                ADD CONSTRAINT public_entities_has_a_title CHECK (title_ne IS NOT NULL OR title_en IS NOT NULL),
                ADD CONSTRAINT public_entities_one_per_address
                    UNIQUE NULLS NOT DISTINCT (entity_type, entity_id, tenant_id)
            SQL);

        Schema::create('processed_outbox_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_outbox_events');
        Schema::dropIfExists('public_entities');
    }
};
