<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a marker into whichever municipality database is active while it runs.
 *
 * Used by `hw:doctor --deep` to prove the whole chain end to end: a job
 * dispatched in one container is picked up by the queue worker in another and
 * lands in the right database (docs/12 §9).
 */
final class TenancyProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        TenantSetting::query()->updateOrCreate(
            ['key' => 'diagnostics.probe'],
            [
                'value' => ['token' => $this->token, 'at' => now()->toIso8601String()],
                'reason' => 'hw:doctor --deep',
            ],
        );
    }
}
