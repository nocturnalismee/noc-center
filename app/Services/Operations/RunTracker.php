<?php

namespace App\Services\Operations;

use App\Events\OperationsChanged;
use App\Models\OperationalRun;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RunTracker
{
    /**
     * Track a scheduled operation while preserving its exit code and exception behavior.
     *
     * @param  callable(OperationalRun): int  $operation
     */
    public function run(string $task, callable $operation): int
    {
        $now = CarbonImmutable::now('UTC');
        $startedAt = hrtime(true);
        $runId = (string) Str::uuid();

        // Keep one status row per task. An upsert makes repeated scheduled
        // runs idempotent while preserving the last successful timestamp.
        OperationalRun::query()->upsert([[
            'task_key' => $task,
            'started_at' => $now,
            'finished_at' => null,
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['task_key'], ['started_at', 'finished_at', 'status', 'updated_at']);
        $run = OperationalRun::query()->where('task_key', $task)->firstOrFail();

        try {
            $exitCode = $operation($run);
            $finished = CarbonImmutable::now('UTC');
            $success = $exitCode === 0;
            $attributes = [
                'finished_at' => $finished,
                'status' => $success ? 'succeeded' : 'failed',
            ];

            if ($success) {
                $attributes['succeeded_at'] = $finished;
            }

            $run->forceFill($attributes)->save();

            Log::info('operations.command_finished', [
                'task' => $task,
                'run_id' => $runId,
                'status' => $success ? 'succeeded' : 'failed',
                'exit_code' => $exitCode,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ]);

            Realtime::emit(new OperationsChanged(
                taskKey: $task,
                status: $success ? 'succeeded' : 'failed',
                finishedAt: $finished->toIso8601String(),
            ));

            return $exitCode;
        } catch (Throwable $exception) {
            $finished = CarbonImmutable::now('UTC');
            $run->forceFill([
                'finished_at' => $finished,
                'status' => 'failed',
            ])->save();

            Log::error('operations.command_finished', [
                'task' => $task,
                'run_id' => $runId,
                'status' => 'failed',
                'exception' => $exception::class,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ]);

            Realtime::emit(new OperationsChanged(
                taskKey: $task,
                status: 'failed',
                finishedAt: $finished->toIso8601String(),
            ));

            throw $exception;
        }
    }
}
