<?php

namespace App\Livewire;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\OperationalRun;
use App\Models\Server;
use App\Services\FleetSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
    public function refreshSummary(): void
    {
        app(FleetSummary::class)->forget();
    }

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
        // Laravel 13 sets cache.serializable_classes=false. Never cache Eloquent
        // models or collections here: a serialized object would come back as
        // __PHP_Incomplete_Class on the next request. This query is small.
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
     * @return array{agents:string,scheduler_heartbeat:string,last_rollup:string,last_prune:string,next_retention:string,failed_jobs:int,database:?string,wal:?string}
     */
    private function collection(array $summary): array
    {
        $runs = OperationalRun::query()
            ->whereIn('task_key', ['scheduler', 'rollup', 'prune'])
            ->get()
            ->keyBy('task_key');
        $schedulerAt = $runs->get('scheduler')?->succeeded_at;
        $rollupAt = $runs->get('rollup')?->succeeded_at;
        $pruneAt = $runs->get('prune')?->succeeded_at;
        $now = now()->timezone('Asia/Jakarta');
        $nextRetention = $now->copy()->setTime(3, 30);

        if ($nextRetention->lessThanOrEqualTo($now)) {
            $nextRetention->addDay();
        }

        $sizes = $this->sqliteSizes();

        return [
            'agents' => $summary['online'].' / '.$summary['total'],
            'scheduler_heartbeat' => $this->runAge($schedulerAt, 120),
            'last_rollup' => $this->runLabel($runs->get('rollup'), $rollupAt, 600),
            'last_prune' => $this->runLabel($runs->get('prune'), $pruneAt, 93600),
            'next_retention' => $nextRetention->format('H:i'),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'database' => $sizes['database'],
            'wal' => $sizes['wal'],
        ];
    }

    private function runAge(?\DateTimeInterface $at, int $staleAfterSeconds): string
    {
        if ($at === null) {
            return 'Not yet';
        }

        $age = Carbon::instance($at)->utc()->diffInSeconds(now()->utc());

        return $age > $staleAfterSeconds
            ? 'Delayed · '.Carbon::instance($at)->diffForHumans(short: true)
            : Carbon::instance($at)->diffForHumans(short: true);
    }

    private function runLabel(?OperationalRun $run, ?\DateTimeInterface $lastSuccess, int $staleAfterSeconds): string
    {
        if ($run?->status === 'running') {
            $started = Carbon::instance($run->started_at)->utc();
            $age = $started->diffForHumans(short: true);
            $delayed = $started->diffInSeconds(now()->utc()) > $staleAfterSeconds;
            $lastSuccessAge = $lastSuccess === null
                ? null
                : Carbon::instance($lastSuccess)->diffForHumans(short: true);
            $lastSuccessLabel = $lastSuccessAge === null ? '' : ' · last success '.$lastSuccessAge;

            return ($delayed ? 'Delayed · running since '.$age : 'Running · started '.$age).$lastSuccessLabel;
        }

        $age = $this->runAge($lastSuccess, $staleAfterSeconds);

        if ($run?->status === 'failed') {
            $failedAt = $run->finished_at === null
                ? 'unknown time'
                : Carbon::instance($run->finished_at)->diffForHumans(short: true);
            $lastSuccessLabel = $lastSuccess === null
                ? 'no successful run'
                : 'last success '.Carbon::instance($lastSuccess)->diffForHumans(short: true);

            return 'Latest run failed '.$failedAt.' · '.$lastSuccessLabel;
        }

        return $age;
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
        // See openAlerts(): models must not cross the cache boundary.
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

        if ($ids === []) {
            return [];
        }

        $key = 'dashboard.activity-server-names.'.hash('sha256', implode(',', $ids));

        return Cache::remember($key, now()->addMinute(), fn (): array => Server::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all());
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
