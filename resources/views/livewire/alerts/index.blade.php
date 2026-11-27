<div
    @if (config('noc.realtime.enabled'))
        wire:poll.60s.visible="pollRefresh"
        data-realtime="alerts-index"
        x-data="nocRealtimeRefresh({ listeners: [{ channel: 'alerts', event: 'AlertChanged' }], debounce: {{ (int) config('noc.realtime.debounce_ms', 750) }} })"
    @else
        wire:poll.30s.visible="pollRefresh"
    @endif
    class="mx-auto w-full max-w-[1440px]"
>
    @section('title', 'Alerts · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Alerts</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Alerts</h1>
            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[13px] text-text-sub">
                <span>{{ $counts['open'] }} open</span>
                <span class="text-border-strong">·</span>
                <span>{{ $counts['critical'] }} critical active</span>
                <span class="text-border-strong">·</span>
                <span>{{ $counts['acknowledged'] }} acknowledged</span>
                <span class="text-border-strong">·</span>
                <span>{{ $counts['resolved'] }} resolved</span>
            </p>
        </div>
    </div>

    <nav class="mt-6 flex gap-5 overflow-x-auto overflow-y-hidden border-b border-border" aria-label="Alert status">
        @foreach (['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
            <button
                type="button"
                wire:click="$set('status', '{{ $key }}')"
                @class([
                    '-mb-px flex shrink-0 items-baseline gap-1.5 border-b-2 py-3 text-[13px] transition-colors',
                    'border-focus font-medium text-text' => $status === $key,
                    'border-transparent text-text-sub hover:text-text' => $status !== $key,
                ])
                @if ($status === $key) aria-current="page" @endif
            >
                {{ $label }}
                <span class="font-mono text-[12px] text-text-muted">{{ $counts[$key] }}</span>
            </button>
        @endforeach

        <div class="ml-auto flex shrink-0 flex-wrap items-center gap-3 py-2">
            <label for="severity-filter" class="sr-only">Severity</label>
            <select id="severity-filter" wire:model.live="severity" class="field h-9 w-auto min-w-32">
                <option value="all">All severities</option>
                <option value="warning">Warning</option>
                <option value="critical">Critical</option>
            </select>

            <label for="server-filter" class="sr-only">Server</label>
            <select id="server-filter" wire:model.live="serverId" class="field h-9 w-auto min-w-40">
                <option value="">All servers</option>
                @foreach ($servers as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </nav>

    @if ($alerts->isEmpty())
        <div class="mt-6 border-y border-divider py-10 text-center">
            <p class="text-[13.5px] text-text-sub">
                @if ($status === 'open')
                    No open alerts.
                @elseif ($status === 'acknowledged')
                    No acknowledged alerts.
                @elseif ($status === 'resolved')
                    No resolved alerts.
                @else
                    No alerts recorded yet.
                @endif
            </p>
            @if ($severity !== 'all' || $serverId !== '')
                <button type="button" wire:click="clearFilters" class="secondary-button mt-4">Clear filters</button>
            @endif
        </div>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-border table-heading">
                        <th scope="col" class="py-2 pr-4 font-medium">Severity</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Alert</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                        <th scope="col" class="py-2 text-right font-medium">Age</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alerts as $alert)
                        <tr class="border-b border-divider align-top text-[13px]" wire:key="alert-{{ $alert->id }}">
                            <td class="py-3 pr-4">
                                <span @class([
                                    'inline-flex items-center gap-1.5 font-medium whitespace-nowrap',
                                    'text-critical' => $alert->severity->value === 'critical',
                                    'text-warning' => $alert->severity->value === 'warning',
                                ])>
                                    <span aria-hidden="true" @class([
                                        'h-[6px] w-[6px] shrink-0 rounded-full',
                                        'bg-critical' => $alert->severity->value === 'critical',
                                        'bg-warning' => $alert->severity->value === 'warning',
                                    ])></span>
                                    {{ ucfirst($alert->severity->value) }}
                                </span>
                            </td>
                            <td class="py-3 pr-4">
                                <a href="{{ route('alerts.show', $alert) }}" class="block text-[13px] font-medium text-text hover:underline">
                                    {{ $alert->title }}
                                </a>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12px] text-text-muted">
                                    <span class="font-mono">{{ $alert->server?->hostname ?? 'Deleted server' }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $alert->server?->server_type ?: 'Unassigned' }}</span>
                                </p>
                            </td>
                            <td class="py-3 pr-4 text-text-sub">{{ ucfirst($alert->status->value) }}</td>
                            <td class="py-3 text-right font-mono text-[12px] text-text-muted">
                                {{ $alert->last_seen_at?->diffForHumans(short: true) ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-[12px] text-text-muted">
            <p>Showing {{ $alerts->firstItem() }}–{{ $alerts->lastItem() }} of {{ $alerts->total() }}</p>
            {{ $alerts->links() }}
        </div>
    @endif
</div>
