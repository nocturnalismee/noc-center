<?php

namespace App\Services\Ingest;

use App\Enums\ServerHealth;
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
 * `last_seen_at`, thresholds) because the agent proved it is alive; only the
 * sample and its detail row are left untouched.
 */
class StoreSample
{
    /**
     * Spec §10: `stale_after = last_seen_at + max(2.5 × interval, 150 s)` and
     * `offline_after = last_seen_at + max(5 × interval, 300 s)`.
     */
    private const STALE_MIN_SECONDS = 150;

    private const OFFLINE_MIN_SECONDS = 300;

    public function __construct(private readonly PayloadValidator $validator)
    {
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

        [$created, $sample] = DB::transaction(function () use ($server, $token, $payload, $bytes, $hash): array {
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

            return [$inserted > 0, $sample];
        }, 1);

        $server->refresh();
        $sample->refresh();

        return new IngestResult($created, $sample, $server);
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
     * Health and issue codes stay `ok`/`[]` until Task 8 supplies an issue set.
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
            'health' => ServerHealth::Ok,
            'issue_codes' => [],
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
