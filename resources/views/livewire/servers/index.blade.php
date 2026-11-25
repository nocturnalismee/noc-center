<div class="mx-auto w-full max-w-[1440px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Servers</h1>
            <p class="mt-1 text-[13px] text-text-sub">
                {{ $total }} {{ $total === 1 ? 'server' : 'servers' }}
                · filters, search, and collection state
            </p>
        </div>

        <a href="{{ route('servers.create') }}" class="primary-button">Add server</a>
    </div>

    <div class="mt-8">
        <livewire:servers.server-table />
    </div>
</div>
