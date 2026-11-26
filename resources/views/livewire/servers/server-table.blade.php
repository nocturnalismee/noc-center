<div
    @if (config('noc.realtime.enabled'))
        wire:poll.60s.visible="pollRefresh"
        data-realtime="server-table"
        x-data="nocRealtimeRefresh({ listeners: [{ channel: 'fleet', event: 'SampleStored' }, { channel: 'fleet', event: 'ServerChanged' }], debounce: {{ (int) config('noc.realtime.debounce_ms', 750) }} })"
    @else
        wire:poll.30s.visible="pollRefresh"
    @endif
    class="rounded-panel border border-border bg-surface"
>
    <div class="flex flex-wrap items-end justify-between gap-3 px-4 pt-3 lg:px-5">
        <nav class="flex min-w-0 gap-5 overflow-x-auto" aria-label="Filter servers by status">
            @foreach ([
                'all' => ['label' => 'All', 'count' => $counts['all']],
                'online' => ['label' => 'Online', 'count' => $counts['online']],
                'attention' => ['label' => 'Attention', 'count' => $counts['attention']],
                'stale' => ['label' => 'Stale', 'count' => $counts['stale']],
                'offline' => ['label' => 'Offline', 'count' => $counts['offline']],
                'never' => ['label' => 'Never', 'count' => $counts['never']],
            ] as $key => $tab)
                <button
                    type="button"
                    wire:click="setStatus('{{ $key }}')"
                    aria-pressed="{{ $status === $key ? 'true' : 'false' }}"
                    class="relative shrink-0 pb-2.5 pt-1 text-[13px] transition-colors {{ $status === $key ? 'font-medium text-text' : 'text-text-muted hover:text-text-sub' }}"
                >
                    {{ $tab['label'] }}
                    <span class="ml-1.5 font-mono text-[11px] text-text-muted">{{ $tab['count'] }}</span>
                    @if ($status === $key)
                        <span class="absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-focus" aria-hidden="true"></span>
                    @endif
                </button>
            @endforeach
        </nav>

        <div class="flex w-full flex-wrap items-center gap-2 pb-2 sm:w-auto">
            @if (count($providers) > 0)
                <label class="sr-only" for="server-provider-filter">Filter by provider</label>
                <select
                    id="server-provider-filter"
                    wire:model.live="provider"
                    class="h-8 rounded-control border border-border bg-canvas px-2 text-[13px] text-text-sub"
                >
                    <option value="">All providers</option>
                    @foreach ($providers as $providerName)
                        <option value="{{ $providerName }}">{{ $providerName }}</option>
                    @endforeach
                </select>
            @endif

            <label class="sr-only" for="server-search">Search servers</label>
            <div class="relative w-full sm:w-auto">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-text-muted" />
                <input
                    id="server-search"
                    type="search"
                    wire:model.live.debounce.300ms="q"
                    placeholder="Filter name, hostname, IP, label"
                    class="h-9 w-full rounded-control border border-border bg-canvas pl-8 pr-2 text-[13px] text-text placeholder:text-text-muted focus:border-border-strong sm:w-[280px]"
                >
            </div>
        </div>
    </div>

    @if ($servers->isEmpty())
        <div class="border-t border-divider p-4 lg:p-5">
            @if ($totalServers === 0)
                <x-empty-state>
                    No servers yet. Register the first server to start collecting metrics.
                    <x-slot:action>
                        <a href="{{ route('servers.create') }}" class="primary-button">Add server</a>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state>
                    No servers match these filters.
                    <x-slot:action>
                        <button type="button" wire:click="clearFilters" class="secondary-button">Clear filters</button>
                    </x-slot:action>
                </x-empty-state>
            @endif
        </div>
    @else
        <div
            class="hidden overflow-x-auto border-t border-divider md:block"
            role="region"
            aria-label="Servers table"
            tabindex="0"
        >
            <table class="w-full min-w-[940px] table-fixed border-collapse">
                <caption class="sr-only">Servers</caption>
                <thead>
                    <tr class="h-10 border-b border-divider text-left">
                        <th class="w-[112px] pl-5 text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'status') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('status')" class="hover:text-text-sub">Status <span aria-hidden="true">{{ $sort === 'status' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'hostname') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('hostname')" class="hover:text-text-sub">Server <span aria-hidden="true">{{ $sort === 'hostname' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="w-[112px] text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col">Provider</th>
                        <th class="w-[64px] text-right text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'cpu') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('cpu')" class="hover:text-text-sub">CPU <span aria-hidden="true">{{ $sort === 'cpu' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="w-[64px] text-right text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'memory') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('memory')" class="hover:text-text-sub">Mem <span aria-hidden="true">{{ $sort === 'memory' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="w-[64px] text-right text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'disk') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('disk')" class="hover:text-text-sub">Disk <span aria-hidden="true">{{ $sort === 'disk' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="w-[78px] pr-3 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col" @if ($sort === 'load') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('load')" class="hover:text-text-sub">Load <span aria-hidden="true">{{ $sort === 'load' ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                        </th>
                        <th class="w-[120px] pl-3 text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col">Last sample</th>
                        <th class="w-[152px] pr-5 text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted" scope="col">Issues</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr wire:key="server-{{ $server->id }}" class="h-[52px] border-b border-divider transition-colors last:border-b-0 hover:bg-selected">
                            <td class="pl-5">
                                <x-status :state="$this->displayState($server)" />
                            </td>
                            <td class="min-w-0 pr-3">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <a href="{{ route('servers.show', $server) }}" class="truncate text-[13px] font-medium text-text hover:underline" title="{{ $server->name }}{{ $server->server_type ? ' · '.$server->server_type : '' }}">{{ $server->name }}</a>
                                    @if ($server->label)
                                        <span class="shrink-0 rounded border border-border px-1 py-0.5 text-[11px] leading-none text-text-muted">{{ $server->label }}</span>
                                    @endif
                                </div>
                                <p class="truncate font-mono text-[11px] leading-5 text-text-muted" title="{{ $server->hostname }}{{ $server->ip_address ? ' · '.$server->ip_address : '' }}">
                                    {{ $server->hostname }}@if ($server->ip_address)<span class="text-text-muted"> · {{ $server->ip_address }}</span>@endif
                                </p>
                            </td>
                            <td class="truncate pr-3 text-[13px] text-text-sub">{{ $server->provider ?: '—' }}</td>
                            <td class="pr-2 text-right font-mono text-[13px] {{ $server->cpu_usage_pct === null ? 'text-text-muted' : 'text-text' }}">{{ $this->percent($server->cpu_usage_pct) }}</td>
                            <td class="pr-2 text-right">
                                <span class="block font-mono text-[13px] {{ $server->mem_used_pct === null ? 'text-text-muted' : 'text-text' }}">{{ $this->percent($server->mem_used_pct) }}</span>
                                @if ($server->mem_used_pct !== null)
                                    @php($memoryTone = $this->usageTone($server->mem_used_pct, $thresholds['memory']))
                                    <span class="ml-auto mt-1 block h-1 w-10 overflow-hidden rounded-full bg-surface-2" data-usage="memory" data-tone="{{ $memoryTone }}" aria-hidden="true">
                                        <span class="block h-full rounded-full {{ $this->usageBarColor($memoryTone) }}" style="width: {{ $this->usageWidth($server->mem_used_pct) }}"></span>
                                    </span>
                                @endif
                            </td>
                            <td class="pr-2 text-right">
                                <span class="block font-mono text-[13px] {{ $server->disk_used_pct === null ? 'text-text-muted' : 'text-text' }}">{{ $this->percent($server->disk_used_pct) }}</span>
                                @if ($server->disk_used_pct !== null)
                                    @php($diskTone = $this->usageTone($server->disk_used_pct, $thresholds['disk']))
                                    <span class="ml-auto mt-1 block h-1 w-10 overflow-hidden rounded-full bg-surface-2" data-usage="disk" data-tone="{{ $diskTone }}" aria-hidden="true">
                                        <span class="block h-full rounded-full {{ $this->usageBarColor($diskTone) }}" style="width: {{ $this->usageWidth($server->disk_used_pct) }}"></span>
                                    </span>
                                @endif
                            </td>
                            <td class="pr-2 text-right font-mono text-[13px] {{ $server->load1 === null ? 'text-text-muted' : 'text-text' }}">{{ $this->load($server->load1) }}</td>
                            <td class="pl-3 pr-3">
                                <p class="text-[12px] {{ $server->last_sample_at === null ? 'text-text-muted' : 'text-text-sub' }}">{{ $this->sampleAge($server->last_sample_at) }}</p>
                                @if ($server->last_sample_at)
                                    <p class="font-mono text-[11px] text-text-muted">{{ $this->sampleClock($server->last_sample_at) }}</p>
                                @endif
                            </td>
                            <td class="truncate pr-5 font-mono text-[11px] text-text-muted">
                                @php($issues = $this->issueLabels($server))
                                {{ count($issues) ? implode(' · ', $issues) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-divider border-t border-divider md:hidden">
            @foreach ($servers as $server)
                <a href="{{ route('servers.show', $server) }}" wire:key="mobile-server-{{ $server->id }}" class="block px-4 py-3 hover:bg-selected">
                    <div class="flex items-center justify-between gap-3">
                        <x-status :state="$this->displayState($server)" />
                        <p class="font-mono text-[11px] text-text-muted">{{ $this->sampleAge($server->last_sample_at) }}</p>
                    </div>
                    <p class="mt-1.5 truncate text-[13.5px] font-medium text-text">{{ $server->name }}</p>
                    <p class="truncate font-mono text-[11px] text-text-muted">{{ $server->hostname }}@if ($server->ip_address) · {{ $server->ip_address }}@endif</p>
                    <p class="mt-2 font-mono text-[11px] text-text-sub">
                        CPU {{ $this->percent($server->cpu_usage_pct) }}
                        <span class="px-1.5 text-divider">·</span>
                        MEM {{ $this->percent($server->mem_used_pct) }}
                        <span class="px-1.5 text-divider">·</span>
                        DISK {{ $this->percent($server->disk_used_pct) }}
                    </p>
                </a>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-divider px-4 py-2.5 lg:px-5">
            <p class="text-[12px] text-text-muted">
                Showing {{ $servers->firstItem() }}–{{ $servers->lastItem() }} of {{ $servers->total() }}
            </p>

            <div class="text-[12px]">
                {{ $servers->links() }}
            </div>
        </div>
    @endif
</div>
