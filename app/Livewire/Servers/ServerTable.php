<?php

namespace App\Livewire\Servers;

use App\Enums\ServerHealth;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\SettingsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ServerTable extends Component
{
    use WithPagination;

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $provider = '';

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: 'health')]
    public string $sort = 'health';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    public function mount(): void
    {
        $this->normaliseFilters();
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['all', 'online', 'attention', 'stale', 'offline', 'never'], true)
            ? $status
            : 'all';

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->status = 'all';
        $this->provider = '';
        $this->q = '';
        $this->resetPage();
    }

    public function pollRefresh(): void
    {
        $this->dispatch('fleet-summary-refresh');
    }

    public function sortBy(string $column): void
    {
        $allowed = ['hostname', 'name', 'cpu', 'memory', 'disk', 'load', 'status'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = $column === 'hostname' || $column === 'name' || $column === 'status' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'provider', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $now = now()->utc()->toDateTimeString();

        $query = $this->filteredQuery($now)->select([
            'id',
            'name',
            'hostname',
            'server_type',
            'provider',
            'ip_address',
            'label',
            'interval_seconds',
            'last_seen_at',
            'last_sample_at',
            'stale_after',
            'offline_after',
            'cpu_usage_pct',
            'mem_used_pct',
            'disk_used_pct',
            'load1',
            'health',
            'issue_codes',
        ]);

        $this->applyStatus($query, $now);
        $this->applySort($query, $now);

        /** @var LengthAwarePaginator<int, Server> $servers */
        $servers = $query->paginate(25);

        $counts = $this->tabCounts($now);

        $providers = Server::query()
            ->whereNotNull('provider')
            ->where('provider', '!=', '')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();

        $settings = app(SettingsRepository::class);

        return view('livewire.servers.server-table', [
            'servers' => $servers,
            'counts' => $counts,
            'providers' => count($providers) >= 2 ? $providers : [],
            'thresholds' => [
                'memory' => [
                    'warning' => (int) $settings->get('alerts.rules.memory_high.warning', config('alerts.rules.memory_high.warning')),
                    'critical' => (int) $settings->get('alerts.rules.memory_high.critical', config('alerts.rules.memory_high.critical')),
                ],
                'disk' => [
                    'warning' => (int) $settings->get('alerts.rules.disk_high.warning', config('alerts.rules.disk_high.warning')),
                    'critical' => (int) $settings->get('alerts.rules.disk_high.critical', config('alerts.rules.disk_high.critical')),
                ],
            ],
            'totalServers' => Server::query()->count(),
        ]);
    }

    public function displayState(Server $server): string
    {
        $status = $server->status();

        if ($status === ServerStatus::Online
            && in_array($server->health, [ServerHealth::Warning, ServerHealth::Critical], true)) {
            return $server->health->value;
        }

        return $status->value;
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : round($value).'%';
    }

    /**
     * @param  array{warning:int,critical:int}  $thresholds
     */
    public function usageTone(?float $value, array $thresholds): string
    {
        if ($value === null) {
            return 'muted';
        }

        if ($value >= $thresholds['critical']) {
            return 'critical';
        }

        if ($value >= $thresholds['warning']) {
            return 'warning';
        }

        return 'ok';
    }

    public function usageBarColor(string $tone): string
    {
        return match ($tone) {
            'critical' => 'bg-critical',
            'warning' => 'bg-warning',
            default => 'bg-ok',
        };
    }

    public function usageWidth(?float $value): string
    {
        return $value === null ? '0%' : max(0, min(100, round($value, 1))).'%';
    }

    public function load(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }

    public function sampleAge(\DateTimeInterface|string|null $value): string
    {
        if ($value === null) {
            return 'No sample';
        }

        return $this->asCarbon($value)->diffForHumans(short: true);
    }

    public function sampleClock(\DateTimeInterface|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return $this->asCarbon($value)->timezone('Asia/Jakarta')->format('H:i:s');
    }

    /**
     * @return list<string>
     */
    public function issueLabels(Server $server): array
    {
        $labels = [
            'server_offline' => 'OFFLINE',
            'server_stale' => 'STALE',
            'cpu_high' => 'CPU',
            'memory_high' => 'MEM',
            'disk_high' => 'DISK',
            'inode_high' => 'INODE',
            'load_high' => 'LOAD',
            'smart_failed' => 'SMART',
            'smart_warning' => 'SMART W',
            'service_down' => 'SERVICE',
            'mysql_unreachable' => 'MYSQL',
            'quota_over' => 'QUOTA',
            'module_cache_stale' => 'STALE',
            'security_disabled' => 'SECURITY',
        ];

        return collect($server->issue_codes ?? [])
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->map(fn (string $code): string => $labels[$code] ?? strtoupper(str_replace('_', ' ', $code)))
            ->unique()
            ->values()
            ->all();
    }

    private function normaliseFilters(): void
    {
        $this->status = in_array($this->status, ['all', 'online', 'attention', 'stale', 'offline', 'never'], true)
            ? $this->status
            : 'all';

        $this->sort = in_array($this->sort, ['health', 'hostname', 'name', 'cpu', 'memory', 'disk', 'load', 'status'], true)
            ? $this->sort
            : 'health';

        $this->dir = $this->dir === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @return Builder<Server>
     */
    private function filteredQuery(string $now): Builder
    {
        $query = Server::query();

        $term = trim($this->q);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(function (Builder $query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('label', 'like', $like)
                    ->orWhere('server_type', 'like', $like)
                    ->orWhere('provider', 'like', $like);
            });
        }

        if ($this->provider !== '') {
            $query->where('provider', $this->provider);
        }

        return $query;
    }

    /**
     * @param  Builder<Server>  $query
     */
    private function applyStatus(Builder $query, string $now): void
    {
        match ($this->status) {
            'online' => $query
                ->whereNotNull('last_seen_at')
                ->whereNotNull('stale_after')
                ->whereNotNull('offline_after')
                ->where('stale_after', '>=', $now),
            'attention' => $query->whereIn('health', ['warning', 'critical']),
            'stale' => $query
                ->whereNotNull('last_seen_at')
                ->whereNotNull('stale_after')
                ->whereNotNull('offline_after')
                ->where('stale_after', '<', $now)
                ->where('offline_after', '>=', $now),
            'offline' => $query
                ->whereNotNull('last_seen_at')
                ->where(function (Builder $query) use ($now): void {
                    $query->whereNull('stale_after')
                        ->orWhereNull('offline_after')
                        ->orWhere(function (Builder $query) use ($now): void {
                            $query->where('offline_after', '<', $now)
                                ->where('stale_after', '<', $now);
                        });
                }),
            'never' => $query->whereNull('last_seen_at'),
            default => null,
        };
    }

    /**
     * @param  Builder<Server>  $query
     */
    private function applySort(Builder $query, string $now): void
    {
        $direction = $this->dir === 'desc' ? 'desc' : 'asc';

        if ($this->sort === 'health') {
            $query->orderByRaw(
                "CASE health WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END {$direction}"
            );
        } elseif ($this->sort === 'status') {
            $query->orderByRaw(
                "CASE
                    WHEN last_seen_at IS NULL THEN 4
                    WHEN stale_after IS NULL OR offline_after IS NULL OR offline_after < ? THEN 3
                    WHEN stale_after < ? THEN 2
                    ELSE 0
                END {$direction}",
                [$now, $now],
            );
        } else {
            $column = match ($this->sort) {
                'cpu' => 'cpu_usage_pct',
                'memory' => 'mem_used_pct',
                'disk' => 'disk_used_pct',
                'load' => 'load1',
                'name' => 'name',
                default => 'hostname',
            };

            $query->orderBy($column, $direction);
        }

        if ($this->sort !== 'hostname') {
            $query->orderBy('hostname');
        }
    }

    /**
     * @return array{all:int,online:int,attention:int,stale:int,offline:int,never:int}
     */
    private function tabCounts(string $now): array
    {
        $row = $this->filteredQuery($now)
            ->toBase()
            ->selectRaw(
                <<<'SQL'
                COUNT(*) AS all_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND stale_after IS NOT NULL
                        AND offline_after IS NOT NULL
                        AND stale_after >= ?
                    THEN 1 ELSE 0 END), 0) AS online_count,
                COALESCE(SUM(CASE WHEN health IN ('warning', 'critical') THEN 1 ELSE 0 END), 0) AS attention_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND stale_after IS NOT NULL
                        AND offline_after IS NOT NULL
                        AND stale_after < ?
                        AND offline_after >= ?
                    THEN 1 ELSE 0 END), 0) AS stale_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND (
                            stale_after IS NULL
                            OR offline_after IS NULL
                            OR (offline_after < ? AND stale_after < ?)
                        )
                    THEN 1 ELSE 0 END), 0) AS offline_count,
                COALESCE(SUM(CASE WHEN last_seen_at IS NULL THEN 1 ELSE 0 END), 0) AS never_count
                SQL,
                [$now, $now, $now, $now, $now],
            )
            ->first();

        return [
            'all' => (int) $row->all_count,
            'online' => (int) $row->online_count,
            'attention' => (int) $row->attention_count,
            'stale' => (int) $row->stale_count,
            'offline' => (int) $row->offline_count,
            'never' => (int) $row->never_count,
        ];
    }

    private function asCarbon(\DateTimeInterface|string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }
}
