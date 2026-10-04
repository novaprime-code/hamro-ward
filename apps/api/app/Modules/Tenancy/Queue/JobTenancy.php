<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queue;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Jobs dispatched inside a tenant carry its id in the payload
 * (see TenancyServiceProvider) and run inside that tenant again.
 *
 * A stack keeps the caller's tenant intact when jobs run synchronously
 * inside a request or command.
 */
final class JobTenancy
{
    public const PAYLOAD_KEY = 'hw_tenant_id';

    /**
     * @var list<Tenant|null>
     */
    private array $stack = [];

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @return array<string, string>
     */
    public function payload(): array
    {
        $tenant = $this->tenancy->current();

        return $tenant === null ? [] : [self::PAYLOAD_KEY => (string) $tenant->getKey()];
    }

    public function begin(JobProcessing $event): void
    {
        $this->stack[] = $this->tenancy->current();

        $tenantId = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if ($tenantId === null) {
            $this->tenancy->end();

            return;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw TenancyException::unknownTenant((string) $tenantId);
        }

        $this->tenancy->initialize($tenant);
    }

    public function finish(): void
    {
        $previous = array_pop($this->stack);

        if ($previous instanceof Tenant) {
            $this->tenancy->initialize($previous, allowInactive: true);
        } else {
            $this->tenancy->end();
        }
    }
}
