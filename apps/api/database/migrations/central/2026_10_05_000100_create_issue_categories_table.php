<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — what a citizen can report about (docs/05 §6.1, FR-ISS-02).
|
| A national catalogue, like positions: one list for every municipality, so a
| "drainage" report means the same thing in Koshi as in Sudurpashchim. Edited
| through IssueCategorySeeder, replicated read-only into each tenant by
| SyncTenantReferenceData so that issues.category_key can be a real foreign key.
|
| A category is retired with is_active = false, never deleted: reports already
| filed under it keep their meaning, and the replica sync refuses a delete that
| an issue still points at.
|
| Also makes the first four characters of tenant_key unique. Issue public ids
| carry them as a prefix ({tenant_key[0:4]}-{id}, docs/12 §6) so that a URL
| names its municipality without a global lookup; two tenants sharing a prefix
| would make that ambiguous.
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
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE issue_categories
                ADD CONSTRAINT issue_categories_key_format CHECK (key ~ '^[a-z][a-z0-9_]*$'),
                ADD CONSTRAINT issue_categories_sort_range CHECK (sort BETWEEN 1 AND 999)
            SQL);

        DB::statement('CREATE UNIQUE INDEX tenants_key_prefix_unique ON tenants (left(tenant_key, 4))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenants_key_prefix_unique');
        Schema::dropIfExists('issue_categories');
    }
};
