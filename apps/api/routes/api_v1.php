<?php

declare(strict_types=1);

use App\Modules\Support\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Prefix "api/v1" is applied in bootstrap/app.php.
| Routes are grouped per module as modules arrive (docs/06 §16).
*/

Route::get('/health', HealthController::class)->name('api.v1.health');
