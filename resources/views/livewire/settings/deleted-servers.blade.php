<div class="mx-auto w-full max-w-[1100px]">
    @section('title', 'Deleted servers · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('settings.index') }}" class="hover:text-text">Settings</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Deleted servers</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Deleted servers</h1>
        <p class="mt-1.5 max-w-[78ch] text-[13px] text-text-sub">
            Servers removed from the fleet keep their history until they are purged. Automatic purge runs daily after the grace period.
        </p>
    </div>

    @include('livewire.settings.nav')

    <x-status-toast :message="$statusMessage" />

    @error('purge')
        <p class="mt-4 border-y border-divider py-2.5 text-[13px] text-critical" role="alert">{{ $message }}</p>
    @enderror

    <form wire:submit="savePolicy" class="mt-8 border-t border-border pt-5" aria-labelledby="retention-heading">
        <h2 id="retention-heading" class="text-[15px] font-semibold text-text">Auto-purge after</h2>

        <div class="mt-4 max-w-xs">
            <label for="grace-days" class="field-label">Days</label>
            <input
                id="grace-days"
                type="number"
                min="0"
                max="3650"
                step="1"
                wire:model="graceDays"
                class="field max-w-[180px] font-mono"
                aria-describedby="grace-days-help"
                @error('graceDays') aria-invalid="true" @enderror
            >
            <p id="grace-days-help" class="mt-1.5 text-[11px] text-text-muted">
                Days after deletion before the server and its history are removed. Set to 0 to disable automatic purge.
            </p>
            @error('graceDays')
                <p class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="primary-button h-9">Save retention policy</button>
            <p class="text-[12px] text-text-sub">
                {{-- A public string prop is merged over view data, so the
                     comparison and the echoed value are both integer-cast. --}}
                @php
                    $summaryDays = max(0, (int) $graceDays);
                @endphp

                @if ($summaryDays === 0)
                    Automatic purge is <span class="text-text">Disabled</span>.
                @else
                    Deleted servers are purged automatically {{ $summaryDays }} {{ $summaryDays === 1 ? 'day' : 'days' }} after deletion.
                @endif
            </p>
        </div>
    </form>

    <section class="mt-8 border-t border-border pt-5" aria-labelledby="deleted-servers-heading">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 id="deleted-servers-heading" class="text-[15px] font-semibold text-text">Deleted servers</h2>
            <p class="font-mono text-[12px] text-text-muted">{{ $deletedServers->total() }} {{ \Illuminate\Support\Str::plural('server', $deletedServers->total()) }}</p>
        </div>

        @if ($deletedServers->isEmpty())
            <x-empty-state class="mt-4">
                No deleted servers. Servers removed from the fleet appear here until they are purged.
            </x-empty-state>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="border-b border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                            <th scope="col" class="py-2 pr-4 font-medium">Server</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Deleted at</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Auto-purge</th>
                            <th scope="col" class="py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deletedServers as $server)
                            @php
                                $autoPurgeAt = $this->autoPurgeAt($server);
                            @endphp

                            <tr class="border-b border-divider align-top text-[13px]" wire:key="deleted-server-{{ $server->id }}">
                                <td class="py-3 pr-4">
                                    <p class="text-[13px] font-medium text-text">{{ $server->name }}</p>
                                    <p class="mt-0.5 font-mono text-[12px] text-text-muted">{{ $server->hostname }}</p>
                                </td>
                                <td class="py-3 pr-4 font-mono text-[12px] text-text-muted">
                                    {{ $server->deleted_at?->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB' }}
                                </td>
                                <td class="py-3 pr-4">
                                    <p @class([
                                        'text-[13px]',
                                        'text-text-sub' => $this->autoPurgeState($server) === 'Disabled',
                                        'text-warning' => $this->autoPurgeState($server) === 'Due now',
                                        'text-text' => ! in_array($this->autoPurgeState($server), ['Disabled', 'Due now'], true),
                                    ])>{{ $this->autoPurgeState($server) }}</p>
                                    <p class="mt-0.5 font-mono text-[12px] text-text-muted">
                                        {{ $autoPurgeAt ? $autoPurgeAt->timezone('Asia/Jakarta')->format('d M Y') : '—' }}
                                    </p>
                                </td>
                                <td class="py-3 text-right">
                                    <button type="button" wire:click="startPurge('{{ $server->id }}')" class="text-[12px] text-text-sub hover:text-critical">
                                        Purge now
                                    </button>

                                    @if ($confirmingId === $server->id)
                                        <div class="mt-3 border-t border-divider pt-3 text-left">
                                            <p class="text-[13px] text-text">
                                                This permanently removes the server and its history. Audit records are kept. This cannot be undone.
                                            </p>

                                            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-[12px] sm:grid-cols-3">
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Samples</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['samples'] ?? 0 }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Details</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['details'] ?? 0 }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Rollups</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['rollups'] ?? 0 }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Alerts</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['alerts'] ?? 0 }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Deliveries</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['deliveries'] ?? 0 }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <dt class="text-text-muted">Tokens</dt>
                                                    <dd class="font-mono text-text-sub">{{ $confirmCounts['tokens'] ?? 0 }}</dd>
                                                </div>
                                            </dl>

                                            <div class="mt-3 max-w-xs">
                                                <label for="confirm-name-{{ $server->id }}" class="block text-[12px] font-medium text-text-muted">
                                                    Type <span class="font-mono text-text">{{ $server->name }}</span> to confirm
                                                </label>
                                                <input
                                                    id="confirm-name-{{ $server->id }}"
                                                    type="text"
                                                    wire:model="confirmName"
                                                    class="field mt-1.5 font-mono"
                                                    autocomplete="off"
                                                    @error('confirmName') aria-invalid="true" @enderror
                                                >
                                                @error('confirmName')
                                                    <p class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            <div class="mt-3 flex gap-2">
                                                <button type="button" wire:click="purge" class="primary-button h-8">Purge permanently</button>
                                                <button type="button" wire:click="cancelPurge" class="secondary-button h-8">Cancel</button>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $deletedServers->links() }}
            </div>
        @endif

        <p class="mt-6 text-[12px] leading-5 text-text-muted">
            Automatic purge runs daily at 04:30 WIB for servers deleted longer than the grace period. Purging removes rows from the database but SQLite may not shrink the file until <span class="font-mono">VACUUM</span> runs.
        </p>
    </section>
</div>
