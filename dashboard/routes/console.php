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

// Metric rollups (spec §11): 5m from samples, then 1h, then 1d, all over the
// last completed buckets. The lock TTL is strictly below the 5-minute cadence
// so a killed run cannot strand the key into the next tick.
Schedule::command('noc:rollup')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

// Retention pruning (spec §7.11) runs at low traffic. Batches are bounded, so
// the lock TTL only has to outlive a normal run and stay below the daily
// cadence; a killed run must not strand the key for a whole day. No
// `onOneServer()`: the dashboard is a single node without an atomic cache lock.
Schedule::command('noc:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(60);

// Shared hosting has no supervisor: drain the queue from the scheduler instead
// (spec §15.1/§15.3). `--stop-when-empty` and `--max-time=50` keep each run
// below the one-minute cadence; `--tries=3` bounds retries and `--sleep=1`
// avoids a busy loop while a job is still backing off. The lock TTL is two
// minutes, longer than `--max-time` but short enough that a killed worker
// cannot block the next tick forever. VPS deployments use systemd instead.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(2);
