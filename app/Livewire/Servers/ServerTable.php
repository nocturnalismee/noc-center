<?php

namespace App\Livewire\Servers;

use App\Enums\AlertRule;
use App\Enums\ServerHealth;
use App\Enums\ServerStatus;
use App\Fleet\FleetState;
use App\Models\Server;
use App\Services\SettingsRepository;
use App\Support\MetricFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $this->status = in_array($status, FleetState::TABS, true)
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
        $now = now()->utc();

        $query = $this->filteredQuery()->select([
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

        FleetState::applyStatus($query, $this->status, $now);
        $this->applySort($query, $now);

        /** @var LengthAwarePaginator<int, Server> $servers */
        $servers = $query->paginate(25);

        $counts = $this->tabCounts($now);

        $providers = Cache::remember('fleet.providers', now()->addMinute(), fn (): array => Server::query()
            ->whereNotNull('provider')
            ->where('provider', '!=', '')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all());

        // The table renders memory and disk bars only; CPU is not read here.
        $thresholds = FleetState::usageThresholds(app(SettingsRepository::class), ['memory', 'disk']);

        return view('livewire.servers.server-table', [
            'servers' => $servers,
            'counts' => $counts,
            'providers' => count($providers) >= 2 ? $providers : [],
            'thresholds' => $thresholds,
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
        return MetricFormatter::percent($value);
    }

    /**
     * @param  array{warning:int,critical:int}  $thresholds
     */
    public function usageTone(?float $value, array $thresholds): string
    {
        return MetricFormatter::tone($value, $thresholds);
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
        return MetricFormatter::load($value);
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
        return AlertRule::labels($server->issue_codes ?? [], true);
    }

    private function normaliseFilters(): void
    {
        $this->status = in_array($this->status, FleetState::TABS, true)
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
    private function filteredQuery(): Builder
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
    private function applySort(Builder $query, CarbonInterface $now): void
    {
        $direction = $this->dir === 'desc' ? 'desc' : 'asc';

        if ($this->sort === 'health') {
            $query->orderByRaw(
                "CASE health WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END {$direction}"
            );
        } elseif ($this->sort === 'status') {
            FleetState::applyStatusSort($query, $direction, $now);
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
    private function tabCounts(CarbonInterface $now): array
    {
        $cacheKey = 'fleet.tab-counts.'.hash('sha256', serialize([
            'provider' => $this->provider,
            'query' => trim($this->q),
        ]));

        return Cache::remember($cacheKey, now()->addSeconds(10), fn (): array => FleetState::counts($this->filteredQuery(), $now));
    }

    private function asCarbon(\DateTimeInterface|string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }
}
