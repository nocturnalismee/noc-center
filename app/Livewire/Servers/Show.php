<?php

namespace App\Livewire\Servers;

use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerDetailPresenter;
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

        $presenter = new ServerDetailPresenter($this->server);

        return view('livewire.servers.show', [
            'tab' => $tab,
            'range' => $range,
            'chartRange' => $chartRange,
            'ranges' => $ranges,
            'sample' => $sample,
            'metrics' => $presenter->metrics($sample),
            'charts' => in_array($tab, ['overview', 'performance'], true)
                ? $presenter->charts($tab, $chartRange, $sample)
                : [],
            'alerts' => $alerts,
            'history' => $history,
            'actors' => $actors,
            'canViewHistory' => $canViewHistory,
            'canOperate' => auth()->user()?->canOperate() ?? false,
            'canViewSnapshot' => auth()->user()?->can('viewSnapshot', $this->server) ?? false,
            'lastSeen' => $presenter->lastSeen(),
            'statusState' => $this->server->status()->value,
            'issueLabels' => $presenter->issueLabels(),
            'itsm' => $presenter->itsm($sample),
            'panel' => $panel,
        ]);
    }

    public function pollRefresh(): void {}

    public function statusLabel(): string
    {
        return $this->server->status()->label();
    }
}
