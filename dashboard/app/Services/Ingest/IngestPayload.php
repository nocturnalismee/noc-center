<?php

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

/**
 * A validated schema-v1 agent payload.
 *
 * The DTO is the only thing the rest of ingest sees: it is a readonly value
 * object with no array access and no mutators, so validation results cannot
 * drift between validation and storage. Unknown fields were dropped; the flat
 * maps keep their scalar values (string, int, float, bool or null) verbatim.
 *
 * `collectedAt` is the clamped collection time (dashboard clock ± 10 minutes)
 * and `receivedAt` is the dashboard clock at ingest, both in UTC.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class IngestPayload implements Arrayable
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, string|int|float|bool|null>  $panelMetrics
     * @param  array<string, string|int|float|bool|null>  $extensions
     */
    public function __construct(
        public int $schemaVersion,
        public string $agentId,
        public string $hostname,
        public string $panel,
        public CarbonImmutable $collectedAt,
        public CarbonImmutable $receivedAt,
        public int $intervalSeconds,
        public ?string $osName,
        public ?string $osVersion,
        public ?string $kernel,
        public ?string $agentVersion,
        public ?int $uptimeSeconds,
        public array $metrics,
        public array $panelMetrics,
        public array $extensions,
    ) {
        //
    }

    /**
     * The raw JSON the DTO was built from is not retained, so the array view
     * is metadata only. It exists for logging/diagnostics, not for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'agent_id' => $this->agentId,
            'hostname' => $this->hostname,
            'panel' => $this->panel,
            'collected_at' => $this->collectedAt->toIso8601String(),
            'received_at' => $this->receivedAt->toIso8601String(),
            'interval_seconds' => $this->intervalSeconds,
            'os_name' => $this->osName,
            'os_version' => $this->osVersion,
            'kernel' => $this->kernel,
            'agent_version' => $this->agentVersion,
            'uptime_seconds' => $this->uptimeSeconds,
            'panel_metrics_keys' => count($this->panelMetrics),
            'extension_keys' => count($this->extensions),
        ];
    }

    /**
     * A denormalised metric from the core `metrics` object.
     */
    public function metric(string ...$path): int|float
    {
        $value = Arr::get($this->metrics, implode('.', $path));

        return is_int($value) || is_float($value) ? $value : 0;
    }
}
