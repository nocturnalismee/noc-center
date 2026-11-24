<div class="mx-auto w-full max-w-[1440px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Fleet</h1>
            <p class="mt-1 text-[13px] text-text-sub">
                {{ $summary['total'] }} {{ $summary['total'] === 1 ? 'server' : 'servers' }}
                · {{ $summary['online'] }} reporting
                @if ($latestSample)
                    · last sample {{ \Illuminate\Support\Carbon::parse($latestSample)->diffForHumans(short: true) }}
                @else
                    · no samples yet
                @endif
            </p>
        </div>

        <a href="/servers/create" class="primary-button">Add server</a>
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden border-y border-divider bg-divider sm:grid-cols-3 xl:grid-cols-6">
        @php
            $metrics = [
                ['label' => 'Online', 'value' => $summary['online'].'/'.$summary['total'], 'meta' => $summary['total'] > 0 ? round($summary['online'] / $summary['total'] * 100).'% reporting' : 'No servers'],
                ['label' => 'Attention', 'value' => (string) $summary['attention'], 'meta' => 'Health warning or critical'],
                ['label' => 'Hot resources', 'value' => (string) $summary['hot'], 'meta' => 'CPU · RAM · disk ≥ 90%'],
                ['label' => 'Missing data', 'value' => (string) $summary['gaps'], 'meta' => 'Stale · offline · never'],
                ['label' => 'Open alerts', 'value' => (string) $summary['open_alerts'], 'meta' => 'Unacknowledged'],
                ['label' => 'Samples / min', 'value' => (string) $summary['samples_per_minute'], 'meta' => 'From online intervals'],
            ];
        @endphp

        @foreach ($metrics as $metric)
            <div class="bg-canvas px-4 py-4">
                <dt class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">{{ $metric['label'] }}</dt>
                <dd class="mt-1.5 text-[24px] font-semibold leading-7 tracking-[-0.01em] text-text">{{ $metric['value'] }}</dd>
                <dd class="mt-1 text-[12px] text-text-muted">{{ $metric['meta'] }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-8 grid gap-8 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0">
            <livewire:servers.server-table />
        </div>

        <aside class="flex min-w-0 flex-col gap-8" aria-label="Fleet overview">
            <section aria-labelledby="open-alerts-heading">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="open-alerts-heading" class="text-[15px] font-semibold text-text">Open alerts</h2>
                    <a href="/alerts" class="text-[12px] text-focus hover:underline">View all</a>
                </div>

                @if ($openAlerts->isEmpty())
                    <p class="mt-3 border-t border-divider pt-3 text-[12px] text-text-muted">No open alerts.</p>
                @else
                    <ul class="mt-3 border-t border-divider">
                        @foreach ($openAlerts as $alert)
                            @php
                                $severity = $alert->severity->value;
                                $severityText = $severity === 'critical' ? 'text-critical' : 'text-warning';
                                $severityDot = $severity === 'critical' ? 'bg-critical' : 'bg-warning';
                            @endphp
                            <li class="border-b border-divider py-3" wire:key="open-alert-{{ $alert->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="size-1.5 shrink-0 rounded-full {{ $severityDot }}" aria-hidden="true"></span>
                                            <a href="/alerts/{{ $alert->id }}" class="truncate text-[13px] font-medium text-text hover:underline">{{ $alert->title }}</a>
                                        </div>
                                        <p class="mt-1 pl-3.5 text-[12px] leading-5 text-text-muted">
                                            {{ $alert->server?->name ?? 'Unknown server' }} · {{ $alert->message }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 font-mono text-[11px] text-text-muted">{{ $alert->last_seen_at?->diffForHumans(short: true) ?? '—' }}</span>
                                </div>
                                <p class="mt-1 pl-3.5 text-[11px] font-medium uppercase tracking-[0.06em] {{ $severityText }}">
                                    {{ ucfirst($severity) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="collection-heading">
                <h2 id="collection-heading" class="text-[15px] font-semibold text-text">Collection</h2>
                <dl class="mt-3 border-t border-divider text-[12px]">
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                        <dt class="text-text-muted">Agents reporting</dt>
                        <dd class="font-mono text-text">{{ $collection['agents'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                        <dt class="text-text-muted">Last rollup</dt>
                        <dd class="font-mono text-text">{{ $collection['last_rollup'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                        <dt class="text-text-muted">Next retention</dt>
                        <dd class="font-mono text-text">{{ $collection['next_retention'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                        <dt class="text-text-muted">Failed jobs</dt>
                        <dd class="font-mono {{ $collection['failed_jobs'] > 0 ? 'text-critical' : 'text-text' }}">{{ $collection['failed_jobs'] }}</dd>
                    </div>
                </dl>
                @if ($collection['database'] !== null)
                    <p class="mt-3 font-mono text-[11px] leading-5 text-text-muted">
                        SQLite {{ $collection['database'] }}
                        @if ($collection['wal'] !== null)
                            · WAL {{ $collection['wal'] }}
                        @endif
                        · {{ $summary['total'] }} {{ $summary['total'] === 1 ? 'agent' : 'agents' }}
                    </p>
                @endif
            </section>

            @if ($canViewActivity)
                <section aria-labelledby="activity-heading">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 id="activity-heading" class="text-[15px] font-semibold text-text">Activity</h2>
                        <a href="/activity" class="text-[12px] text-focus hover:underline">Open audit log</a>
                    </div>

                    @if ($activityEvents->isEmpty())
                        <p class="mt-3 border-t border-divider pt-3 text-[12px] text-text-muted">No activity yet.</p>
                    @else
                        <ol class="mt-3 border-t border-divider">
                            @foreach ($activityEvents as $event)
                                @php($subject = $this->activitySubject($event, $activityServerNames))
                                <li class="grid grid-cols-[52px_minmax(0,1fr)] gap-3 border-b border-divider py-2.5" wire:key="activity-{{ $event->id }}">
                                    <span class="font-mono text-[11px] leading-5 text-text-muted">{{ $event->created_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '—' }}</span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="text-[12px] text-text-sub">{{ $event->user?->name ?? 'System' }}</span>
                                            <span class="text-[12px] font-medium text-text">{{ $this->actionLabel($event->action) }}</span>
                                        </div>
                                        @if ($subject !== '')
                                            <p class="truncate font-mono text-[11px] text-text-muted">{{ $subject }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
