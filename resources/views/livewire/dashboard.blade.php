<div class="mx-auto w-full max-w-[1440px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Fleet</h1>
            <p class="mt-1 text-[11.5px] text-text-sub">
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
                <dt class="text-[8.5px] font-medium uppercase tracking-[0.09em] text-text-muted">{{ $metric['label'] }}</dt>
                <dd class="mt-1.5 text-[20px] font-semibold leading-6 tracking-[-0.01em] text-text">{{ $metric['value'] }}</dd>
                <dd class="mt-1 text-[9.5px] text-text-muted">{{ $metric['meta'] }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-8">
        <livewire:servers.server-table />
    </div>
</div>
