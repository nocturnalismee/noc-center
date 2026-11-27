<?php

namespace App\Livewire\Settings;

use App\Models\Server;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Servers\ServerService;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Deleted servers: retention policy and manual purge (spec §9.5).
 *
 * Soft delete keeps a server and its history so an administrator can inspect
 * it before it disappears. This admin-only tab closes the loop: it edits the
 * `retention.deleted_server_days` grace period consumed by
 * `noc:purge-deleted-servers`, and offers a manual purge that requires the
 * exact typed server name after previewing the cascade counts.
 *
 * The purge itself is delegated to `ServerService::purge()` with the
 * `manual` trigger, so the audit event, the cascade and the token revocation
 * match `noc:purge-server` exactly. The page is double-gated:
 * `SettingPolicy::viewAny()` on mount and on every render, and
 * `ServerPolicy::purge()` on the action itself, so a stale component left in
 * an operator session is denied too.
 */
#[Layout('layouts.app')]
class DeletedServers extends Component
{
    use WithPagination;

    public string $graceDays = '30';

    public ?string $statusMessage = null;

    public ?string $confirmingId = null;

    public string $confirmName = '';

    /**
     * @var array{samples: int, details: int, rollups: int, alerts: int, deliveries: int, tokens: int}|array{}
     */
    public array $confirmCounts = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Setting::class);

        $this->graceDays = (string) app(SettingsRepository::class)
            ->get('retention.deleted_server_days', config('retention.deleted_server_days'));
    }

    public function savePolicy(): void
    {
        Gate::authorize('viewAny', Setting::class);

        $this->validate(['graceDays' => ['required', 'integer', 'between:0,3650']]);

        $settings = app(SettingsRepository::class);
        $current = (int) $settings->get('retention.deleted_server_days', config('retention.deleted_server_days'));
        $next = (int) $this->graceDays;

        if ($current !== $next) {
            $settings->set('retention.deleted_server_days', $next, 'int');
            app(AuditLogger::class)->log('settings.updated', null, [
                'key' => 'retention.deleted_server_days',
            ]);
        }

        $this->statusMessage = 'Retention policy saved.';
    }

    public function startPurge(string $serverId): void
    {
        $server = Server::onlyTrashed()->findOrFail($serverId);
        Gate::authorize('purge', $server);

        $this->confirmingId = $serverId;
        $this->confirmName = '';
        $this->confirmCounts = app(ServerService::class)->purgeCounts($server);
        $this->resetErrorBag();
    }

    public function cancelPurge(): void
    {
        $this->confirmingId = null;
        $this->confirmName = '';
        $this->confirmCounts = [];
        $this->resetErrorBag();
    }

    public function purge(): void
    {
        $server = Server::onlyTrashed()->findOrFail((string) $this->confirmingId);
        Gate::authorize('purge', $server);

        if (trim($this->confirmName) !== $server->name) {
            $this->addError('confirmName', 'Type the server name exactly to purge it.');

            return;
        }

        try {
            app(ServerService::class)->purge($server, auth()->user(), 'manual');
        } catch (Throwable) {
            $this->addError('purge', 'Purge failed. The server was not deleted.');

            return;
        }

        $this->cancelPurge();
        $this->resetPage();
        $this->statusMessage = 'Server permanently deleted.';
    }

    /**
     * The moment automatic purge will remove this server, if it is enabled.
     */
    public function autoPurgeAt(Server $server): ?CarbonInterface
    {
        if ($this->graceDaysValue() === 0 || $server->deleted_at === null) {
            return null;
        }

        return $server->deleted_at->copy()->addDays($this->graceDaysValue());
    }

    /**
     * A short, stable label for the Auto-purge column.
     */
    public function autoPurgeState(Server $server): string
    {
        $days = $this->graceDaysValue();

        if ($days === 0) {
            return 'Disabled';
        }

        $due = $this->autoPurgeAt($server);

        if ($due === null || $due->lessThanOrEqualTo(now())) {
            return 'Due now';
        }

        $remaining = (int) ceil(now()->diffInDays($due));

        return 'In '.$remaining.' '.($remaining === 1 ? 'day' : 'days');
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Setting::class);

        return view('livewire.settings.deleted-servers', [
            'deletedServers' => Server::onlyTrashed()->orderByDesc('deleted_at')->paginate(25),
            'graceDays' => $this->graceDaysValue(),
        ]);
    }

    private function graceDaysValue(): int
    {
        return max(0, (int) $this->graceDays);
    }
}
