<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Test-only job: writes into whichever tenant database is active while it runs.
 */
final class WriteTenantProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $message) {}

    public function handle(): void
    {
        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => 'probe'],
            [
                'value' => json_encode(['message' => $this->message], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ],
        );
    }
}
