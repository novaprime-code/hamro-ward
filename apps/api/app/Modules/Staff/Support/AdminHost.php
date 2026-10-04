<?php

declare(strict_types=1);

namespace App\Modules\Staff\Support;

use Illuminate\Http\Request;

/**
 * Whether a request arrived on the staff host. The host comes from
 * Request::getHost(), which honours X-Forwarded-Host only from trusted
 * proxies (bootstrap/app.php) — the web tier's rewrite in front of /api.
 */
final class AdminHost
{
    public function matches(Request $request): bool
    {
        $configured = strtolower(trim((string) config('staff.admin_host')));

        return $configured !== '' && strtolower($request->getHost()) === $configured;
    }
}
