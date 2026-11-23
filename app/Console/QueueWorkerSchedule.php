<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Shared-hosting queue drain (spec §15.1/§15.3).
 *
 * Without a supervisor, the scheduler starts a short-lived worker every
 * minute. A VPS that runs `noc-queue.service` must set
 * `QUEUE_SCHEDULED_WORKER=false` so the schedule does not start a second,
 * competing worker. The flag is read through `config('queue.scheduled_worker')`
 * so a cached config is authoritative; put the value in `.env` before
 * `config:cache`.
 */
final class QueueWorkerSchedule
{
    public static function register(Schedule $schedule): void
    {
        if (! config('queue.scheduled_worker', true)) {
            return;
        }

        $schedule->command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
            ->everyMinute()
            ->withoutOverlapping(2);
    }
}
