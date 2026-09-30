<?php

declare(strict_types=1);

use App\Modules\Geography\Http\Controllers\LocalLevelController;
use App\Modules\Offices\Http\Controllers\PersonController;
use App\Modules\Offices\Http\Controllers\WardController;
use App\Modules\Provenance\Http\Controllers\EvidenceController;
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
|
| Every route is throttled. The limit is generous for the web tier and tight
| for anything else, because the web tier's requests each stand for many
| visitors while nothing else is supposed to be calling this at all — see
| SecurityServiceProvider and config/security.php. The per-visitor ceiling is
| not here and cannot be: visitors are behind the web tier, and Laravel sees
| them all as one address.
*/

// ---- Support ---------------------------------------------------------------

/*
 * Not throttled, deliberately. This is what a container health check and an
 * uptime monitor call, on a schedule, and a monitor that gets a 429 reports an
 * outage that is not happening. It touches no database and returns a fixed
 * shape, so there is nothing here to exhaust.
 */
Route::get('/health', HealthController::class)->name('api.v1.health');

Route::middleware('throttle:public-read')->group(function (): void {
    // ---- Geography: central only, the list a visitor picks from ------------

    Route::get('/local-levels', [LocalLevelController::class, 'index'])
        ->name('api.v1.local-levels.index');

    // ---- Per-municipality: everything below resolves a tenant first --------

    Route::middleware(ResolveTenant::class)->group(function (): void {
        Route::get('/local-levels/{province}/{district}/{local_level}', [LocalLevelController::class, 'show'])
            ->name('api.v1.local-levels.show');

        Route::get('/wards/{province}/{district}/{local_level}/{ward}', [WardController::class, 'show'])
            ->whereNumber('ward')
            ->name('api.v1.wards.show');

        // The person behind a seat row (HW-E05-F02).
        Route::get('/persons/{province}/{district}/{local_level}/{person}', [PersonController::class, 'show'])
            ->name('api.v1.persons.show');

        /*
         * Show the working (HW-E04-F02). subject_type is constrained here as
         * well as allowlisted in the query: two independent statements of the
         * same rule, so that a future subject added to one and forgotten in the
         * other fails closed rather than open.
         *
         * subject_id is constrained to a uuid so that anything else is a 404
         * from the router, before a query runs.
         */
        Route::get(
            '/evidence/{province}/{district}/{local_level}/{subject_type}/{subject_id}',
            [EvidenceController::class, 'show'],
        )
            ->whereIn('subject_type', ['office_holding', 'vacancy', 'ward_office', 'admin_unit', 'person', 'party'])
            ->whereUuid('subject_id')
            ->name('api.v1.evidence.show');
    });
});