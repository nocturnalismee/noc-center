<?php

namespace App\Servers;

use App\Enums\AlertRule;
use App\Models\MetricSample;
use App\Models\Server;
use App\Support\MetricCatalog;
use App\Support\MetricFormatter;

/**
 * Presentation for one server's detail page: the headline metric strip, the
 * chart definitions, the ITSM facts, the last-seen line and the issue labels.
 *
 * Everything here is derived from the server and its latest sample; the
 * Livewire component only orchestrates loading and tab/range normalisation.
 * Labels stay escaped by Blade, so unknown keys are never trusted as markup.
 */
final class ServerDetailPresenter
{
    public function __construct(private readonly Server $server) {}

    /**
     * @return list<array{label:string, value:string, meta:string}>
     */
    public function metrics(?MetricSample $sample): array
    {
        if ($sample === null) {
            return array_map(
                fn (array $metric): array => [
                    'label' => $metric['label'],
                    'value' => '—',
                    'meta' => 'No sample yet',
                ],
                array_values(MetricCatalog::serverMetrics()),
            );
        }

        $rx = MetricFormatter::rateParts($sample->net_rx_bps);
        $tx = MetricFormatter::rateParts($sample->net_tx_bps);

        return [
            [
                'label' => $this->metricLabel('cpu'),
                'value' => MetricFormatter::percent($sample->cpu_usage_pct),
                'meta' => $sample->cpu_cores.' cores · iowait '.MetricFormatter::percent($sample->cpu_iowait_pct),
            ],
            [
                'label' => $this->metricLabel('memory'),
                'value' => MetricFormatter::percent($sample->mem_used_pct),
                'meta' => $this->usedTotal($sample->mem_used_bytes, $sample->mem_total_bytes),
            ],
            [
                'label' => $this->metricLabel('disk'),
                'value' => MetricFormatter::percent($sample->disk_used_pct),
                'meta' => $this->usedTotal($sample->disk_used_bytes, $sample->disk_total_bytes),
            ],
            [
                'label' => $this->metricLabel('load'),
                'value' => MetricFormatter::load($sample->load1),
                'meta' => '5m '.MetricFormatter::load($sample->load5).' · 15m '.MetricFormatter::load($sample->load15),
            ],
            [
                'label' => $this->metricLabel('network'),
                'value' => $rx['value'].' / '.$tx['value'],
                'meta' => $rx['unit'].'/s in · '.$tx['unit'].'/s out',
            ],
            [
                'label' => $this->metricLabel('uptime'),
                'value' => MetricFormatter::uptime($sample->uptime_seconds),
                'meta' => 'Since '.$this->since($sample),
            ],
        ];
    }

    /**
     * @return array<string, array{key:string,title:string,subtitle:string,unit:string,series:list<array{metric:string,label:string,color:string,url:string,axis?:string,format?:string}>,summary:string}>
     */
    public function charts(string $tab, string $range, ?MetricSample $sample): array
    {
        if ($tab === 'overview') {
            return [
                'resources' => $this->chart(
                    'resources',
                    'Resource summary',
                    'Last hour · '.(int) $this->server->interval_seconds.'s collector samples · WIB',
                    'percent',
                    $this->seriesLines($range, [
                        ['metric' => 'cpu', 'label' => 'CPU', 'color' => '--chart-cpu'],
                        ['metric' => 'memory', 'label' => 'Memory', 'color' => '--chart-memory'],
                        ['metric' => 'disk', 'label' => 'Disk', 'color' => '--chart-disk'],
                    ]),
                    $this->summary('resources', $sample),
                ),
                'load-network' => $this->chart(
                    'load-network',
                    'Load and network',
                    'Last hour · load 1m and network throughput · WIB',
                    'mixed',
                    $this->seriesLines($range, [
                        ['metric' => 'load', 'label' => 'Load 1m', 'color' => '--chart-load', 'axis' => 'y', 'format' => 'load'],
                        ['metric' => 'rx', 'label' => 'Network in', 'color' => '--chart-network', 'axis' => 'y1', 'format' => 'rate'],
                        ['metric' => 'tx', 'label' => 'Network out', 'color' => '--chart-network-out', 'axis' => 'y1', 'format' => 'rate'],
                    ]),
                    $this->summary('load-network', $sample),
                ),
            ];
        }

        return [
            'cpu' => $this->chart(
                'cpu',
                'CPU',
                (int) $this->server->interval_seconds.'s collector · percent · WIB',
                'percent',
                $this->seriesLines($range, [
                    ['metric' => 'cpu', 'label' => 'CPU', 'color' => '--chart-cpu'],
                ]),
                $this->summary('cpu', $sample),
            ),
            'memory' => $this->chart(
                'memory',
                'Memory',
                (int) $this->server->interval_seconds.'s collector · used bytes · WIB',
                'bytes',
                $this->seriesLines($range, [
                    ['metric' => 'memory_bytes', 'label' => 'Memory used', 'color' => '--chart-memory'],
                ]),
                $this->summary('memory', $sample),
            ),
            'disk' => $this->chart(
                'disk',
                'Disk',
                (int) $this->server->interval_seconds.'s collector · used bytes · WIB',
                'bytes',
                $this->seriesLines($range, [
                    ['metric' => 'disk_bytes', 'label' => 'Disk used', 'color' => '--chart-disk'],
                ]),
                $this->summary('disk', $sample),
            ),
            'load' => $this->chart(
                'load',
                'Load',
                (int) $this->server->interval_seconds.'s collector · 1m load average · WIB',
                'load',
                $this->seriesLines($range, [
                    ['metric' => 'load', 'label' => 'Load 1m', 'color' => '--chart-load'],
                ]),
                $this->summary('load', $sample),
            ),
            'network' => $this->chart(
                'network',
                'Network',
                (int) $this->server->interval_seconds.'s collector · bytes per second · WIB',
                'rate',
                $this->seriesLines($range, [
                    ['metric' => 'rx', 'label' => 'Network in', 'color' => '--chart-network'],
                    ['metric' => 'tx', 'label' => 'Network out', 'color' => '--chart-network-out'],
                ]),
                $this->summary('network', $sample),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function itsm(?MetricSample $sample): array
    {
        return [
            'Hostname' => $this->server->hostname,
            'Primary IP' => $this->server->ip_address ?: '—',
            'Asset label' => $this->server->label ?: '—',
            'Type' => $this->server->server_type ?: '—',
            'Provider' => $this->server->provider ?: '—',
            'Location' => $this->server->location ?: '—',
            'Panel' => $this->server->panel ?: '—',
            'Agent' => $this->server->agent_version ? 'v'.$this->server->agent_version : '—',
            'Interval' => $this->server->interval_seconds.'s',
            'Last sample' => $sample === null
                ? 'No sample yet'
                : $sample->collected_at->diffForHumans(short: true),
            'OS' => trim(($this->server->os_name ?? '').' '.($this->server->os_version ?? '')) ?: '—',
            'Kernel' => $this->server->kernel ?: '—',
        ];
    }

    public function lastSeen(): string
    {
        if ($this->server->last_seen_at === null) {
            return 'Never seen';
        }

        return 'Last seen '.$this->server->last_seen_at->diffForHumans(short: true)
            .' · '.$this->server->last_seen_at->timezone('Asia/Jakarta')->format('H:i:s').' WIB';
    }

    /**
     * @return list<string>
     */
    public function issueLabels(): array
    {
        return AlertRule::labels($this->server->issue_codes ?? []);
    }

    private function metricLabel(string $key): string
    {
        return MetricCatalog::serverMetric($key)['label'];
    }

    /**
     * @param  list<array{metric:string,label:string,color:string,axis?:string,format?:string}>  $definitions
     * @return list<array{metric:string,label:string,color:string,url:string,axis?:string,format?:string}>
     */
    private function seriesLines(string $range, array $definitions): array
    {
        return collect($definitions)->map(fn (array $series): array => [
            ...$series,
            'url' => route('servers.series', [
                'server' => $this->server,
                'metric' => $series['metric'],
                'range' => $range,
            ]),
        ])->all();
    }

    /**
     * @param  list<array{metric:string,label:string,color:string,url:string,axis?:string,format?:string}>  $series
     * @return array{key:string,title:string,subtitle:string,unit:string,series:list<array{metric:string,label:string,color:string,url:string,axis?:string,format?:string}>,summary:string}
     */
    private function chart(
        string $key,
        string $title,
        string $subtitle,
        string $unit,
        array $series,
        string $summary,
    ): array {
        return compact('key', 'title', 'subtitle', 'unit', 'series', 'summary');
    }

    private function summary(string $key, ?MetricSample $sample): string
    {
        if ($sample === null) {
            return 'No samples recorded yet.';
        }

        $rx = MetricFormatter::rateParts($sample->net_rx_bps);
        $tx = MetricFormatter::rateParts($sample->net_tx_bps);

        return match ($key) {
            'cpu' => 'Current CPU '.MetricFormatter::percent($sample->cpu_usage_pct).' · '.$sample->cpu_cores.' cores.',
            'memory' => 'Current memory '.MetricFormatter::bytes($sample->mem_used_bytes).' of '.MetricFormatter::bytes($sample->mem_total_bytes).'.',
            'disk' => 'Current disk '.MetricFormatter::bytes($sample->disk_used_bytes).' of '.MetricFormatter::bytes($sample->disk_total_bytes).'.',
            'load' => 'Current load '.MetricFormatter::load($sample->load1).' · 5m '.MetricFormatter::load($sample->load5).' · 15m '.MetricFormatter::load($sample->load15).'.',
            'network' => 'Current '.$rx['value'].' '.$rx['unit'].'/s in · '.$tx['value'].' '.$tx['unit'].'/s out.',
            'resources' => 'Current CPU '.MetricFormatter::percent($sample->cpu_usage_pct).', memory '.MetricFormatter::percent($sample->mem_used_pct).', disk '.MetricFormatter::percent($sample->disk_used_pct).'. Last hour.',
            'load-network' => 'Current load '.MetricFormatter::load($sample->load1).' · '.$rx['value'].' '.$rx['unit'].'/s in · '.$tx['value'].' '.$tx['unit'].'/s out.',
            default => 'No samples recorded yet.',
        };
    }

    private function usedTotal(?int $used, ?int $total): string
    {
        if ($used === null || $total === null) {
            return '—';
        }

        return MetricFormatter::gigabytes($used).' / '.MetricFormatter::gigabytes($total);
    }

    private function since(MetricSample $sample): string
    {
        return $sample->collected_at
            ->copy()
            ->subSeconds($sample->uptime_seconds)
            ->timezone('Asia/Jakarta')
            ->format('d M Y');
    }
}
