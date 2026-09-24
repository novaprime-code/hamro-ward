<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| TENANT migration — runs in every tenant database via hw:tenant:migrate.
| Per-tenant overrides of platform settings, e.g. issues.submissions_paused
| (FR-ISS-10) and issues.reporting_scope (FR-ACC-09).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->jsonb('value');
            $table->text('reason')->nullable();
            $table->uuid('updated_by')->nullable()->comment('central ref: staff_users.id');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
