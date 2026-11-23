<?php

namespace App\Livewire\Servers;

use App\Enums\ServerHealth;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Servers\ServerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public Server $server;

    public string $name = '';

    public string $hostname = '';

    public string $server_type = '';

    public string $provider = '';

    public string $location = '';

    public string $ip_address = '';

    public string $label = '';

    public string $interval_seconds = '60';

    public string $notes = '';

    public bool $alerts_enabled = true;

    public string $deleteName = '';

    public ?string $statusMessage = null;

    public function mount(Server $server): void
    {
        Gate::authorize('update', $server);

        $this->server = $server;

        $this->name = $server->name;
        $this->hostname = $server->hostname;
        $this->server_type = $server->server_type ?? '';
        $this->provider = $server->provider ?? '';
        $this->location = $server->location ?? '';
        $this->ip_address = $server->ip_address ?? '';
        $this->label = $server->label ?? '';
        $this->interval_seconds = (string) $server->interval_seconds;
        $this->notes = $server->notes ?? '';
        $this->alerts_enabled = (bool) $server->alerts_enabled;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->server);

        $this->statusMessage = null;
        $this->resetErrorBag();

        try {
            $this->server = app(ServerService::class)->update($this->server, [
                'name' => $this->name,
                'hostname' => $this->hostname,
                'server_type' => $this->server_type,
                'provider' => $this->provider,
                'location' => $this->location,
                'ip_address' => $this->ip_address,
                'label' => $this->label,
                'interval_seconds' => $this->interval_seconds,
                'notes' => $this->notes,
                'alerts_enabled' => $this->alerts_enabled,
            ], auth()->user());
        } catch (ValidationException $exception) {
            // Keep the entered values on screen and show the service's rules
            // inline. The service remains the single source of validation.
            $this->setErrorBag($exception->errors());

            return;
        }

        $this->name = $this->server->name;
        $this->hostname = $this->server->hostname;
        $this->server_type = $this->server->server_type ?? '';
        $this->provider = $this->server->provider ?? '';
        $this->location = $this->server->location ?? '';
        $this->ip_address = $this->server->ip_address ?? '';
        $this->label = $this->server->label ?? '';
        $this->interval_seconds = (string) $this->server->interval_seconds;
        $this->notes = $this->server->notes ?? '';
        $this->alerts_enabled = (bool) $this->server->alerts_enabled;
        $this->statusMessage = 'Server updated.';
    }

    /**
     * Delete is destructive and requires typing the exact server name.
     * The service soft-deletes, revokes tokens, and closes alerts without
     * recovery deliveries.
     */
    public function deleteServer(): void
    {
        Gate::authorize('delete', $this->server);

        $this->statusMessage = null;
        $this->resetErrorBag();

        if (trim($this->deleteName) !== $this->server->name) {
            $this->addError('deleteName', 'Type the server name exactly to delete it.');

            return;
        }

        app(ServerService::class)->delete($this->server, auth()->user());

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        $intervals = [10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];
        $storedInterval = (int) $this->server->interval_seconds;

        if ($storedInterval > 0 && ! in_array($storedInterval, $intervals, true)) {
            $intervals[] = $storedInterval;
            sort($intervals);
        }

        return view('livewire.servers.edit', [
            'groups' => $this->distinct('server_type'),
            'providers' => $this->distinct('provider'),
            'locations' => $this->distinct('location'),
            'intervals' => $intervals,
        ]);
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

    public function healthLabel(): string
    {
        return match ($this->server->health ?? ServerHealth::Unknown) {
            ServerHealth::Ok => 'OK',
            ServerHealth::Warning => 'Warning',
            ServerHealth::Critical => 'Critical',
            ServerHealth::Unknown => 'Unknown',
        };
    }

    /**
     * @return list<string>
     */
    public function issueLabels(): array
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
            ->map(fn (string $code): string => $labels[$code] ?? Str::headline(str_replace('_', ' ', $code)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function distinct(string $column): array
    {
        return Server::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
