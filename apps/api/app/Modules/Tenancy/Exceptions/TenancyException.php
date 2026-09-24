<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\Models\Tenant;
use RuntimeException;

final class TenancyException extends RuntimeException
{
    public static function notInitialized(string $model): self
    {
        return new self("Tenant model [{$model}] was used before a tenant was initialized.");
    }

    public static function inactive(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->tenant_key}] is {$tenant->status->value}, not active.");
    }

    public static function unknownTenant(string $id): self
    {
        return new self("Tenant [{$id}] does not exist.");
    }

    public static function invalidDatabaseName(string $name): self
    {
        return new self("Refusing to use invalid tenant database name [{$name}].");
    }

    public static function invalidKey(string $key): self
    {
        return new self("Invalid tenant key [{$key}]; expected 8 lowercase hex characters.");
    }

    public static function invalidIdentifier(string $identifier): self
    {
        return new self("Refusing to use invalid PostgreSQL identifier [{$identifier}].");
    }

    public static function databaseAlreadyExists(string $name): self
    {
        return new self("Tenant database [{$name}] already exists.");
    }

    public static function keyExhausted(): self
    {
        return new self('Could not generate a unique tenant key after 10 attempts.');
    }

    public static function immutableIdentity(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->getKey()}]: tenant_key and database_name cannot change.");
    }

    public static function dropNotAllowed(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->tenant_key}] must be archived before its database can be dropped.");
    }

    public static function migrationFailed(Tenant $tenant, string $output): self
    {
        return new self("Migrations failed for tenant [{$tenant->tenant_key}]: {$output}");
    }
}
