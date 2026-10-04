<?php

declare(strict_types=1);

use App\Modules\Staff\Http\Controllers\StaffAuthController;
use App\Modules\Staff\Http\Middleware\AuthenticateStaff;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff API — admin host only (docs/12 §11, HW-E13-F01-T02, T03)
|--------------------------------------------------------------------------
| Prefix "api/v1/staff", registered in bootstrap/app.php with the `web`
| group: a session (cookie hw_staff_session, chosen by
| ConfigureSessionForHost) and CSRF via the XSRF-TOKEN cookie from
| /sanctum/csrf-cookie. The browser reaches it same-origin through the web
| tier's /api rewrite, so no CORS is configured anywhere.
|
| Everything here is a 404 on any host but the admin host (EnsureAdminHost,
| applied ahead of the `web` group in bootstrap/app.php).
*/

Route::middleware('throttle:staff-sign-in')->group(function (): void {
    Route::post('/login', [StaffAuthController::class, 'login'])->name('staff.login');
    Route::post('/two-factor/enrol', [StaffAuthController::class, 'enrol'])->name('staff.two-factor.enrol');
    Route::post('/two-factor/confirm', [StaffAuthController::class, 'confirm'])->name('staff.two-factor.confirm');
    Route::post('/two-factor/challenge', [StaffAuthController::class, 'challenge'])->name('staff.two-factor.challenge');
});

Route::post('/logout', [StaffAuthController::class, 'logout'])->name('staff.logout');

Route::middleware(AuthenticateStaff::class)->group(function (): void {
    Route::get('/me', [StaffAuthController::class, 'me'])->name('staff.me');
});
