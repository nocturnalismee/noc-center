<?php

namespace App\Services\Metrics;

use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recompute completed metric buckets (spec §7.6, §11).
 *
 * A bucket is epoch-aligned UTC: `floor(ts / resolution) * resolution`. A
 * bucket is only written once it is complete — `bucket_start + resolution >
 * now` means the current bucket is still filling and is skipped, so a chart
 * never shows a partial point that later jumps.
 *
 * Sources are strictly chained: `5m` reads `metric_samples`, `1h` reads `5m`,
 * and `1d` reads `1h`. A coarse bucket never scans raw samples, so a gap in
 * the finer rollup stays a gap instead of being silently backfilled from a
 * source the dashboards do not use.
 *
 * Aggregation runs in PHP (spec §11) with keyed incremental folds instead of
 * per-bucket queries: one chunked read per source table and one upsert per
 * chunk. Averages are always `sum / samples`; sums are rounded to the column
 * scale (2dp for percentages, 3dp for load) and network sums stay integers.
 *
 * The upsert targets the existing unique `(server_id, resolution_seconds,
 * bucket_start)`, so a rerun is idempotent and a late sample re-aggregated
 * inside the window replaces the bucket in place.
 */
class RollupService
{
    public const FIVE_MINUTES = 300;

    public const HOUR = 3600;

    public const DAY = 86400;

    /** Percent columns are `decimal(12,2)` / `decimal(5,2)`. */
    private const PERCENT_SCALE = 2;

    /** `load1` is `decimal(14,3)` / `decimal(8,3)`. */
    private const LOAD_SCALE = 3;

    /** Rows aggregated and rows upserted per batch. */
    private const CHUNK = 1000;

    /**
     * Summed metrics: target column => [rollup source column, raw sample column].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SUMS = [
        'cpu_usage_sum' => ['cpu_usage_sum', 'cpu_usage_pct'],
        'cpu_iowait_sum' => ['cpu_iowait_sum', 'cpu_iowait_pct'],
        'mem_used_pct_sum' => ['mem_used_pct_sum', 'mem_used_pct'],
        'disk_used_pct_sum' => ['disk_used_pct_sum', 'disk_used_pct'],
        'load1_sum' => ['load1_sum', 'load1'],
    ];

    /**
     * Min/max metrics: target column => [min|max, rollup source column, raw sample column].
     *
     * `cpu_iowait_sum`, `load1_sum`, and the network sums have no min/max
     * columns in the schema, so none is derived for them.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const EXTREMES = [
        'cpu_usage_min' => ['min', 'cpu_usage_min', 'cpu_usage_pct'],
        'cpu_usage_max' => ['max', 'cpu_usage_max', 'cpu_usage_pct'],
        'mem_used_pct_min' => ['min', 'mem_used_pct_min', 'mem_used_pct'],
        'mem_used_pct_max' => ['max', 'mem_used_pct_max', 'mem_used_pct'],
        'disk_used_pct_min' => ['min', 'disk_used_pct_min', 'disk_used_pct'],
        'disk_used_pct_max' => ['max', 'disk_used_pct_max', 'disk_used_pct'],
        'load1_max' => ['max', 'load1_max', 'load1'],
    ];

    /**
     * Recompute every completed bucket overlapping `[from, to)` and return the
     * number of buckets written.
     */
    public function rollup(
        Server $server,
        int $resolutionSeconds,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        if (! in_array($resolutionSeconds, [self::FIVE_MINUTES, self::HOUR, self::DAY], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported rollup resolution: %d.', $resolutionSeconds));
        }

        $from = $from->utc();
        $to = $to->utc();

        if ($from->greaterThanOrEqualTo($to)) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $firstBucket = $this->bucketStart($from, $resolutionSeconds);
        $lastExclusive = $this->bucketStart($to->subSecond(), $resolutionSeconds)->addSeconds($resolutionSeconds);

        // Never touch the bucket that is still filling.
        $completeBefore = $now->getTimestamp();
        if ($lastExclusive->getTimestamp() > $completeBefore) {
            $lastExclusive = $this->bucketStart($now, $resolutionSeconds);
        }

        if ($lastExclusive->lessThanOrEqualTo($firstBucket)) {
            return 0;
        }

        $aggregates = $resolutionSeconds === self::FIVE_MINUTES
            ? $this->fromSamples($server, $firstBucket, $lastExclusive)
            : $this->fromRollups(
                $server,
                $resolutionSeconds === self::HOUR ? self::FIVE_MINUTES : self::HOUR,
                $resolutionSeconds,
                $firstBucket,
                $lastExclusive,
            );

        if ($aggregates === []) {
            return 0;
        }

        $this->persist($server, $resolutionSeconds, $aggregates);

        return count($aggregates);
    }

    /**
     * Epoch-aligned bucket start for a timestamp.
     */
    public function bucketStart(CarbonImmutable $at, int $resolutionSeconds): CarbonImmutable
    {
        $epoch = $at->utc()->getTimestamp();

        return CarbonImmutable::createFromTimestamp(
            intdiv($epoch - (($epoch % $resolutionSeconds) + $resolutionSeconds) % $resolutionSeconds, $resolutionSeconds) * $resolutionSeconds,
            'UTC',
        );
    }

    /**
     * Fold raw samples into 5m buckets.
     *
     * @return array<int, array<string, int|float>> keyed by bucket epoch
     */
    private function fromSamples(Server $server, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $aggregates = [];

        MetricSample::query()
            ->select(['id', 'collected_at', 'cpu_usage_pct', 'cpu_iowait_pct', 'mem_used_pct', 'disk_used_pct', 'load1', 'net_rx_bps', 'net_tx_bps'])
            ->where('server_id', $server->getKey())
            ->where('collected_at', '>=', $from)
            ->where('collected_at', '<', $to)
            // A stable order makes chunking deterministic; the primary key
            // alone would be enough for correctness.
            ->orderBy('id')
            ->chunk(self::CHUNK, function (Collection $rows) use (&$aggregates): void {
                foreach ($rows as $row) {
                    $bucketStart = $this->bucketStart(
                        CarbonImmutable::instance($row->collected_at),
                        self::FIVE_MINUTES,
                    );
                    $bucket = $bucketStart->getTimestamp();

                    $aggregate = $aggregates[$bucket] ?? $this->emptyAggregate();

                    $this->add($aggregate, $row);

                    $aggregates[$bucket] = $aggregate;
                }
            });

        ksort($aggregates);

        return $this->round($aggregates);
    }

    /**
     * Fold finer rollups into coarser buckets.
     *
     * @return array<int, array<string, int|float>> keyed by bucket epoch
     */
    private function fromRollups(
        Server $server,
        int $sourceResolution,
        int $resolution,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $aggregates = [];

        MetricRollup::query()
            ->where('server_id', $server->getKey())
            ->where('resolution_seconds', $sourceResolution)
            ->where('bucket_start', '>=', $from)
            ->where('bucket_start', '<', $to)
            ->orderBy('bucket_start')
            ->chunk(self::CHUNK, function (Collection $rows) use (&$aggregates, $resolution): void {
                foreach ($rows as $row) {
                    $bucket = $this->bucketStart(CarbonImmutable::instance($row->bucket_start), $resolution)->getTimestamp();

                    $aggregate = $aggregates[$bucket] ?? $this->emptyAggregate();

                    $this->add($aggregate, $row);

                    $aggregates[$bucket] = $aggregate;
                }
            });

        ksort($aggregates);

        return $this->round($aggregates);
    }

    /**
     * @param  array<string, int|float>  $aggregate
     * @param  object  $row  a `metric_samples` or `metric_rollups` row
     */
    private function add(array &$aggregate, object $row): void
    {
        // Rollup rows carry their own sample count; a raw sample counts as one.
        $aggregate['samples'] += isset($row->samples) ? (int) $row->samples : 1;

        foreach (self::SUMS as $target => [$rollupColumn, $sampleColumn]) {
            $aggregate[$target] += $this->value($row, $rollupColumn, $sampleColumn);
        }

        $aggregate['net_rx_bps_sum'] += (int) $this->value($row, 'net_rx_bps_sum', 'net_rx_bps');
        $aggregate['net_tx_bps_sum'] += (int) $this->value($row, 'net_tx_bps_sum', 'net_tx_bps');

        foreach (self::EXTREMES as $target => [$side, $rollupColumn, $sampleColumn]) {
            $value = $this->value($row, $rollupColumn, $sampleColumn);

            $aggregate[$target] = $side === 'min'
                ? min($aggregate[$target], $value)
                : max($aggregate[$target], $value);
        }
    }

    /**
     * A rollup row exposes the finer aggregate columns; a raw sample exposes
     * only the current value. An explicit `isset()` is required: `??` on a
     * missing Eloquent attribute resolves through `getAttribute()` and
     * returns null, which would silently contribute zero.
     */
    private function value(object $row, string $rollupColumn, string $sampleColumn): float
    {
        if (isset($row->{$rollupColumn})) {
            return (float) $row->{$rollupColumn};
        }

        return isset($row->{$sampleColumn}) ? (float) $row->{$sampleColumn} : 0.0;
    }

    /**
     * @param  array<int, array<string, int|float>>  $aggregates
     * @return array<int, array<string, int|float>>
     */
    private function round(array $aggregates): array
    {
        foreach ($aggregates as $bucket => &$aggregate) {
            foreach (['cpu_usage', 'mem_used_pct', 'disk_used_pct'] as $metric) {
                $aggregate[$metric.'_sum'] = round($aggregate[$metric.'_sum'], self::PERCENT_SCALE);
            }

            $aggregate['cpu_iowait_sum'] = round($aggregate['cpu_iowait_sum'], self::PERCENT_SCALE);
            $aggregate['load1_sum'] = round($aggregate['load1_sum'], self::LOAD_SCALE);

            foreach (['cpu_usage', 'mem_used_pct', 'disk_used_pct'] as $metric) {
                $aggregate[$metric.'_min'] = round($aggregate[$metric.'_min'], self::PERCENT_SCALE);
                $aggregate[$metric.'_max'] = round($aggregate[$metric.'_max'], self::PERCENT_SCALE);
            }

            $aggregate['load1_max'] = round($aggregate['load1_max'], self::LOAD_SCALE);
        }

        unset($aggregate);

        return $aggregates;
    }

    /**
     * @return array<string, int|float>
     */
    private function emptyAggregate(): array
    {
        return [
            'samples' => 0,
            'cpu_usage_sum' => 0.0,
            'cpu_iowait_sum' => 0.0,
            'mem_used_pct_sum' => 0.0,
            'disk_used_pct_sum' => 0.0,
            'load1_sum' => 0.0,
            'net_rx_bps_sum' => 0,
            'net_tx_bps_sum' => 0,
            'cpu_usage_min' => INF,
            'cpu_usage_max' => -INF,
            'mem_used_pct_min' => INF,
            'mem_used_pct_max' => -INF,
            'disk_used_pct_min' => INF,
            'disk_used_pct_max' => -INF,
            'load1_max' => -INF,
        ];
    }

    /**
     * @param  array<int, array<string, int|float>>  $aggregates
     */
    private function persist(Server $server, int $resolution, array $aggregates): void
    {
        $now = CarbonImmutable::now('UTC');

        $rows = [];

        foreach ($aggregates as $bucket => $aggregate) {
            if ($aggregate['samples'] === 0) {
                continue;
            }

            $rows[] = $aggregate + [
                'server_id' => $server->getKey(),
                'resolution_seconds' => $resolution,
                'bucket_start' => CarbonImmutable::createFromTimestamp($bucket, 'UTC')->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('metric_rollups')->upsert(
                $chunk,
                ['server_id', 'resolution_seconds', 'bucket_start'],
                [
                    'samples',
                    'cpu_usage_sum',
                    'cpu_iowait_sum',
                    'mem_used_pct_sum',
                    'disk_used_pct_sum',
                    'load1_sum',
                    'net_rx_bps_sum',
                    'net_tx_bps_sum',
                    'cpu_usage_min',
                    'cpu_usage_max',
                    'mem_used_pct_min',
                    'mem_used_pct_max',
                    'disk_used_pct_min',
                    'disk_used_pct_max',
                    'load1_max',
                    'updated_at',
                ],
            );
        }
    }
}
