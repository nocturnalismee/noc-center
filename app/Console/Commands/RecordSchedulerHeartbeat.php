<?php

namespace App\Console\Commands;

use App\Models\OperationalRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'noc:scheduler-heartbeat';

    protected $description = 'Record that the Laravel scheduler is running';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $run = OperationalRun::query()->firstOrNew(['task_key' => 'scheduler']);
        $run->forceFill([
            'started_at' => $now,
            'finished_at' => $now,
            'succeeded_at' => $now,
            'status' => 'succeeded',
        ])->save();

        return self::SUCCESS;
    }
}
