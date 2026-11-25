<div wire:poll.30s.visible="refresh">
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
