<?php

namespace App\Services\Metrics;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Build chart series from raw samples or metric rollups (spec §7.6, §11, §12.3).
 *
 * Short ranges read `metric_samples`; long ranges read `metric_rollups` at
 * the resolution named by `config/metrics.php`. When the requested window
 * would produce more points than the range cap, buckets widen by an integer
 * factor so the response never exceeds the cap. A bucket without source data
 * is omitted instead of zero-filled, so gaps stay visible.
 *
 * Raw points are the mean of their samples. Rollup points are the weighted
 * mean `sum(value_sum) / sum(samples)`, never an average of averages.
 */
class MetricSeriesRepository
{
    /**
     * @return array{metric: string, range: string, resolution: int, points: list<array{t: string, v: float}>}
     */
    public function series(Server $server, string $metric, string $range): array
    {
        $ranges = config('metrics.ranges', []);
        $metrics = config('metrics.metrics', []);

        if (! is_array($ranges) || ! array_key_exists($range, $ranges)) {
            throw new InvalidArgumentException(sprintf('Unknown series range: %s.', $range));
        }

        if (! is_array($metrics) || ! array_key_exists($metric, $metrics)) {
            throw new InvalidArgumentException(sprintf('Unknown series metric: %s.', $metric));
        }

        $rangeConfig = $ranges[$range];
        $metricConfig = $metrics[$metric];

        $rangeSeconds = (int) $rangeConfig['seconds'];
        $cap = max(1, (int) $rangeConfig['cap']);
        $isRaw = $rangeConfig['source'] === 'raw';

        $sourceResolution = $isRaw
            ? max(1, (int) $server->interval_seconds)
            : max(1, (int) $rangeConfig['resolution']);

        $factor = max(1, (int) ceil(($rangeSeconds / $sourceResolution) / $cap));
        $resolution = $sourceResolution * $factor;

        $now = CarbonImmutable::now('UTC');
        $from = $now->subSeconds($rangeSeconds);

        $points = $isRaw
            ? $this->rawPoints($server, $metricConfig['raw'], $from, $now, $resolution)
            : $this->rollupPoints($server, $metricConfig['rollup'], max(1, (int) $rangeConfig['resolution']), $from, $now, $resolution);

        // A window that is not aligned to the effective bucket can intersect
        // one more bucket than `range / resolution` (e.g. 24h at 12:00:30).
        // Keep the newest cap buckets so the resolution never has to widen.
        if (count($points) > $cap) {
            $points = array_values(array_slice($points, -$cap));
        }

        return [
            'metric' => $metric,
            'range' => $range,
            'resolution' => $resolution,
            'points' => $points,
        ];
    }

    /**
     * @return list<array{t: string, v: float}>
     */
    private function rawPoints(
        Server $server,
        string $column,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $resolution,
    ): array {
        $rows = DB::table('metric_samples')
            ->select(['collected_at', $column])
            ->where('server_id', $server->getKey())
            ->where('collected_at', '>=', $from)
            ->where('collected_at', '<', $to)
            ->orderBy('collected_at')
            ->get();

        $sums = [];
        $counts = [];

        foreach ($rows as $row) {
            $bucket = $this->bucket(
                CarbonImmutable::parse((string) $row->collected_at, 'UTC')->getTimestamp(),
                $resolution,
            );

            $sums[$bucket] = ($sums[$bucket] ?? 0.0) + (float) $row->{$column};
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        return $this->points($sums, $counts);
    }

    /**
     * @return list<array{t: string, v: float}>
     */
    private function rollupPoints(
        Server $server,
        string $column,
        int $sourceResolution,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $resolution,
    ): array {
        $rows = DB::table('metric_rollups')
            ->select(['bucket_start', 'samples', $column])
            ->where('server_id', $server->getKey())
            ->where('resolution_seconds', $sourceResolution)
            ->where('bucket_start', '>=', $from)
            ->where('bucket_start', '<', $to)
            ->orderBy('bucket_start')
            ->get();

        $sums = [];
        $counts = [];

        foreach ($rows as $row) {
            // A bucket with no samples contributes to no weighted mean.
            $samples = (int) $row->samples;

            if ($samples <= 0) {
                continue;
            }

            $bucket = $this->bucket(
                CarbonImmutable::parse((string) $row->bucket_start, 'UTC')->getTimestamp(),
                $resolution,
            );

            $sums[$bucket] = ($sums[$bucket] ?? 0.0) + (float) $row->{$column};
            $counts[$bucket] = ($counts[$bucket] ?? 0) + $samples;
        }

        return $this->points($sums, $counts);
    }

    /**
     * @param  array<int, float>  $sums
     * @param  array<int, int>  $counts
     * @return list<array{t: string, v: float}>
     */
    private function points(array $sums, array $counts): array
    {
        ksort($sums);

        $points = [];

        foreach ($sums as $bucket => $sum) {
            $count = $counts[$bucket] ?? 0;

            if ($count === 0) {
                continue;
            }

            $points[] = [
                't' => CarbonImmutable::createFromTimestamp($bucket, 'UTC')->format('Y-m-d\TH:i:s\Z'),
                'v' => round($sum / $count, 3),
            ];
        }

        return $points;
    }

    /**
     * Epoch-aligned floor, correct for timestamps before 1970 as well.
     */
    private function bucket(int $timestamp, int $resolution): int
    {
        return intdiv(
            $timestamp - (($timestamp % $resolution) + $resolution) % $resolution,
            $resolution,
        ) * $resolution;
    }
}
