<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Operations\RunTracker;
use App\Services\Servers\ServerService;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Purge soft-deleted servers past the retention grace period.
 *
 * Soft delete keeps a server and its history until an administrator purges it
 * by hand. This scheduled command closes that gap: once `deleted_at` is older
 * than the configured number of days, the server is purged automatically with
 * the `auto` trigger and no actor. A setting of 0 (or a negative value, which
 * is clamped) disables the automatic pass; manual `noc:purge-server` remains.
 *
 * The cutoff is strict: only rows deleted before `now - days` are eligible.
 * A failure on one server is reported, counted, and does not stop the batch;
 * the run then returns failure so the schedule health shows it. `--dry-run`
 * counts without writing and never runs `VACUUM`.
 */
class PurgeDeletedServers extends Command
{
    protected $signature = 'noc:purge-deleted-servers
        {--dry-run : Report eligible servers without deleting anything}';

    protected $description = 'Permanently purge soft-deleted servers past the retention grace period';

    public function handle(
        ServerService $servers,
        SettingsRepository $settings,
        RunTracker $tracker,
    ): int {
        return $tracker->run(
            (bool) $this->option('dry-run') ? 'purge-deleted-servers-dry-run' : 'purge-deleted-servers',
            fn (): int => $this->perform($servers, $settings),
        );
    }

    private function perform(ServerService $servers, SettingsRepository $settings): int
    {
        $days = max(0, (int) $settings->get(
            'retention.deleted_server_days',
            config('retention.deleted_server_days'),
        ));

        if ($days === 0) {
            $this->line('Automatic purge is disabled (retention.deleted_server_days = 0).');

            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        $due = Server::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->get();

        if ($due->isEmpty()) {
            $this->line('No deleted servers were due.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $purged = 0;
        $failed = 0;

        foreach ($due as $server) {
            $name = (string) $server->name;

            try {
                if ($dryRun) {
                    $counts = $servers->purgeCounts($server);
                    $this->line(sprintf('Would purge server [%s]: %s', $name, $this->summary($counts)));
                    $purged++;

                    continue;
                }

                $servers->purge($server, null, 'auto');
                $purged++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('Purge failed for [%s]: %s', $name, $this->safe($exception->getMessage())));
            }
        }

        if ($dryRun) {
            $this->line('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Purged %d deleted servers.', $purged));

        if ($failed > 0) {
            $this->error(sprintf('%d server(s) failed; they will be retried on the next run.', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{samples: int, details: int, rollups: int, alerts: int, deliveries: int, tokens: int}  $counts
     */
    private function summary(array $counts): string
    {
        return sprintf(
            '%d samples, %d details, %d rollups, %d alerts, %d deliveries, %d tokens.',
            $counts['samples'],
            $counts['details'],
            $counts['rollups'],
            $counts['alerts'],
            $counts['deliveries'],
            $counts['tokens'],
        );
    }

    /**
     * Keep exception output to a single bounded line; it may echo a SQL error
     * but never a credential (the command touches none).
     */
    private function safe(string $value): string
    {
        $clean = preg_replace('/[^\P{C}\n]+/u', '?', $value) ?? '';
        $clean = str_replace(["\r", "\n"], ' ', $clean);

        return mb_strlen($clean) > 300 ? mb_substr($clean, 0, 300).'…' : $clean;
    }
}
