<div
    @if (config('noc.realtime.enabled'))
        wire:poll.30s.visible="refresh"
        data-realtime="alert-bell"
        x-data="nocRealtimeRefresh({ listeners: [{ channel: 'alerts', event: 'AlertChanged' }], debounce: {{ (int) config('noc.realtime.debounce_ms', 750) }}, method: 'refresh' })"
    @else
        wire:poll.30s.visible="refresh"
    @endif
>
    <a
        href="{{ route('alerts.index') }}"
        class="icon-button relative"
        aria-label="{{ $count > 0 ? $count.' active alerts' : 'Alerts' }}"
    >
        <x-heroicon-o-bell class="h-5 w-5" aria-hidden="true" />

        @if ($count > 0)
            <span class="absolute right-1 top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-critical px-1 font-mono text-[11px] font-medium leading-none text-canvas">
                {{ $count }}
            </span>
        @endif
    </a>
</div>
