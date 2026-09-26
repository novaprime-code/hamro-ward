<?php

declare(strict_types=1);

use App\Modules\Geography\Http\Controllers\LocalLevelController;
use App\Modules\Offices\Http\Controllers\WardController;
use App\Modules\Support\Http\Controllers\HealthController;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Prefix "api/v1" is applied in bootstrap/app.php.
| Routes are grouped per module as modules arrive (docs/06 §16).
|
| Everything here is read-only and unauthenticated: this is information a
| citizen may see without signing in, which is most of the platform (§9).
|
| The URL carries its own address — province/district/local-level/ward — and
| ResolveTenant turns the local-level segment into a database connection
| before any handler runs. Routes outside that group never touch a tenant.
*/

// ---- Support ---------------------------------------------------------------

Route::get('/health', HealthController::class)->name('api.v1.health');

// ---- Geography: central only, the list a visitor picks from ----------------

Route::get('/local-levels', [LocalLevelController::class, 'index'])
    ->name('api.v1.local-levels.index');

// ---- Per-municipality: everything below resolves a tenant first ------------

Route::middleware(ResolveTenant::class)->group(function (): void {
    Route::get('/local-levels/{province}/{district}/{local_level}', [LocalLevelController::class, 'show'])
        ->name('api.v1.local-levels.show');

    Route::get('/wards/{province}/{district}/{local_level}/{ward}', [WardController::class, 'show'])
        ->whereNumber('ward')
        ->name('api.v1.wards.show');
});