<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Metrics\RollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Build the 5m/1h/1d rollups (spec §7.6, §11).
 *
 * Resolutions always run in dependency order — `5m` from raw samples, `1h`
 * from `5m`, `1d` from `1h` — so a `--backfill-days` run rebuilds the whole
 * chain for that window before each coarser level reads it.
 *
 * Default (scheduled) ranges cover the last completed buckets only: 12 × 5m,
 * 3 × 1h, and 2 × 1d. `RollupService` skips the incomplete current bucket, so
 * the range endpoints are generous rather than exact.
 */
class RollupMetrics extends Command
{
    protected $signature = 'noc:rollup
        {--server= : Only roll up this server ULID}
        {--resolution= : Comma-separated resolutions to run (300,3600,86400)}
        {--from= : UTC ISO-8601 start of an explicit range (inclusive)}
        {--to= : UTC ISO-8601 end of an explicit range (exclusive)}
        {--backfill-days=0 : Recompute completed buckets from N days ago to now}';

    protected $description = 'Recompute completed 5m, 1h, and 1d metric rollup buckets';

    /** Completed buckets the scheduled run keeps fresh at each level. */
    private const DEFAULT_BUCKETS = [
        RollupService::FIVE_MINUTES => 12,
        RollupService::HOUR => 3,
        RollupService::DAY => 2,
    ];

    private const ORDER = [RollupService::FIVE_MINUTES, RollupService::HOUR, RollupService::DAY];

    public function handle(RollupService $rollups): int
    {
        $resolutions = $this->resolutions();
        if ($resolutions === null) {
            return self::FAILURE;
        }

        $server = $this->targetServer();
        if ($server === false) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');

        $backfillDays = $this->option('backfill-days');
        if ($backfillDays === null || $backfillDays === '' || ! is_numeric($backfillDays) || (int) $backfillDays < 0) {
            $this->error('--backfill-days must be a non-negative whole number of days.');

            return self::FAILURE;
        }

        $explicit = $this->option('from') !== null || $this->option('to') !== null;
        $from = $explicit ? $this->parseTimestamp('from') : null;
        $to = $explicit ? $this->parseTimestamp('to') : null;

        if ($explicit && ($from === null || $to === null)) {
            return self::FAILURE;
        }

        if ($explicit && $from->greaterThanOrEqualTo($to)) {
            $this->error('--from must be before --to.');

            return self::FAILURE;
        }

        $explicitFrom = $from;
        $explicitTo = $to;

        /** @var array<int, int> $total buckets written per resolution */
        $total = array_fill_keys($resolutions, 0);

        $this->pageServers($server, function (Server $server) use ($rollups, $resolutions, $now, $backfillDays, $explicit, $explicitFrom, $explicitTo, &$total): void {
            foreach ($resolutions as $resolution) {
                [$from, $to] = $explicit
                    ? [$explicitFrom, $explicitTo]
                    : $this->defaultRange($now, $resolution, (int) $backfillDays);

                $total[$resolution] = ($total[$resolution] ?? 0) + $rollups->rollup($server, $resolution, $from, $to);
            }
        });

        foreach ($total as $resolution => $written) {
            $this->info(sprintf('%ds: %d bucket(s)', $resolution, $written));
        }

        return self::SUCCESS;
    }

    /**
     * Walk the servers to roll up.
     *
     * ULIDs are 26-char base32 strings whose first character is often a digit,
     * so keys are always compared as strings (see the explicit column and
     * alias below); `chunkById` is correct for a string primary key and never
     * coerces keys to integers.
     *
     * @param  (\Closure(Server): void)|null  $callback
     */
    private function pageServers(?Server $target, \Closure $callback): void
    {
        if ($target !== null) {
            $callback($target);

            return;
        }

        Server::query()->chunkById(100, function ($servers) use ($callback): void {
            foreach ($servers as $server) {
                $callback($server);
            }
        }, 'id', 'id');
    }

    /**
     * @return CarbonImmutable[]
     */
    private function defaultRange(CarbonImmutable $now, int $resolution, int $backfillDays): array
    {
        if ($backfillDays > 0) {
            return [$now->subDays($backfillDays), $now];
        }

        $current = CarbonImmutable::createFromTimestamp(
            intdiv($now->getTimestamp(), $resolution) * $resolution,
            'UTC',
        );

        return [$current->subSeconds($resolution * self::DEFAULT_BUCKETS[$resolution]), $now];
    }

    /**
     * @return list<int>|null null when the option is invalid
     */
    private function resolutions(): ?array
    {
        $raw = $this->option('resolution');

        if ($raw === null || $raw === '') {
            return self::ORDER;
        }

        $resolutions = [];
        foreach (explode(',', (string) $raw) as $value) {
            $value = trim($value);
            if ($value === '' || ! ctype_digit($value) || ! in_array((int) $value, self::ORDER, true)) {
                $this->error('--resolution must be one or more of 300, 3600, or 86400.');

                return null;
            }

            $resolutions[] = (int) $value;
        }

        // Always run the dependency first (5m → 1h → 1d).
        $resolutions = array_values(array_unique($resolutions));
        sort($resolutions);

        return $resolutions;
    }

    /**
     * @return Server|false|null false when the option is invalid
     */
    private function targetServer(): Server|false|null
    {
        $id = $this->option('server');

        if ($id === null || $id === '') {
            return null;
        }

        $id = (string) $id;

        if (! Str::isUlid($id)) {
            $this->error(sprintf('No server found for ID "%s": not a valid ULID.', $id));

            return false;
        }

        $server = Server::query()->find($id);

        if ($server === null) {
            $this->error(sprintf('No server found for ID "%s".', $id));

            return false;
        }

        return $server;
    }

    private function parseTimestamp(string $option): ?CarbonImmutable
    {
        $value = (string) $this->option($option);

        // A format list matches on prefix only; reject trailing junk first.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            $this->error(sprintf('--%s must be a UTC ISO-8601 timestamp.', $option));

            return null;
        }

        $parsed = null;
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $candidate = CarbonImmutable::createFromFormat($format, $value, 'UTC');
            if ($candidate !== false) {
                $parsed = $candidate;

                break;
            }
        }

        if ($parsed === null) {
            $this->error(sprintf('--%s must be a UTC ISO-8601 timestamp.', $option));

            return null;
        }

        return $parsed->utc();
    }
}
