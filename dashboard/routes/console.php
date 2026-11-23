<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Alerting scheduler (spec §9.1, §9.4).
|
| Both entries use `withoutOverlapping()` and deliberately not `onOneServer()`:
| the dashboard is a single node, and a shared host without an atomic cache
| lock must still run them. The lock TTL is shorter than the cadence so a
| killed run cannot strand the key.
*/

// Liveness rules for servers that stopped sending samples: every minute.
Schedule::command('noc:evaluate-liveness')
    ->everyMinute()
    ->withoutOverlapping(5);

// Reminders, retries, and the recovery path under a `sync` queue (R43).
Schedule::command('noc:alert-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
