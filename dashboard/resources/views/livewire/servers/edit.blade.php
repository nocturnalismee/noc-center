<div class="mx-auto w-full max-w-[1440px]">
    @section('title')
        Edit {{ $server->name }} · NOC Center
    @endsection
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('servers.show', $server) }}" class="hover:text-text">{{ $server->name }}</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Edit</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Edit server</h1>
            <p class="mt-1.5 text-[11.5px] text-text-sub">
                Identity, placement, and collection settings for {{ $server->name }}. Health and issue state are owned by ingest.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('servers.tokens', $server) }}" class="secondary-button">Manage tokens</a>
            <a href="{{ route('servers.show', $server) }}" class="secondary-button">Cancel</a>
        </div>
    </div>

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    <form wire:submit="save" class="mt-8 grid gap-10 xl:grid-cols-[minmax(0,820px)_minmax(0,1fr)]">
        <div class="flex min-w-0 flex-col gap-8">
            <section class="border-t border-border pt-5" aria-labelledby="identity-heading">
                <h2 id="identity-heading" class="text-[13px] font-semibold text-text">Identity</h2>
                <p class="mt-1 text-[10px] text-text-sub">How the server appears across the dashboard.</p>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="field-label">
                            Server name
                            <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                        </label>
                        <input
                            id="name"
                            type="text"
                            wire:model="name"
                            class="field"
                            autocomplete="off"
                            required
                            aria-required="true"
                            aria-describedby="name-help name-error"
                            @error('name') aria-invalid="true" @enderror
                        >
                        <p id="name-help" class="mt-1.5 text-[9px] text-text-muted">Unique across active and deleted servers.</p>
                        @error('name')
                            <p id="name-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="hostname" class="field-label">
                            Hostname
                            <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                        </label>
                        <input
                            id="hostname"
                            type="text"
                            wire:model="hostname"
                            class="field"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            aria-required="true"
                            aria-describedby="hostname-help hostname-error"
                            @error('hostname') aria-invalid="true" @enderror
                        >
                        <p id="hostname-help" class="mt-1.5 text-[9px] text-text-muted">Must match the server FQDN.</p>
                        @error('hostname')
                            <p id="hostname-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="ip_address" class="field-label">Primary IP</label>
                        <input
                            id="ip_address"
                            type="text"
                            wire:model="ip_address"
                            class="field"
                            autocomplete="off"
                            spellcheck="false"
                            aria-describedby="ip-help ip-error"
                            @error('ip_address') aria-invalid="true" @enderror
                        >
                        <p id="ip-help" class="mt-1.5 text-[9px] text-text-muted">Optional IPv4 or IPv6. Identification only.</p>
                        @error('ip_address')
                            <p id="ip-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="label" class="field-label">Asset label</label>
                        <input
                            id="label"
                            type="text"
                            wire:model="label"
                            class="field"
                            autocomplete="off"
                            aria-describedby="label-help label-error"
                            @error('label') aria-invalid="true" @enderror
                        >
                        <p id="label-help" class="mt-1.5 text-[9px] text-text-muted">Optional asset code, for example JHD-001.</p>
                        @error('label')
                            <p id="label-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-border pt-5" aria-labelledby="placement-heading">
                <h2 id="placement-heading" class="text-[13px] font-semibold text-text">Placement</h2>
                <p class="mt-1 text-[10px] text-text-sub">Free text with suggestions from servers already registered.</p>

                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="server_type" class="field-label">Group</label>
                        <input id="server_type" type="text" wire:model="server_type" class="field" list="edit-group-options" autocomplete="off">
                        <datalist id="edit-group-options">
                            @foreach ($groups as $group)
                                <option value="{{ $group }}"></option>
                            @endforeach
                        </datalist>
                        @error('server_type')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="provider" class="field-label">Provider</label>
                        <input id="provider" type="text" wire:model="provider" class="field" list="edit-provider-options" autocomplete="off">
                        <datalist id="edit-provider-options">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}"></option>
                            @endforeach
                        </datalist>
                        @error('provider')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="location" class="field-label">Location</label>
                        <input id="location" type="text" wire:model="location" class="field" list="edit-location-options" autocomplete="off">
                        <datalist id="edit-location-options">
                            @foreach ($locations as $location)
                                <option value="{{ $location }}"></option>
                            @endforeach
                        </datalist>
                        @error('location')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section class="border-t border-border pt-5" aria-labelledby="collection-heading">
                <h2 id="collection-heading" class="text-[13px] font-semibold text-text">Collection</h2>
                <p class="mt-1 text-[10px] text-text-sub">The agent sends one sample per interval. Thresholds follow the interval.</p>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="interval_seconds" class="field-label">Collection interval</label>
                        <select
                            id="interval_seconds"
                            wire:model="interval_seconds"
                            class="field"
                            aria-describedby="interval-help interval-error"
                            @error('interval_seconds') aria-invalid="true" @enderror
                        >
                            @foreach ($intervals as $interval)
                                <option value="{{ $interval }}">{{ $interval }} seconds</option>
                            @endforeach
                        </select>
                        <p id="interval-help" class="mt-1.5 text-[9px] text-text-muted">Between 10 and 3600 seconds.</p>
                        @error('interval_seconds')
                            <p id="interval-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <p class="field-label">Alerts</p>
                        <label for="alerts_enabled" class="mt-1 flex cursor-pointer items-center gap-3">
                            <input id="alerts_enabled" type="checkbox" wire:model="alerts_enabled" class="peer sr-only">
                            <span
                                aria-hidden="true"
                                class="relative h-5 w-9 shrink-0 rounded-full bg-surface-2 ring-1 ring-inset ring-border transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-text-muted after:transition-transform after:content-[''] peer-checked:bg-text peer-checked:after:translate-x-4 peer-checked:after:bg-canvas peer-focus-visible:ring-2 peer-focus-visible:ring-focus"
                            ></span>
                            <span class="text-[11.5px] text-text-sub">{{ $alerts_enabled ? 'Alerts enabled' : 'Alerts disabled' }}</span>
                        </label>
                        <p class="mt-2 text-[9px] text-text-muted">
                            Turning alerts off closes open and acknowledged alerts for this server without sending recovery messages.
                            Re-enabling is not retroactive.
                        </p>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="notes" class="field-label">Notes</label>
                        <textarea
                            id="notes"
                            wire:model="notes"
                            rows="3"
                            class="field field-area"
                            aria-describedby="notes-help notes-error"
                            @error('notes') aria-invalid="true" @enderror
                        ></textarea>
                        <p id="notes-help" class="mt-1.5 text-[9px] text-text-muted">Optional. Visible to admins and operators.</p>
                        @error('notes')
                            <p id="notes-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-divider pt-5">
                <a href="{{ route('servers.show', $server) }}" class="secondary-button">Cancel</a>
                <button type="submit" class="primary-button" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save server</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>

        <aside class="flex min-w-0 flex-col gap-8 text-[10px] leading-4 text-text-sub">
            <section aria-labelledby="state-heading">
                <h2 id="state-heading" class="text-[13px] font-semibold text-text">State</h2>
                <dl class="mt-3 border-t border-divider">
                    @php($issues = $this->issueLabels())
                    @foreach ([
                        ['Status', $this->statusLabel()],
                        ['Health', $this->healthLabel()],
                        ['Issue codes', $issues === [] ? 'None' : implode(', ', $issues)],
                        ['Last seen', $server->last_seen_at ? $server->last_seen_at->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB' : 'Never seen'],
                    ] as $row)
                        <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                            <dt class="text-[9.5px] text-text-muted">{{ $row[0] }}</dt>
                            <dd class="text-right text-[9.5px] text-text">{{ $row[1] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="mt-3 text-[9px] text-text-muted">Changing these fields does not reset health or issue state.</p>
            </section>

            <section class="border-t border-critical/40 pt-5" aria-labelledby="danger-heading">
                <h2 id="danger-heading" class="text-[13px] font-semibold text-critical">Delete server</h2>
                <p class="mt-2 text-[9.5px] text-text-muted">
                    Soft-deletes the server, revokes every token, and closes all open alerts without recovery messages.
                    Metrics are retained until retention.
                </p>

                <label for="delete_name" class="field-label mt-4">Type {{ $server->name }} to confirm</label>
                <input
                    id="delete_name"
                    type="text"
                    wire:model="deleteName"
                    class="field"
                    autocomplete="off"
                    aria-describedby="delete-name-error"
                >
                @error('deleteName')
                    <p id="delete-name-error" class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                @enderror

                <button type="button" wire:click="deleteServer" class="secondary-button mt-3 text-critical">Delete server</button>
            </section>
        </aside>
    </form>
</div>
