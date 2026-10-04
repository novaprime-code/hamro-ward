<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Pg;
use App\Modules\Tenancy\Support\TenantDatabaseName;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Drops a tenant database. Outside local/testing the tenant must be archived
 * first (hw:tenant:archive, docs/12 §5) — there is no other path to deletion.
 */
final readonly class DropTenantDatabase
{
    /** Drops tried before a terminate refusal is taken as real. */
    private const DROP_ATTEMPTS = 5;

    /** 40 × 50 ms: two seconds at most, normally a single check. */
    private const CLOSE_WAIT_ATTEMPTS = 40;

    private const CLOSE_WAIT_MICROSECONDS = 50_000;

    public function __construct(private TenantManager $tenancy) {}

    public function handle(Tenant $tenant): void
    {
        if (! app()->environment(['local', 'testing']) && $tenant->status !== TenantStatus::Archived) {
            throw TenancyException::dropNotAllowed($tenant);
        }

        $this->force($tenant);
    }

    /**
     * Internal: also used to clean up after a failed CreateTenantDatabase.
     */
    public function force(Tenant $tenant): void
    {
        $name = $tenant->database_name;
        TenantDatabaseName::assertValid($name);

        if ($this->tenancy->current()?->is($tenant) === true) {
            $this->tenancy->end();
        }

        DB::purge((string) config('tenancy.tenant_connection'));
        DB::purge((string) config('tenancy.tenant_owner_connection'));

        $provisioner = DB::connection((string) config('tenancy.provisioner_connection'));

        for ($attempt = 1; ; $attempt++) {
            $this->awaitOwnSessionsClosed($provisioner, $name);

            try {
                $provisioner->statement('DROP DATABASE IF EXISTS '.Pg::ident($name).' WITH (FORCE)');

                return;
            } catch (QueryException $exception) {
                // Only the exiting-backend race is retried; any other refusal,
                // and this one once the attempts run out, is real.
                if ($attempt >= self::DROP_ATTEMPTS || ! $this->isTerminateRace($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /** 42501, raised by FORCE trying to signal a backend owned by another role. */
    private function isTerminateRace(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '42501'
            && str_contains($exception->getMessage(), 'terminate process');
    }

    /**
     * Closing a connection is asynchronous on the server: the client is gone,
     * but its backend can still be exiting when the next statement arrives.
     * WITH (FORCE) then tries to terminate that backend, and when it belongs to
     * the schema owner the provisioner has no right to signal it — the drop
     * fails with "permission denied to terminate process", at random, after
     * the connection was already closed. So wait, briefly, for the sessions
     * this process just closed to finish leaving.
     *
     * Bounded: anything still connected after that is somebody else's, and
     * FORCE deals with the ones it is allowed to. A backend can also leave
     * pg_stat_activity a moment before it stops answering signals, so a
     * refusal of exactly that kind is waited out and the drop retried.
     */
    private function awaitOwnSessionsClosed(Connection $provisioner, string $database): void
    {
        for ($attempt = 0; $attempt < self::CLOSE_WAIT_ATTEMPTS; $attempt++) {
            $remaining = (int) $provisioner->scalar(
                'SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );

            if ($remaining === 0) {
                return;
            }

            usleep(self::CLOSE_WAIT_MICROSECONDS);
        }
    }
}
