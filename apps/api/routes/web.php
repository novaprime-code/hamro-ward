<?php

declare(strict_types=1);

use App\Modules\Accounts\Http\Controllers\RegisterController;
use App\Modules\Accounts\Http\Controllers\ResendVerificationController;
use App\Modules\Accounts\Http\Controllers\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Citizen sign-up and email verification (HW-E30-F01-T02).
|
| These sit beside Fortify's routes rather than inside them: Fortify's own
| registration signs the new account in and its verification route demands a
| session, and this platform can afford neither (see the controllers, and the
| note in config/fortify.php). Everything else about sign-in — the login, the
| password reset, the profile — is still Fortify's.
|
| The paths are the conventional ones, so the web tier's existing rewrites
| (apps/web/next.config.ts) carry them unchanged.
*/
Route::middleware('web')->group(function (): void {
    Route::post('/register', RegisterController::class)
        ->middleware(['guest:web', 'throttle:register'])
        ->name('register');

    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed:relative', 'throttle:verification'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', ResendVerificationController::class)
        ->middleware('throttle:verification')
        ->name('verification.send');
});
