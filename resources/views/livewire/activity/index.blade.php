<div class="mx-auto w-full max-w-[1200px]">
    @section('title', 'Activity · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Activity</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Activity</h1>
        <p class="mt-1.5 max-w-[78ch] text-[13px] text-text-sub">
            Read-only audit trail. Records sign-ins, server and token changes, alert actions, and settings updates. Entries cannot be edited or deleted.
            Date filters use Asia/Jakarta calendar days.
        </p>
    </div>

    <div class="mt-6 flex flex-wrap items-end gap-3 border-y border-divider py-3">
        <div>
            <label for="activity-action" class="field-label">Action</label>
            <select id="activity-action" wire:model.live="action" class="field h-9 w-auto min-w-44">
                <option value="">All actions</option>
                @foreach ($actions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="activity-actor" class="field-label">Actor</label>
            <select id="activity-actor" wire:model.live="actorId" class="field h-9 w-auto min-w-40">
                <option value="">All actors</option>
                @foreach ($actors as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="activity-from" class="field-label">From</label>
            <input id="activity-from" type="date" wire:model.live="from" class="field h-9 w-auto font-mono">
        </div>

        <div>
            <label for="activity-to" class="field-label">To</label>
            <input id="activity-to" type="date" wire:model.live="to" class="field h-9 w-auto font-mono">
        </div>

        <button type="button" wire:click="clearFilters" class="secondary-button h-9">Clear filters</button>

        <p class="ml-auto font-mono text-[12px] text-text-muted">{{ $events->total() }} {{ Str::plural('event', $events->total()) }}</p>
    </div>

    @if ($events->isEmpty())
        <x-empty-state class="mt-4">
            No audit events match the selected filters.
        </x-empty-state>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                        <th scope="col" class="py-2 pr-4 font-medium">Time</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Action</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Actor</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Target</th>
                        <th scope="col" class="py-2 pr-4 font-medium">IP</th>
                        <th scope="col" class="py-2 font-medium">Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr class="border-b border-divider align-top text-[13px]" wire:key="audit-{{ $event->id }}">
                            <td class="whitespace-nowrap py-3 pr-4 font-mono text-[12px] text-text-muted">
                                {{ $event->created_at ? $event->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB' : '—' }}
                            </td>
                            <td class="py-3 pr-4 font-mono text-[12px] text-text">{{ $event->action }}</td>
                            <td class="py-3 pr-4 text-text-sub">
                                {{ $event->user?->name ?? 'System' }}
                            </td>
                            <td class="py-3 pr-4 font-mono text-[12px] text-text-muted">
                                {{ $this->targetLabel($event, $targetNames) ?: '—' }}
                            </td>
                            <td class="py-3 pr-4 font-mono text-[12px] text-text-muted">{{ $event->ip ?? '—' }}</td>
                            <td class="py-3 text-[12px] text-text-muted">
                                @php
                                    $meta = is_array($event->meta_json) ? $event->meta_json : [];
                                    $summary = collect($meta)->keys()->implode(' · ');
                                @endphp
                                {{ $summary !== '' ? $summary : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $events->links() }}
        </div>
    @endif
</div>
