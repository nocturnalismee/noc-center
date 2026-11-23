<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Metrics\RollupService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Build the 5m/1h/1d rollups (spec §7.6, §11).
 *
 * Every selected level also rebuilds its prerequisites, so
 * `--resolution=86400` runs 300 → 3600 → 86400 and `--resolution=3600` runs
 * 300 → 3600. Prerequisites therefore read a finer level that is current
 * instead of a stale one. `300` stays 5m-only.
 *
 * A run's window is the widest selected level's own default window, so the
 * 5m prerequisite of an hourly run covers the same hours the hour does
 * (12 × 5m, 3 × 1h, 2 × 1d completed buckets). `RollupService` skips the
 * incomplete current bucket, so the endpoints are generous rather than exact.
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

        $hasFrom = $this->option('from') !== null;
        $hasTo = $this->option('to') !== null;

        if ($hasFrom !== $hasTo) {
            $this->error('both --from and --to are required to run an explicit range.');

            return self::FAILURE;
        }

        $explicit = $hasFrom;
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

        // Every selected level also rebuilds its prerequisites, so
        // `--resolution=86400` runs 300 → 3600 → 86400 and `--resolution=3600`
        // runs 300 → 3600. A coarser bucket then reads a finer level that is
        // current, never a stale one. `300` stays 5m-only.
        $chain = $this->withPrerequisites($resolutions);

        /** @var array<int, int> $total buckets written per selected resolution */
        $total = array_fill_keys($resolutions, 0);

        $this->pageServers($server, function (Server $server) use ($rollups, $chain, $resolutions, $now, $backfillDays, $explicit, $explicitFrom, $explicitTo, &$total): void {
            // The widest selected level sets the window for its prerequisites.
            $widest = max($chain);

            foreach ($chain as $resolution) {
                [$from, $to] = $explicit
                    ? [$explicitFrom, $explicitTo]
                    : $this->defaultRange($now, $widest, (int) $backfillDays);

                $written = $rollups->rollup($server, $resolution, $from, $to);

                if (in_array($resolution, $resolutions, true)) {
                    $total[$resolution] += $written;
                }
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
     * Expand the selected resolutions with their prerequisites, in order.
     *
     * `300` stays 5m-only; `3600` becomes `300, 3600`; `86400` becomes
     * `300, 3600, 86400`.
     *
     * @param  list<int>  $resolutions
     * @return list<int>
     */
    private function withPrerequisites(array $resolutions): array
    {
        $chain = [RollupService::FIVE_MINUTES];

        if (in_array(RollupService::HOUR, $resolutions, true) || in_array(RollupService::DAY, $resolutions, true)) {
            $chain[] = RollupService::HOUR;
        }

        if (in_array(RollupService::DAY, $resolutions, true)) {
            $chain[] = RollupService::DAY;
        }

        return $chain;
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

    /**
     * Parse a `--from`/`--to` value as an absolute UTC instant.
     *
     * Laravel enables Carbon strict mode, so a mismatched format throws
     * `InvalidFormatException` instead of returning `false`; every attempt is
     * therefore caught. The matching is explicit rather than `Carbon::parse()`
     * so relative words ("yesterday"), trailing junk, and overflowed dates
     * fail instead of being guessed at or silently shifted.
     *
     * Accepted: `YYYY-MM-DD`, `YYYY-MM-DD HH:MM[:SS]`, `YYYY-MM-DDTHH:MM[:SS]`,
     * each with optional fractional seconds and an optional `Z`/`±HH:MM`
     * offset. Values without an offset are UTC.
     */
    private function parseTimestamp(string $option): ?CarbonImmutable
    {
        $value = (string) $this->option($option);

        if (! preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?)?$/',
            $value,
            $parts,
        )) {
            return $this->invalidTimestamp($option);
        }

        // Trailing unmatched groups are absent from the match array, and a
        // short form like `2026-11-20` leaves `''` placeholders: both fall back.
        [, $year, $month, $day] = $parts;
        $hour = ($parts[4] ?? '') ?: '00';
        $minute = ($parts[5] ?? '') ?: '00';
        $second = ($parts[6] ?? '') ?: '00';

        if (! checkdate((int) $month, (int) $day, (int) $year)
            || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return $this->invalidTimestamp($option);
        }

        $offset = ($parts[8] ?? '') ?: '';

        try {
            $parsed = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                sprintf('%s-%s-%s %s:%s:%s', $year, $month, $day, $hour, $minute, $second),
                'UTC',
            );
        } catch (InvalidFormatException|InvalidArgumentException) {
            return $this->invalidTimestamp($option);
        }

        // `checkdate()`, the range checks above, and `!` with a fixed format
        // make this exact; the guard keeps a Carbon change from slipping by.
        if (! $parsed instanceof CarbonImmutable) {
            return $this->invalidTimestamp($option);
        }

        if ($offset !== '' && $offset !== 'Z') {
            // "12:00+07:00" is 05:00 UTC: subtract the offset.
            $sign = $offset[0] === '-' ? -1 : 1;
            $digits = str_replace(':', '', substr($offset, 1));
            $offsetSeconds = $sign * ((int) substr($digits, 0, 2) * 3600 + (int) substr($digits, 2, 2) * 60);

            if (abs($offsetSeconds) >= 24 * 3600) {
                return $this->invalidTimestamp($option);
            }

            $parsed = $parsed->subSeconds($offsetSeconds);
        }

        return $parsed;
    }

    private function invalidTimestamp(string $option): ?CarbonImmutable
    {
        $this->error(sprintf('--%s must be a valid UTC date: YYYY-MM-DD, YYYY-MM-DD HH:MM[:SS], or YYYY-MM-DDTHH:MM[:SS][.sss][Z|±HH:MM].', $option));

        return null;
    }
}
