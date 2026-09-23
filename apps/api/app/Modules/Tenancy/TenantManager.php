<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Events\TenancyEnded;
use App\Modules\Tenancy\Events\TenancyInitialized;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantDatabaseName;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;

/**
 * Points the "tenant" and "tenant_owner" connections at one tenant database
 * (D-013: thin in-house layer instead of a tenancy package).
 *
 * Request middleware (HW-E29-F03-T01), queued jobs (JobTenancy) and CLI
 * commands all go through this class; nothing else touches the tenant
 * connection configuration.
 */
final class TenantManager
{
    private ?Tenant $current = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
        private readonly Dispatcher $events,
    ) {}

    public function initialize(Tenant $tenant, bool $allowInactive = false): void
    {
        if (! $allowInactive && ! $tenant->isActive()) {
            throw TenancyException::inactive($tenant);
        }

        if ($this->current !== null && $this->current->is($tenant)) {
            return;
        }

        TenantDatabaseName::assertValid($tenant->database_name);

        if ($this->current !== null) {
            $this->end();
        }

        $this->pointTenantConnectionsAt($tenant->database_name);
        $this->current = $tenant;

        Context::add('tenant_key', $tenant->tenant_key);
        $this->events->dispatch(new TenancyInitialized($tenant));
    }

    public function end(): void
    {
        if ($this->current === null) {
            return;
        }

        $previous = $this->current;

        $this->pointTenantConnectionsAt(null);
        $this->current = null;

        Context::forget('tenant_key');
        $this->events->dispatch(new TenancyEnded($previous));
    }

    /**
     * Run a callback inside a tenant and restore whatever was active before.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, callable $callback, bool $allowInactive = false): mixed
    {
        $previous = $this->current;

        $this->initialize($tenant, $allowInactive);

        try {
            return $callback($tenant);
        } finally {
            if ($previous !== null) {
                $this->initialize($previous, allowInactive: true);
            } else {
                $this->end();
            }
        }
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function initialized(): bool
    {
        return $this->current !== null;
    }

    private function pointTenantConnectionsAt(?string $database): void
    {
        foreach ([
            (string) $this->config->get('tenancy.tenant_connection'),
            (string) $this->config->get('tenancy.tenant_owner_connection'),
        ] as $connection) {
            $this->config->set("database.connections.{$connection}.database", $database);
            $this->db->purge($connection);
        }
    }
}
