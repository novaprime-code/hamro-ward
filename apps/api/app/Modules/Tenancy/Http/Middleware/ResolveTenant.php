<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Points the tenant connections at the municipality named in the URL
 * (HW-E29-F03-T01, docs/12 §5).
 *
 * Every public URL carries its own address: /koshi/sunsari/koshara/4 says which
 * province, district, local level and ward. The local level segment is what
 * selects the database, and it is resolved from the CENTRAL slug table rather
 * than trusted from the path — so a request for a municipality that does not
 * exist, is not published, or has no tenant gets a 404 before a single tenant
 * query runs.
 *
 * Three refusals, each deliberate:
 *
 *  - unknown or unpublished path → 404. An unpublished municipality must be
 *    indistinguishable from one that does not exist; "exists but hidden" leaks
 *    the import queue.
 *  - no tenant yet → 404, for the same reason.
 *  - tenant in maintenance or suspended → 503 with Retry-After, because that
 *    municipality genuinely does exist and will come back. A 404 there would
 *    tell search engines to drop its pages.
 *
 * The tenant context is ended after the response so that a queued job or a
 * terminable middleware cannot inherit it by accident.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $localLevel = $this->localLevelFrom($request);
        $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->first();

        if ($tenant === null) {
            throw new NotFoundHttpException('That municipality is not on Hamro Ward yet.');
        }

        if (!$tenant->isActive()) {
            throw new ServiceUnavailableHttpException(
                300,
                'This municipality is temporarily unavailable while its data is being updated.',
            );
        }

        $this->tenancy->initialize($tenant);

        // Downstream controllers need the central row as well as the connection:
        // the ward page prints the municipality's name, which lives in central.
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('localLevel', $localLevel);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenancy->end();
    }

    /**
     * Resolves province/district/local-level to a published, current local
     * level. Only the current slug path resolves here; the older paths kept for
     * 301 redirects (FR-GEO-05) are handled by the redirect route, not by
     * silently serving the new content at an old address.
     */
    private function localLevelFrom(Request $request): AdminUnit
    {
        $path = implode('/', array_map(
            static fn (mixed $segment): string => (string) $segment,
            [
                $request->route('province'),
                $request->route('district'),
                $request->route('local_level'),
            ],
        ));

        $slug = AdminUnitSlug::query()
            ->where('slug_path', $path)
            ->where('is_current', true)
            ->first();

        $localLevel = $slug === null
            ? null
            : AdminUnit::query()
                ->publiclyVisible()
                ->where('level', AdminLevel::LocalLevel->value)
                ->whereKey($slug->admin_unit_id)
                ->first();

        if ($localLevel === null) {
            throw new NotFoundHttpException("No municipality at /{$path}.");
        }

        return $localLevel;
    }
}
