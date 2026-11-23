<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Age-based retention pruning (spec §7.11).
 *
 * Every table is deleted page by page: a bounded `SELECT id … LIMIT n` followed
 * by `DELETE … WHERE id IN (…)`, so SQLite never holds a long write lock and a
 * giant `DELETE … WHERE created_at < ?` never competes with ingest.
 *
 * Windows are cutoffs at `now()->utc()->subDays(n)`. A row exactly at the
 * cutoff survives; only rows strictly older than it are eligible. Details are
 * pruned before samples (a sample delete cascades its detail), rollups are
 * pruned by `bucket_start` per resolution, and open/acknowledged alerts,
 * active/grace tokens, and users are never touched.
 *
 * A dry run counts the whole eligible set per table without deleting anything;
 * a real run deletes at most `batch_size` rows per batch and `max_batches`
 * batches per table, leaving the remainder for the next run.
 */
class RetentionService
{
    /**
     * Prune every retention table and return the deleted (or, in a dry run,
     * eligible) row counts keyed in the fixed order below.
     *
     * @return array<string, int>
     */
    public function prune(bool $dryRun = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $batchSize = max(1, (int) config('retention.batch_size', 5000));
        $maxBatches = max(0, (int) config('retention.max_batches', 50));

        return [
            'metric_details' => $this->pruneBy(
                'metric_details',
                'sample_id',
                fn (Builder $query) => $query->where('created_at', '<', $this->cutoff($now, 'metric_details_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'metric_samples' => $this->pruneBy(
                'metric_samples',
                'id',
                fn (Builder $query) => $query->where('collected_at', '<', $this->cutoff($now, 'metric_samples_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'metric_rollups_5m' => $this->pruneBy(
                'metric_rollups',
                'id',
                fn (Builder $query) => $this->rollupScope($query, 300, $this->cutoff($now, 'rollup_days.5m')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'metric_rollups_1h' => $this->pruneBy(
                'metric_rollups',
                'id',
                fn (Builder $query) => $this->rollupScope($query, 3600, $this->cutoff($now, 'rollup_days.1h')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'metric_rollups_1d' => $this->pruneBy(
                'metric_rollups',
                'id',
                fn (Builder $query) => $this->rollupScope($query, 86400, $this->cutoff($now, 'rollup_days.1d')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'resolved_alerts' => $this->pruneBy(
                'alerts',
                'id',
                fn (Builder $query) => $query
                    ->where('status', 'resolved')
                    ->whereNotNull('resolved_at')
                    ->where('resolved_at', '<', $this->cutoff($now, 'resolved_alerts_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'alert_deliveries' => $this->pruneBy(
                'alert_deliveries',
                'id',
                fn (Builder $query) => $query->where('created_at', '<', $this->cutoff($now, 'alert_deliveries_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            // `AuditEvent` is append-only through its model/builder; retention
            // is the one sanctioned maintenance path and goes around that
            // guard with the query builder (spec §7.11, R28).
            'audit_events' => $this->pruneBy(
                'audit_events',
                'id',
                fn (Builder $query) => $query->where('created_at', '<', $this->cutoff($now, 'audit_events_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            'failed_jobs' => $this->pruneBy(
                'failed_jobs',
                'id',
                fn (Builder $query) => $query->where('failed_at', '<', $this->cutoff($now, 'failed_jobs_days')),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
            // Only revoked/expired rows. A live token is neither; a rotated
            // token inside its grace window has a future `expires_at`, so both
            // survive regardless of how long they have existed.
            'server_tokens' => $this->pruneBy(
                'server_tokens',
                'id',
                fn (Builder $query) => $query->where(function (Builder $query) use ($now): void {
                    $cutoff = $this->cutoff($now, 'revoked_tokens_days');

                    $query
                        ->where(function (Builder $query) use ($cutoff): void {
                            $query->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff);
                        })
                        ->orWhere(function (Builder $query) use ($cutoff): void {
                            $query->whereNotNull('expires_at')->where('expires_at', '<', $cutoff);
                        });
                }),
                $batchSize,
                $maxBatches,
                $dryRun,
            ),
        ];
    }

    /**
     * @param  callable(Builder): void  $constrain
     */
    private function pruneBy(
        string $table,
        string $key,
        callable $constrain,
        int $batchSize,
        int $maxBatches,
        bool $dryRun,
    ): int {
        if ($dryRun) {
            $query = DB::table($table);
            $constrain($query);

            return (int) $query->count();
        }

        $deleted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $query = DB::table($table);
            $constrain($query);

            /** @var Collection<int, int|string> $keys */
            $keys = $query->orderBy($key)->limit($batchSize)->pluck($key);

            if ($keys->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn($key, $keys->all())->delete();
        }

        return $deleted;
    }

    private function rollupScope(Builder $query, int $resolutionSeconds, CarbonImmutable $cutoff): Builder
    {
        return $query
            ->where('resolution_seconds', $resolutionSeconds)
            ->where('bucket_start', '<', $cutoff);
    }

    private function cutoff(CarbonImmutable $now, string $key): CarbonImmutable
    {
        return $now->subDays((int) config('retention.'.$key));
    }
}
