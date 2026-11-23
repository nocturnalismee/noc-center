<?php

namespace App\Services\Ingest;

use App\Issues\IssueDetector;
use App\Issues\IssueSet;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Store one validated ingest payload.
 *
 * Everything happens in one short transaction and nothing inside it performs
 * HTTP, mail, logging or rollup work. Idempotency is owned by the unique index
 * on `(server_id, collected_at)`: the insert is attempted with
 * `insertOrIgnore()` instead of reading first, so two concurrent retries can
 * never both insert and neither can raise a unique-constraint error.
 *
 * A duplicate still advances the server state (identity, latest metrics,
 * `last_seen_at`, thresholds) because the agent proved it is alive; the
 * sample and its detail row keep the values stored by the first insert
 * (`insertOrIgnore` does not update). Issue detection always runs on the
 * newest stored sample, and the persisted `health`/`issue_codes` are only
 * rewritten when that newest sample is the one this request stored; an older
 * replayed duplicate returns the current issue state without changing it.
 */
class StoreSample
{
    /**
     * Spec §10: `stale_after = last_seen_at + max(2.5 × interval, 150 s)` and
     * `offline_after = last_seen_at + max(5 × interval, 300 s)`.
     */
    private const STALE_MIN_SECONDS = 150;

    private const OFFLINE_MIN_SECONDS = 300;

    public function __construct(
        private readonly PayloadValidator $validator,
        private readonly IssueDetector $issues,
    ) {
        //
    }

    /**
     * Validate a raw body and store it. The payload is validated here so the
     * controller cannot store an unchecked array.
     *
     * @throws \JsonException
     * @throws ValidationException
     */
    public function storeRaw(ServerToken $token, string $rawJson): IngestResult
    {
        return $this->store(
            $token,
            $this->validator->validate($rawJson),
            strlen($rawJson),
            hash('sha256', $rawJson),
        );
    }

    public function store(
        ServerToken $token,
        IngestPayload $payload,
        int $bytes,
        ?string $hash,
    ): IngestResult {
        // A token whose server was deleted between authentication and storage
        // must not silently write an orphan sample: fail loudly (the caller
        // maps this to a 500) rather than dereferencing a null key.
        $server = $token->server ?? throw new ModelNotFoundException('The token has no server.');

        [$created, $sample, $issues] = DB::transaction(function () use ($server, $token, $payload, $bytes, $hash): array {
            $now = CarbonImmutable::now('UTC');

            $inserted = MetricSample::query()->insertOrIgnore([
                'server_id' => $server->getKey(),
                'collected_at' => $payload->collectedAt,
                'received_at' => $now,
                'schema_version' => $payload->schemaVersion,
                'agent_version' => $payload->agentVersion,
                'interval_seconds' => $payload->intervalSeconds,
                ...$this->metricColumns($payload),
                'payload_bytes' => $bytes,
                'payload_hash' => $hash,
                'created_at' => $now,
            ]);

            $sample = MetricSample::query()
                ->where('server_id', $server->getKey())
                ->where('collected_at', $payload->collectedAt)
                ->firstOrFail();

            if ($inserted > 0) {
                $sample->detail()->create([
                    'panel_metrics' => $payload->panelMetrics,
                    'extensions' => $payload->extensions,
                    'size_bytes' => $bytes,
                ]);
            }

            $this->updateServer($server, $payload);
            $this->touchToken($token);

            // Evaluated inside the transaction: the CPU/load history query
            // must see the stored sample, and the issue state must be written
            // atomically with the metrics that produced it.
            //
            // Detection always runs on the *newest stored* sample. A duplicate that is
            // older than that (an agent replaying a stale packet) would
            // otherwise rebuild a window of stale readings and overwrite the
            // current health with a past value; its result is returned to the
            // caller so the response still describes the current state.
            $newest = $this->newestSample($server);
            $issues = $this->detectFor($newest, $payload);
            $created = $inserted > 0;

            // Persist only when this request's sample is the newest one. An
            // older replay is a valid duplicate (202) but describes the past;
            // rewriting the server from it would regress the current health.
            if ($created || $newest->is($sample)) {
                $server->forceFill([
                    'health' => $issues->health,
                    'issue_codes' => $issues->codes,
                ])->save();
            }

            // A replayed duplicate that is *older* than what is already stored
            // did not become the current state: report the newest stored
            // sample so the response still describes what the dashboard shows.
            return [$created, $created ? $sample : $newest, $issues];
        }, 1);

        $server->refresh();
        $sample->refresh();

        return new IngestResult($created, $sample, $server, $issues);
    }

    /**
     * The newest stored sample for the server, "newest" being the largest
     * `collected_at` (the unique key with `server_id`). Deliberately not
     * `Server::latestSample()`, which orders by `latestOfMany('collected_at')`
     * and therefore resolves to the *oldest* row.
     */
    private function newestSample(Server $server): MetricSample
    {
        return MetricSample::query()
            ->where('server_id', $server->getKey())
            ->orderByDesc('collected_at')
            ->firstOrFail();
    }

    /**
     * Detect issues for a stored sample. A freshly inserted sample is judged
     * on the payload that was just validated (no extra detail read); an
     * existing one is judged on its stored detail row, because a duplicate
     * does not overwrite what the first insert saved.
     */
    private function detectFor(MetricSample $sample, IngestPayload $payload): IssueSet
    {
        if ($sample->wasRecentlyCreated) {
            return $this->issues->detect($sample->server, $sample, $payload->panelMetrics, $payload->extensions);
        }

        $detail = $sample->detail;

        return $this->issues->detect(
            $sample->server,
            $sample,
            $detail?->panel_metrics ?? [],
            $detail?->extensions ?? [],
        );
    }

    /**
     * @return array<string, int|float>
     */
    private function metricColumns(IngestPayload $payload): array
    {
        return [
            'cpu_usage_pct' => $payload->metric('cpu', 'usage_pct'),
            'cpu_iowait_pct' => $payload->metric('cpu', 'iowait_pct'),
            'cpu_cores' => $payload->metric('cpu', 'cores'),
            'load1' => $payload->metric('cpu', 'load1'),
            'load5' => $payload->metric('cpu', 'load5'),
            'load15' => $payload->metric('cpu', 'load15'),
            'mem_total_bytes' => $payload->metric('memory', 'total_bytes'),
            'mem_used_bytes' => $payload->metric('memory', 'used_bytes'),
            'mem_used_pct' => $payload->metric('memory', 'used_pct'),
            'swap_total_bytes' => $payload->metric('swap', 'total_bytes'),
            'swap_used_bytes' => $payload->metric('swap', 'used_bytes'),
            'swap_used_pct' => $payload->metric('swap', 'used_pct'),
            'disk_total_bytes' => $payload->metric('disk', 'root', 'total_bytes'),
            'disk_used_bytes' => $payload->metric('disk', 'root', 'used_bytes'),
            'disk_used_pct' => $payload->metric('disk', 'root', 'used_pct'),
            'disk_inode_pct' => $payload->metric('disk', 'root', 'inode_pct'),
            'net_rx_bps' => $payload->metric('network', 'rx_bytes_per_sec'),
            'net_tx_bps' => $payload->metric('network', 'tx_bytes_per_sec'),
            'processes_running' => $payload->metric('processes', 'running'),
            'processes_blocked' => $payload->metric('processes', 'blocked'),
            'uptime_seconds' => $payload->uptimeSeconds ?? 0,
        ];
    }

    /**
     * Advance the server from the dashboard clock, not the agent clock: a late
     * or early retry still marks the server as just seen, while
     * `last_sample_at` follows the (clamped) reported collection time.
     *
     * Optional identity fields are only overwritten when the agent reported
     * them, so a payload with `system` dropped keeps the stored OS details.
     * Health and issue codes are written by the caller after detection, so
     * this method leaves them untouched.
     */
    private function updateServer(Server $server, IngestPayload $payload): void
    {
        $now = CarbonImmutable::now('UTC');

        $staleSeconds = max((int) ceil(2.5 * $payload->intervalSeconds), self::STALE_MIN_SECONDS);
        $offlineSeconds = max(5 * $payload->intervalSeconds, self::OFFLINE_MIN_SECONDS);

        $attributes = [
            'hostname' => $payload->hostname,
            'panel' => $payload->panel,
            'interval_seconds' => $payload->intervalSeconds,
            'last_seen_at' => $now,
            'last_sample_at' => $payload->collectedAt,
            'stale_after' => $now->addSeconds($staleSeconds),
            'offline_after' => $now->addSeconds($offlineSeconds),
            ...array_filter([
                'os_name' => $payload->osName,
                'os_version' => $payload->osVersion,
                'kernel' => $payload->kernel,
                'agent_version' => $payload->agentVersion,
            ], static fn (?string $value): bool => $value !== null),
            'cpu_usage_pct' => $payload->metric('cpu', 'usage_pct'),
            'cpu_iowait_pct' => $payload->metric('cpu', 'iowait_pct'),
            'load1' => $payload->metric('cpu', 'load1'),
            'load5' => $payload->metric('cpu', 'load5'),
            'load15' => $payload->metric('cpu', 'load15'),
            'mem_used_pct' => $payload->metric('memory', 'used_pct'),
            'swap_used_pct' => $payload->metric('swap', 'used_pct'),
            'disk_used_pct' => $payload->metric('disk', 'root', 'used_pct'),
            'disk_inode_pct' => $payload->metric('disk', 'root', 'inode_pct'),
            'net_rx_bps' => $payload->metric('network', 'rx_bytes_per_sec'),
            'net_tx_bps' => $payload->metric('network', 'tx_bytes_per_sec'),
            'processes_running' => $payload->metric('processes', 'running'),
            'processes_blocked' => $payload->metric('processes', 'blocked'),
            'uptime_seconds' => $payload->uptimeSeconds ?? 0,
        ];

        $server->forceFill($attributes)->save();
    }

    /**
     * Spec §8 step 6: `last_used_at` is written at most once a minute.
     */
    private function touchToken(ServerToken $token): void
    {
        $now = CarbonImmutable::now('UTC');

        if ($token->last_used_at !== null && $token->last_used_at->greaterThan($now->subMinute())) {
            return;
        }

        $token->forceFill(['last_used_at' => $now])->save();
    }
}
