<div class="mx-auto w-full max-w-[1440px]">
    @section('title')
        {{ $server->name }} · NOC Center
    @endsection
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">{{ $server->name }}</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-[13px] text-text-muted hover:text-text">
                <x-heroicon-o-arrow-left class="size-3.5" />
                Back to fleet
            </a>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">{{ $server->name }}</h1>
                <x-status :state="$statusState" :label="$this->statusLabel()" />
            </div>

            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[13px] text-text-sub">
                <span>{{ $server->hostname }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ $server->server_type ?: 'Unassigned' }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ $server->panel ?: 'No panel' }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ $server->agent_version ? 'agent v'.$server->agent_version : 'agent unknown' }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ $server->interval_seconds }}s interval</span>
            </p>

            @if ($server->last_seen_at !== null)
                <p class="mt-1 text-[12px] text-text-muted">{{ $lastSeen }}</p>
            @endif

            @if ($issueLabels !== [])
                <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[12px] {{ $server->health->value === 'critical' ? 'text-critical' : 'text-warning' }}">
                    @foreach ($issueLabels as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canViewSnapshot)
                <a href="/servers/{{ $server->id }}/snapshot" class="secondary-button">Stored snapshot</a>
            @endif
            @if ($canOperate)
                <a href="/servers/{{ $server->id }}/tokens" class="secondary-button">Manage tokens</a>
                <a href="/servers/{{ $server->id }}/edit" class="secondary-button">Edit server</a>
            @endif
        </div>
    </div>

    @if ($server->status() === \App\Enums\ServerStatus::NeverSeen)
        <div class="mt-6 border-y border-divider py-5">
            <p class="text-[13px] font-medium text-text">Waiting for first check-in</p>
            <p class="mt-1 text-[13px] text-text-sub">
                This server has not sent a sample yet. Install the agent with its token, then the live metrics appear here.
            </p>
        </div>
    @elseif ($server->status() === \App\Enums\ServerStatus::Offline)
        <div class="mt-6 border-y border-divider py-5">
            <p class="text-[13px] font-medium text-text">
                Data ends {{ $sample?->collected_at->diffForHumans(short: true) ?? $server->last_seen_at?->diffForHumans(short: true) ?? 'unknown' }}
            </p>
            <p class="mt-1 text-[13px] text-text-sub">
                No sample has arrived within the offline window. The last recorded values are shown below and are not live.
            </p>
        </div>
    @endif

    <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden border-y border-divider bg-divider sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($metrics as $metric)
            <div class="bg-canvas px-4 py-4">
                <dt class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">{{ $metric['label'] }}</dt>
                <dd class="mt-1.5 text-[24px] font-semibold leading-7 tracking-[-0.01em] text-text">{{ $metric['value'] }}</dd>
                <dd class="mt-1 text-[12px] text-text-muted">{{ $metric['meta'] }}</dd>
            </div>
        @endforeach
    </dl>

    <nav class="mt-8 flex gap-6 overflow-x-auto overflow-y-hidden border-b border-border" aria-label="Server sections">
        @foreach (['overview' => 'Overview', 'performance' => 'Performance', 'panel' => 'Panel', 'modules' => 'Modules', 'alerts' => 'Alerts', 'history' => 'History'] as $key => $label)
            @continue($key === 'history' && ! $canViewHistory)

            <a
                href="{{ route('servers.show', ['server' => $server, 'tab' => $key, 'range' => $range]) }}"
                @class([
                    '-mb-px shrink-0 border-b-2 py-3 text-[13px] transition-colors',
                    'border-focus font-medium text-text' => $tab === $key,
                    'border-transparent text-text-sub hover:text-text' => $tab !== $key,
                ])
                @if ($tab === $key) aria-current="page" @endif
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="mt-6 grid gap-8 xl:grid-cols-[minmax(0,1fr)_340px]">
            <section aria-labelledby="performance-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="performance-heading" class="text-[15px] font-semibold text-text">Performance</h2>
                        <p class="mt-1 text-[12px] text-text-muted">Last hour · {{ $server->interval_seconds }}s collector samples · WIB</p>
                    </div>
                    <div class="flex items-center gap-4 text-[12px]">
                        @foreach ($series as $line)
                            <span class="flex items-center gap-1.5 text-text-sub">
                                <span class="h-0.5 w-3" style="background: var({{ $line['color'] }})"></span>
                                {{ $line['label'] }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div
                    class="relative mt-4 h-[280px]"
                    data-series-chart
                    data-series-url="{{ $series[0]['url'] ?? '' }}"
                    data-series="{{ json_encode($series) }}"
                    data-interval="{{ max(1, (int) $server->interval_seconds) }}"
                    data-summary="{{ $sample ? 'CPU '.$this->pct($sample->cpu_usage_pct).', memory '.$this->pct($sample->mem_used_pct).', disk '.$this->pct($sample->disk_used_pct).'. Last hour.' : 'No samples recorded.' }}"
                    role="img"
                    aria-label="{{ $sample ? 'CPU, memory, and disk usage for the last hour' : 'No samples recorded for this server' }}"
                >
                    <canvas></canvas>
                    @if (! $sample)
                        <div class="absolute inset-0 z-10 flex items-center justify-center border-y border-divider bg-canvas">
                            <div class="max-w-[36ch] text-center">
                                <p class="text-[13.5px] font-medium text-text">No samples yet</p>
                                <p class="mt-1 text-[12px] text-text-muted">The chart appears after this server sends its first sample.</p>
                            </div>
                        </div>
                    @endif
                    <noscript>
                        <p class="py-8 text-[13px] text-text-sub">
                            Charts require JavaScript. The latest values are shown in the summary strip above.
                        </p>
                    </noscript>
                </div>

                <p class="border-t border-divider pt-3 text-[12px] text-text-muted">
                    {{ $sample ? 'Current: CPU '.$this->pct($sample->cpu_usage_pct).' · memory '.$this->pct($sample->mem_used_pct).' · disk '.$this->pct($sample->disk_used_pct).'. Gaps are left blank, not filled with zero.' : 'No samples recorded yet.' }}
                </p>
            </section>

            <aside class="space-y-6">
                <section aria-labelledby="issues-heading">
                    <h2 id="issues-heading" class="text-[15px] font-semibold text-text">Active issues</h2>
                    <p class="mt-1 text-[12px] text-text-muted">{{ $issueLabels === [] ? 'No active issues' : count($issueLabels).' reported' }}</p>
                    <ul class="mt-3 border-t border-divider">
                        @forelse ($issueLabels as $issue)
                            <li class="flex items-start gap-2 border-b border-divider py-2.5 text-[13px] text-text-sub">
                                <span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ $server->health->value === 'critical' ? 'bg-critical' : 'bg-warning' }}"></span>
                                {{ $issue }}
                            </li>
                        @empty
                            <li class="border-b border-divider py-3 text-[13px] text-text-muted">Resource checks are within limits.</li>
                        @endforelse
                    </ul>
                </section>

                <section aria-labelledby="agent-heading">
                    <h2 id="agent-heading" class="text-[15px] font-semibold text-text">Server facts</h2>
                    <dl class="mt-3 border-t border-divider">
                        @foreach ($itsm as $label => $value)
                            <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2">
                                <dt class="text-[12px] text-text-muted">{{ $label }}</dt>
                                <dd class="text-right text-[12px] text-text-sub">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </aside>
        </div>
    @elseif ($tab === 'performance')
        <section class="mt-6" aria-labelledby="range-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="range-heading" class="text-[15px] font-semibold text-text">Resource history</h2>
                    <p class="mt-1 text-[12px] text-text-muted">{{ $server->interval_seconds }}s collector · downsampled for display · WIB</p>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-[12px]">
                    @foreach ($ranges as $key)
                        <a
                            href="{{ route('servers.show', ['server' => $server, 'tab' => 'performance', 'range' => $key]) }}"
                            @class([
                                'border-b-2 pb-1 transition-colors',
                                'border-focus font-medium text-text' => $range === $key,
                                'border-transparent text-text-muted hover:text-text' => $range !== $key,
                            ])
                        >
                            {{ $key }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 flex items-center gap-4 text-[12px]">
                @foreach ($series as $line)
                    <span class="flex items-center gap-1.5 text-text-sub">
                        <span class="h-0.5 w-3" style="background: var({{ $line['color'] }})"></span>
                        {{ $line['label'] }}
                    </span>
                @endforeach
            </div>

            <div
                class="relative mt-4 h-[340px]"
                data-series-chart
                data-series-url="{{ $series[0]['url'] ?? '' }}"
                data-series="{{ json_encode($series) }}"
                data-interval="{{ max(1, (int) $server->interval_seconds) }}"
                data-summary="{{ $sample ? 'CPU '.$this->pct($sample->cpu_usage_pct).', memory '.$this->pct($sample->mem_used_pct).', disk '.$this->pct($sample->disk_used_pct).' over '.$range.'.' : 'No samples recorded.' }}"
                role="img"
                aria-label="CPU, memory, and disk usage over {{ $range }}"
            >
                <canvas></canvas>
                @if (! $sample)
                    <div class="absolute inset-0 z-10 flex items-center justify-center border-y border-divider bg-canvas">
                        <div class="max-w-[36ch] text-center">
                            <p class="text-[13.5px] font-medium text-text">No samples yet</p>
                            <p class="mt-1 text-[12px] text-text-muted">The chart appears after this server sends its first sample.</p>
                        </div>
                    </div>
                @endif
                <noscript>
                    <p class="py-8 text-[13px] text-text-sub">
                        Charts require JavaScript. The range and latest values are shown above.
                    </p>
                </noscript>
            </div>

            <p class="border-t border-divider pt-3 text-[12px] text-text-muted">
                Series are read from raw samples up to 24 hours and from rollups for longer ranges. Missing data stays blank.
            </p>
        </section>
    @elseif ($tab === 'panel')
        <section class="mt-6" aria-labelledby="panel-heading">
            <h2 id="panel-heading" class="text-[15px] font-semibold text-text">Panel metrics</h2>
            <p class="mt-1 text-[12px] text-text-muted">cPanel and security facts reported by the agent.</p>
            @include('livewire.servers.tabs.panel', ['panel' => $panel])
        </section>
    @elseif ($tab === 'modules')
        <section class="mt-6" aria-labelledby="modules-heading">
            <h2 id="modules-heading" class="text-[15px] font-semibold text-text">Module freshness</h2>
            <p class="mt-1 text-[12px] text-text-muted">SMART, quota, Imunify, and database module state.</p>
            @include('livewire.servers.tabs.modules', ['modules' => $panel])
        </section>
    @elseif ($tab === 'alerts')
        <section class="mt-6" aria-labelledby="alerts-heading">
            <h2 id="alerts-heading" class="text-[15px] font-semibold text-text">Alerts</h2>
            <p class="mt-1 text-[12px] text-text-muted">Open, acknowledged, and recently resolved alerts for this server.</p>

            @if ($alerts->isEmpty())
                <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
                    No alerts have been recorded for this server.
                </div>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="border-y border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                                <th class="py-2 pr-4 font-medium">Severity</th>
                                <th class="py-2 pr-4 font-medium">Alert</th>
                                <th class="py-2 pr-4 font-medium">Status</th>
                                <th class="py-2 text-right font-medium">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($alerts as $alert)
                                <tr class="border-b border-divider text-[13px]">
                                    <td class="py-2.5 pr-4">
                                        <span class="{{ $alert->severity->value === 'critical' ? 'text-critical' : 'text-warning' }}">
                                            {{ ucfirst($alert->severity->value) }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-4 text-text">{{ $alert->title }}</td>
                                    <td class="py-2.5 pr-4 text-text-sub">{{ ucfirst($alert->status->value) }}</td>
                                    <td class="py-2.5 text-right text-[12px] text-text-muted">
                                        {{ $alert->last_seen_at?->diffForHumans(short: true) ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @elseif ($tab === 'history')
        <section class="mt-6" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-[15px] font-semibold text-text">History</h2>

            @if (! $canViewHistory)
                <p class="mt-3 border-y border-divider py-6 text-[13px] text-text-sub">
                    History is restricted to operators and administrators.
                </p>
            @else
                <p class="mt-1 text-[12px] text-text-muted">Audit events for this server and its tokens.</p>

                @if ($history->isEmpty())
                    <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
                        No audit events have been recorded for this server.
                    </div>
                @else
                    <ol class="mt-4 border-t border-divider">
                        @foreach ($history as $event)
                            <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-divider py-2.5 text-[13px]">
                                <span class="w-16 shrink-0 text-[11px] text-text-muted">
                                    {{ $event->created_at?->timezone('Asia/Jakarta')->format('H:i:s') }}
                                </span>
                                <span class="min-w-32 text-text-sub">{{ $actors[$event->user_id] ?? 'System' }}</span>
                                <span class="font-medium text-text">{{ $event->action }}</span>
                                @if (isset($event->meta_json['note']))
                                    <span class="text-text-muted">{{ $event->meta_json['note'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            @endif
        </section>
    @endif
</div>
