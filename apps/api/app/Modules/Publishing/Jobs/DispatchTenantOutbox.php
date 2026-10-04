<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Jobs;

use App\Modules\Publishing\Actions\IndexPersonPage;
use App\Modules\Publishing\Models\ProcessedOutboxEvent;
use App\Modules\Publishing\Support\CacheTags;
use App\Modules\Publishing\Support\TenantPath;
use App\Modules\Tenancy\Actions\RecordOutboxEvent;
use App\Modules\Tenancy\Models\OutboxEvent;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drains one municipality's outbox into the central indexes (docs/12 §4.3).
 *
 * For each pending event, applying it and recording it as processed share one
 * CENTRAL transaction; only then is the tenant row stamped. A crash after the
 * central commit leaves the tenant row pending, and the next run finds the
 * event in processed_outbox_events and only stamps it. Nothing is applied
 * twice and nothing is lost.
 *
 * Unique per tenant, so a burst of dispatches — one per import, one per
 * minute from the scheduler — cannot run two drains of the same outbox at once.
 */
final class DispatchTenantOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const BATCH = 500;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantManager $tenancy, IndexPersonPage $indexPersonPage): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $applied = 0;

        $tenancy->run($tenant, function () use ($indexPersonPage, &$applied): void {
            $central = DB::connection((string) config('tenancy.central_connection'));

            foreach (OutboxEvent::query()->pending()->limit(self::BATCH)->get() as $event) {
                $wasApplied = $central->transaction(function () use ($event, $indexPersonPage): bool {
                    if (ProcessedOutboxEvent::query()->whereKey($event->id)->exists()) {
                        return true;
                    }

                    if (! $this->apply($event, $indexPersonPage)) {
                        return false;
                    }

                    ProcessedOutboxEvent::query()->create([
                        'event_id' => $event->id,
                        'tenant_id' => $this->tenantId,
                        'event_type' => $event->event_type,
                    ]);

                    return true;
                });

                if ($wasApplied) {
                    $event->forceFill(['processed_at' => now()])->save();
                    $applied++;
                }
            }
        }, allowInactive: true);

        // Holdings changed outside an import — through RecordOfficeHolding or
        // EndOfficeHolding — reach the web tier this way. After an import this
        // repeats the import's own signal, which is harmless.
        $path = TenantPath::of($tenant);

        if ($applied > 0 && $path !== null) {
            DispatchRevalidation::dispatch([CacheTags::place($path), CacheTags::INDEX]);
        }
    }

    /**
     * An event type this code does not know stays pending rather than being
     * marked done: it was written by newer code, and newer code will apply it.
     */
    private function apply(OutboxEvent $event, IndexPersonPage $indexPersonPage): bool
    {
        if ($event->event_type === RecordOutboxEvent::HOLDING_CHANGED) {
            $personId = $event->payload['person_id'] ?? null;

            // Malformed, and retrying cannot fix it: a nightly rebuild covers
            // whichever page it was about.
            if (is_string($personId) && $personId !== '') {
                $indexPersonPage->handle($personId);
            }

            return true;
        }

        Log::warning('Outbox event of an unknown type left pending', [
            'tenant_id' => $this->tenantId,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return false;
    }
}
