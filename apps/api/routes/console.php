<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Central indexes (docs/12 §4.3). The outbox drain is also queued by every
| import; the minute-by-minute run is the safety net for anything else that
| writes holdings. The hourly rebuild catches changes no outbox announces —
| a person published centrally, a ward published after onboarding.
*/
Schedule::command('hw:outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('hw:index:rebuild')->hourly()->withoutOverlapping();
