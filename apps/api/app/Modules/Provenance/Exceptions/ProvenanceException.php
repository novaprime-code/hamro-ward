<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Exceptions;

use App\Modules\Provenance\Enums\SourceScope;
use RuntimeException;

final class ProvenanceException extends RuntimeException
{
    public static function missingSource(SourceScope $scope, string $sourceId): self
    {
        return new self("No {$scope->value} source [{$sourceId}].");
    }

    public static function tenantScopeOutsideTenant(): self
    {
        return new self('A tenant-scoped source can only be checked while a tenant is initialized.');
    }

    public static function unknownSubjectType(string $subjectType, SourceScope $scope): self
    {
        return new self("Subject type [{$subjectType}] cannot be used in the {$scope->value} database.");
    }
}
