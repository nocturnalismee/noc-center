<?php

namespace App\Livewire\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    /**
     * @var list<string>
     */
    private const STATUSES = ['open', 'acknowledged', 'resolved', 'all'];

    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: 'all')]
    public string $severity = 'all';

    #[Url(except: '')]
    public string $serverId = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Alert::class);
    }

    public function pollRefresh(): void
    {
        // A no-op render target for the Livewire polling safety net.
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'severity', 'serverId'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->severity = 'all';
        $this->serverId = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $status = in_array($this->status, self::STATUSES, true) ? $this->status : 'open';
        $severity = in_array($this->severity, ['warning', 'critical'], true) ? $this->severity : 'all';

        $query = Alert::query()
            ->with('server')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($severity !== 'all', fn ($query) => $query->where('severity', $severity))
            ->when($this->serverId !== '', fn ($query) => $query->where('server_id', $this->serverId))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        $counts = Alert::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('livewire.alerts.index', [
            'alerts' => $query->paginate(25),
            'status' => $status,
            'severity' => $severity,
            'counts' => [
                'open' => (int) ($counts[AlertStatus::Open->value] ?? 0),
                'acknowledged' => (int) ($counts[AlertStatus::Acknowledged->value] ?? 0),
                'resolved' => (int) ($counts[AlertStatus::Resolved->value] ?? 0),
                'all' => (int) $counts->sum(),
                'critical' => Alert::query()->where('severity', AlertSeverity::Critical->value)->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])->count(),
            ],
            'servers' => Server::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
