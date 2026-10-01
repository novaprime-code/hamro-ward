<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demonstration data
    |--------------------------------------------------------------------------
    |
    | Seeding invented representatives into a live civic platform is the worst
    | failure this project has available to it, and it would happen through a
    | mistyped environment on a deploy, not through malice (docs/13 §3.3).
    |
    | DemoGuard refuses to seed in production unless this is deliberately on.
    | It lives in config rather than being read with env() inside the guard:
    | once `php artisan config:cache` has run, Laravel stops loading .env at
    | all, so env() there saw real OS variables but not the .env file — the
    | escape hatch worked under Docker and silently died elsewhere. For a
    | switch whose entire job is "never flip by accident", unpredictable is
    | the wrong property. Read through config, the value is fixed at deploy
    | time and is auditable in one place.
    |
    */

    'allow_in_production' => filter_var(
        env('HW_ALLOW_DEMO_DATA', false),
        FILTER_VALIDATE_BOOL,
    ),

];
