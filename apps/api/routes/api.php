<?php

declare(strict_types=1);

use App\Modules\Geography\Http\Controllers\LocalLevelController;
use App\Modules\Offices\Http\Controllers\WardController;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
| Read-only and unauthenticated: everything here is information a citizen may
| see without signing in, which is most of the platform (§9).
|
| The URL carries its own address — province/district/local-level/ward — and
| ResolveTenant turns the local-level segment into a database connection before
| any handler runs. Routes without that middleware never touch a tenant.
|
| Versioned from the first endpoint, because the Next.js app and, later, the
| shared civic cards are separate deployables that will not update in step.
*/

Route::prefix('v1')->group(function (): void {
    // Liveness for the proxy and the web app's status line. No tenant, no
    // database beyond a connection check.
    Route::get('/health', fn (): array => ['status' => 'ok']);

    // Central only: the list a visitor picks from.
    Route::get('/local-levels', [LocalLevelController::class, 'index']);

    Route::middleware(ResolveTenant::class)->group(function (): void {
        Route::get(
            '/local-levels/{province}/{district}/{local_level}',
            [LocalLevelController::class, 'show'],
        );

        Route::get(
            '/wards/{province}/{district}/{local_level}/{ward}',
            [WardController::class, 'show'],
        )->whereNumber('ward');
    });
});
