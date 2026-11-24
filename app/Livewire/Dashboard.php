<?php

namespace App\Livewire;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Services\FleetSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Keep the summary strip in step with the table's 30-second poll.
     */
    #[On('fleet-summary-refresh')]
    public function refreshSummary(): void {}

    public function render(): View
    {
        $metrics = app(FleetSummary::class)->summary();
        $latestSample = Server::query()->max('last_sample_at');
        $canViewActivity = (bool) auth()->user()?->canOperate();
        $activityEvents = $canViewActivity ? $this->activityEvents() : collect();

        return view('livewire.dashboard', [
            'summary' => $metrics,
            'latestSample' => $latestSample,
            'openAlerts' => $this->openAlerts(),
            'collection' => $this->collection($metrics),
            'canViewActivity' => $canViewActivity,
            'activityEvents' => $activityEvents,
            'activityServerNames' => $canViewActivity ? $this->activityServerNames($activityEvents) : [],
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Alert>
     */
    private function openAlerts()
    {
        return Alert::query()
            ->with('server')
            ->where('status', AlertStatus::Open->value)
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get();
    }

    /**
     * @param  array{total:int,online:int,attention:int,hot:int,gaps:int,open_alerts:int,samples_per_minute:int}  $summary
     * @return array{agents:string,last_rollup:string,next_retention:string,failed_jobs:int,database:?string,wal:?string}
     */
    private function collection(array $summary): array
    {
        $lastRollup = DB::table('metric_rollups')->max('updated_at');
        $now = now()->timezone('Asia/Jakarta');
        $nextRetention = $now->copy()->setTime(3, 30);

        if ($nextRetention->lessThanOrEqualTo($now)) {
            $nextRetention->addDay();
        }

        $sizes = $this->sqliteSizes();

        return [
            'agents' => $summary['online'].' / '.$summary['total'],
            'last_rollup' => $lastRollup === null ? 'Not yet' : Carbon::parse($lastRollup)->diffForHumans(short: true),
            'next_retention' => $nextRetention->format('H:i'),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'database' => $sizes['database'],
            'wal' => $sizes['wal'],
        ];
    }

    /**
     * @return array{database:?string,wal:?string}
     */
    private function sqliteSizes(): array
    {
        if (config('database.default') !== 'sqlite') {
            return ['database' => null, 'wal' => null];
        }

        $path = (string) config('database.connections.sqlite.database');

        if ($path === '' || $path === ':memory:' || ! is_file($path)) {
            return ['database' => null, 'wal' => null];
        }

        $wal = $path.'-wal';

        return [
            'database' => $this->megabytes($path),
            'wal' => is_file($wal) ? $this->megabytes($wal) : null,
        ];
    }

    private function megabytes(string $path): string
    {
        return number_format(filesize($path) / 1048576, 1).' MB';
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    private function activityEvents()
    {
        return AuditEvent::query()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get();
    }

    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return array<string, string>
     */
    private function activityServerNames($events): array
    {
        $ids = $events
            ->map(fn (AuditEvent $event): ?string => $this->subjectServerId($event))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? [] : Server::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function subjectServerId(AuditEvent $event): ?string
    {
        $meta = is_array($event->meta_json) ? $event->meta_json : [];
        $id = $meta['server_id'] ?? null;

        if ($id === null && class_basename((string) $event->target_type) === 'Server') {
            $id = $event->target_id;
        }

        return $id === null || $id === '' ? null : (string) $id;
    }

    public function activitySubject(AuditEvent $event, array $serverNames): string
    {
        $id = $this->subjectServerId($event);

        return $id !== null ? ($serverNames[$id] ?? '') : '';
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'server.created' => 'Created server',
            'server.updated' => 'Updated server',
            'server.deleted' => 'Deleted server',
            'server.alerts_disabled' => 'Disabled alerts',
            'token.created' => 'Created token',
            'token.rotated' => 'Rotated token',
            'token.revoked' => 'Revoked token',
            'alert.acknowledged' => 'Acknowledged',
            'alert.resolved' => 'Resolved',
            'alert.opened' => 'Opened alert',
            'auth.login' => 'Signed in',
            'auth.logout' => 'Signed out',
            'auth.failed' => 'Failed sign-in',
            'user.created' => 'Created user',
            'user.updated' => 'Updated user',
            'user.deleted' => 'Deleted user',
            'user.role_changed' => 'Changed role',
            'user.password_reset' => 'Reset password',
            'settings.updated' => 'Updated settings',
            default => Str::headline(str_replace('.', ' ', $action)),
        };
    }
}
