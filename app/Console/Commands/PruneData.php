<?php

namespace App\Console\Commands;

use App\Services\Metrics\RetentionService;
use App\Services\Operations\RunTracker;
use Illuminate\Console\Command;

/**
 * Prune data past the retention windows (spec §7.11).
 *
 * The service already prints counts in a fixed order, one key per line. A dry
 * run reports the eligible row counts without deleting anything.
 */
class PruneData extends Command
{
    protected $signature = 'noc:prune
        {--dry-run : Report eligible row counts without deleting anything}';

    protected $description = 'Prune metric, alert, audit, and token rows past their retention windows';

    public function handle(RunTracker $tracker, RetentionService $retention): int
    {
        $task = (bool) $this->option('dry-run') ? 'prune-dry-run' : 'prune';

        return $tracker->run($task, fn (): int => $this->perform($retention));
    }

    private function perform(RetentionService $retention): int
    {
        $counts = $retention->prune((bool) $this->option('dry-run'));

        foreach ($counts as $key => $count) {
            $this->line(sprintf('%s: %d', $key, $count));
        }

        return self::SUCCESS;
    }
}
