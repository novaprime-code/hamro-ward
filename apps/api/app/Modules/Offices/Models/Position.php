<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;

/**
 * The editable catalogue (docs/05 §5.1). Changing a row here changes what every
 * municipality's ward pages expect to exist, so edits go through a migration or
 * the seeder rather than an admin form, and reach tenants through
 * SyncTenantReferenceData.
 */
final class Position extends BasePosition
{
    use UsesCentralConnection;
}
