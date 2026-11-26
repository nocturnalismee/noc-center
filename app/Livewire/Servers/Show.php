<?php

namespace App\Livewire\Servers;

use App\Enums\ServerStatus;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Support\MetricPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Server $server;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(except: '24h')]
    public string $range = '24h';

    private const TABS = ['overview', 'performance', 'panel', 'modules', 'alerts', 'history'];

    public function mount(Server $server): void
    {
        Gate::authorize('view', $server);

        $this->server = $server;
    }

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $ranges = array_keys(config('metrics.ranges', []));
        $range = in_array($this->range, $ranges, true) ? $this->range : '24h';
        $chartRange = $tab === 'overview' ? '1h' : $range;

        /** @var MetricSample|null $sample */
        $sample = $this->server->latestSample()->first();

        $alerts = collect();
        $history = collect();
        $actors = collect();
        $panel = null;
        $canViewHistory = Gate::allows('viewAny', AuditEvent::class);

        if (in_array($tab, ['panel', 'modules'], true)) {
            $detail = $sample?->detail()->first();

            $panel = MetricPresenter::groups(array_merge(
                $detail?->panel_metrics ?? [],
                $detail?->extensions ?? [],
            ));
        }

        if ($tab === 'alerts') {
            $alerts = $this->server->alerts()
                ->latest('last_seen_at')
                ->limit(25)
                ->get();
        }

        if ($tab === 'history' && $canViewHistory) {
            $history = AuditEvent::query()
                ->where('target_type', $this->server->getMorphClass())
                ->where('target_id', $this->server->getKey())
                ->latest('id')
                ->limit(25)
                ->get();

            $actors = User::query()
                ->whereIn('id', $history->pluck('user_id')->filter()->unique())
                ->pluck('name', 'id');
        }

        return view('livewire.servers.show', [
            'tab' => $tab,
            'range' => $range,
            'chartRange' => $chartRange,
            'ranges' => $ranges,
            'sample' => $sample,
            'metrics' => $this->metrics($sample),
            'charts' => in_array($tab, ['overview', 'performance'], true)
                ? $this->charts($tab, $chartRange)
                : [],
            'alerts' => $alerts,
            'history' => $history,
            'actors' => $actors,
            'canViewHistory' => $canViewHistory,
            'canOperate' => auth()->user()?->canOperate() ?? false,
            'canViewSnapshot' => auth()->user()?->can('viewSnapshot', $this->server) ?? false,
            'lastSeen' => $this->lastSeen(),
            'statusState' => $this->server->status()->value,
            'issueLabels' => $this->issueLabels(),
            'itsm' => $this->itsm($sample),
            'panel' => $panel,
        ]);
    }

    public function pollRefresh(): void {}

    public function displayStatus(): string
    {
        return $this->server->status()->value;
    }

    public function statusLabel(): string
    {
        return match ($this->server->status()) {
            ServerStatus::NeverSeen => 'Never seen',
            ServerStatus::Online => 'Online',
            ServerStatus::Stale => 'Stale',
            ServerStatus::Offline => 'Offline',
        };
    }

    /**
     * @return list<array{label:string,value:string,meta:string}>
     */
    private function metrics(?MetricSample $sample): array
    {
        if ($sample === null) {
            return collect(['CPU', 'Memory', 'Disk', 'Load', 'Network', 'Uptime'])
                ->map(fn (string $label): array => [
                    'label' => $label,
                    'value' => '—',
                    'meta' => 'No sample yet',
                ])
                ->all();
        }

        return [
            [
                'label' => 'CPU',
                'value' => $this->pct($sample->cpu_usage_pct),
                'meta' => $sample->cpu_cores.' cores · iowait '.$this->pct($sample->cpu_iowait_pct),
            ],
            [
                'label' => 'Memory',
                'value' => $this->pct($sample->mem_used_pct),
                'meta' => $this->usedTotal($sample->mem_used_bytes, $sample->mem_total_bytes),
            ],
            [
                'label' => 'Disk',
                'value' => $this->pct($sample->disk_used_pct),
                'meta' => $this->usedTotal($sample->disk_used_bytes, $sample->disk_total_bytes),
            ],
            [
                'label' => 'Load',
                'value' => number_format($sample->load1, 2),
                'meta' => '5m '.number_format($sample->load5, 2).' · 15m '.number_format($sample->load15, 2),
            ],
            [
                'label' => 'Network',
                'value' => $this->rateCompact($sample->net_rx_bps).' / '.$this->rateCompact($sample->net_tx_bps),
                'meta' => $this->rateUnit($sample->net_rx_bps).'/s in · '.$this->rateUnit($sample->net_tx_bps).'/s out',
            ],
            [
                'label' => 'Uptime',
                'value' => $this->uptime($sample->uptime_seconds),
                'meta' => 'Since '.$this->since($sample),
            ],
        ];
    }

    /**
     * @return array<string, array{key:string,title:string,subtitle:string,unit:string,series:list<array{metric:string,label:string,color:string,url:string,axis?:string,format?:string}>,summary:string}>
     */
    private function charts(string $tab, string $range): array
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
                    $this->summary('resources'),
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
                    $this->summary('load-network'),
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
                $this->summary('cpu'),
            ),
            'memory' => $this->chart(
                'memory',
                'Memory',
                (int) $this->server->interval_seconds.'s collector · used bytes · WIB',
                'bytes',
                $this->seriesLines($range, [
                    ['metric' => 'memory_bytes', 'label' => 'Memory used', 'color' => '--chart-memory'],
                ]),
                $this->summary('memory'),
            ),
            'disk' => $this->chart(
                'disk',
                'Disk',
                (int) $this->server->interval_seconds.'s collector · used bytes · WIB',
                'bytes',
                $this->seriesLines($range, [
                    ['metric' => 'disk_bytes', 'label' => 'Disk used', 'color' => '--chart-disk'],
                ]),
                $this->summary('disk'),
            ),
            'load' => $this->chart(
                'load',
                'Load',
                (int) $this->server->interval_seconds.'s collector · 1m load average · WIB',
                'load',
                $this->seriesLines($range, [
                    ['metric' => 'load', 'label' => 'Load 1m', 'color' => '--chart-load'],
                ]),
                $this->summary('load'),
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
                $this->summary('network'),
            ),
        ];
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

    private function summary(string $key): string
    {
        $sample = $this->server->latestSample()->first();

        if ($sample === null) {
            return 'No samples recorded yet.';
        }

        return match ($key) {
            'cpu' => 'Current CPU '.$this->pct($sample->cpu_usage_pct).' · '.$sample->cpu_cores.' cores.',
            'memory' => 'Current memory '.$this->bytes($sample->mem_used_bytes).' of '.$this->bytes($sample->mem_total_bytes).'.',
            'disk' => 'Current disk '.$this->bytes($sample->disk_used_bytes).' of '.$this->bytes($sample->disk_total_bytes).'.',
            'load' => 'Current load '.number_format($sample->load1, 2).' · 5m '.number_format($sample->load5, 2).' · 15m '.number_format($sample->load15, 2).'.',
            'network' => 'Current '.$this->rateCompact($sample->net_rx_bps).' '.$this->rateUnit($sample->net_rx_bps).'/s in · '.$this->rateCompact($sample->net_tx_bps).' '.$this->rateUnit($sample->net_tx_bps).'/s out.',
            'resources' => 'Current CPU '.$this->pct($sample->cpu_usage_pct).', memory '.$this->pct($sample->mem_used_pct).', disk '.$this->pct($sample->disk_used_pct).'. Last hour.',
            'load-network' => 'Current load '.number_format($sample->load1, 2).' · '.$this->rateCompact($sample->net_rx_bps).' '.$this->rateUnit($sample->net_rx_bps).'/s in · '.$this->rateCompact($sample->net_tx_bps).' '.$this->rateUnit($sample->net_tx_bps).'/s out.',
            default => 'No samples recorded yet.',
        };
    }

    private function lastSeen(): string
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
    private function issueLabels(): array
    {
        $labels = [
            'server_offline' => 'Server offline',
            'server_stale' => 'Data is stale',
            'cpu_high' => 'CPU high',
            'memory_high' => 'Memory high',
            'disk_high' => 'Disk almost full',
            'inode_high' => 'Inodes high',
            'load_high' => 'Load high',
            'smart_failed' => 'SMART failed',
            'smart_warning' => 'SMART warning',
            'service_down' => 'Service down',
            'mysql_unreachable' => 'MySQL unreachable',
            'quota_over' => 'Quota exceeded',
            'module_cache_stale' => 'Module cache stale',
            'security_disabled' => 'Security layer disabled',
        ];

        return collect($this->server->issue_codes ?? [])
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->map(fn (string $code): string => $labels[$code] ?? str_replace('_', ' ', ucfirst($code)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function itsm(?MetricSample $sample): array
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

    public function pct(?float $value): string
    {
        return $value === null ? '—' : round($value).'%';
    }

    private function usedTotal(?int $used, ?int $total): string
    {
        if ($used === null || $total === null) {
            return '—';
        }

        return $this->gigabytes($used).' / '.$this->gigabytes($total);
    }

    private function gigabytes(int $bytes): string
    {
        $gb = $bytes / 1024 ** 3;

        return ($gb >= 10 ? number_format($gb, 1) : number_format($gb, 2)).' GB';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);

        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.').' '.$units[$unit];
    }

    private function rateCompact(int $bps): string
    {
        return number_format($this->rateValue($bps), $this->rateValue($bps) >= 10 ? 1 : 2);
    }

    private function rateUnit(int $bps): string
    {
        return match (true) {
            $bps >= 1024 ** 3 => 'GB',
            $bps >= 1024 ** 2 => 'MB',
            $bps >= 1024 => 'KB',
            default => 'B',
        };
    }

    private function rateValue(int $bps): float
    {
        return match (true) {
            $bps >= 1024 ** 3 => $bps / 1024 ** 3,
            $bps >= 1024 ** 2 => $bps / 1024 ** 2,
            $bps >= 1024 => $bps / 1024,
            default => (float) $bps,
        };
    }

    private function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.'d '.$hours.'h';
        }

        if ($hours > 0) {
            return $hours.'h '.$minutes.'m';
        }

        return $minutes.'m';
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
