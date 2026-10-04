<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — changes this municipality owes the central indexes (docs/12 §4.3).
|
| Written in the SAME transaction as the change it describes, so the two
| commit or roll back together: an index can lag behind the data, but it can
| never be told about a change that did not happen, nor miss one that did.
| DispatchTenantOutbox drains it into central and stamps processed_at.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 60);
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE outbox_events
                ADD CONSTRAINT outbox_events_type_format CHECK (event_type ~ '^[a-z_]+(\.[a-z_]+)+$')
            SQL);

        // The dispatcher only ever asks for what is still pending.
        DB::statement('CREATE INDEX outbox_events_pending ON outbox_events (created_at) WHERE processed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
