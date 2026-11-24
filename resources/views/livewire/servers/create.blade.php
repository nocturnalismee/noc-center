<div class="mx-auto w-full max-w-[1440px]">
    @section('title', 'Add server · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Add server</span>
    @endsection

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Add server</h1>
            <p class="mt-1 text-[13px] text-text-sub">
                Register a server and issue its first agent token. The token is shown once.
            </p>
        </div>

        <a href="{{ route('agent.guide') }}" class="text-[13px] text-focus hover:underline">Need help?</a>
    </div>

    <ol class="mt-6 grid grid-cols-3 gap-4 border-b border-divider sm:gap-8" aria-label="Add server steps">
        @foreach ([
            ['step' => 1, 'index' => '01', 'label' => 'Details', 'hint' => 'Name, hostname, interval'],
            ['step' => 2, 'index' => '02', 'label' => 'Review', 'hint' => 'Confirm and create'],
            ['step' => null, 'index' => '03', 'label' => 'Install', 'hint' => 'Copy token and run agent'],
        ] as $item)
            @php($active = $item['step'] === $step)
            <li class="pb-3">
                <div class="flex items-baseline gap-2">
                    <span @class([
                        'font-mono text-[12px]',
                        'text-text' => $active,
                        'text-text-muted' => ! $active,
                    ])>{{ $item['index'] }}</span>
                    <span @class([
                        'text-[13px]',
                        'font-medium text-text' => $active,
                        'text-text-sub' => ! $active,
                    ])>{{ $item['label'] }}</span>
                </div>
                <p class="mt-0.5 text-[12px] text-text-muted">{{ $item['hint'] }}</p>
                <span @class([
                    'mt-2 block h-0.5',
                    'bg-focus' => $active,
                    'bg-transparent' => ! $active,
                ])></span>
            </li>
        @endforeach
    </ol>

    <div class="mt-8 grid gap-10 xl:grid-cols-[minmax(0,760px)_minmax(0,1fr)]">
        <div class="min-w-0">
            @if ($step === 1)
                <form wire:submit="goToReview" class="flex flex-col gap-8">
                    <section class="border-t border-border pt-5" aria-labelledby="identity-heading">
                        <h2 id="identity-heading" class="text-[15px] font-semibold text-text">Identity</h2>
                        <p class="mt-1 text-[12px] text-text-sub">How the server appears across the dashboard.</p>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="name" class="field-label">
                                    Server name
                                    <span class="ml-1 inline-block h-[3px] w-[3px] align-middle rounded-full bg-critical" aria-hidden="true"></span>
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
                                <p id="name-help" class="mt-1.5 text-[11px] text-text-muted">Shown in tables and alerts.</p>
                                @error('name')
                                    <p id="name-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="hostname" class="field-label">
                                    Hostname
                                    <span class="ml-1 inline-block h-[3px] w-[3px] align-middle rounded-full bg-critical" aria-hidden="true"></span>
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
                                <p id="hostname-help" class="mt-1.5 text-[11px] text-text-muted">Must match the server FQDN.</p>
                                @error('hostname')
                                    <p id="hostname-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="server_type" class="field-label">Group</label>
                                <input
                                    id="server_type"
                                    type="text"
                                    wire:model="server_type"
                                    class="field"
                                    list="group-options"
                                    autocomplete="off"
                                    aria-describedby="server-type-help server-type-error"
                                    @error('server_type') aria-invalid="true" @enderror
                                >
                                <datalist id="group-options">
                                    @foreach ($groups as $group)
                                        <option value="{{ $group }}"></option>
                                    @endforeach
                                </datalist>
                                <p id="server-type-help" class="mt-1.5 text-[11px] text-text-muted">Used for filtering and search.</p>
                                @error('server_type')
                                    <p id="server-type-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>

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
                                <p id="interval-help" class="mt-1.5 text-[11px] text-text-muted">Between 10 and 3600 seconds.</p>
                                @error('interval_seconds')
                                    <p id="interval-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
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
                                <p id="notes-help" class="mt-1.5 text-[11px] text-text-muted">Optional. Visible to admins and operators.</p>
                                @error('notes')
                                    <p id="notes-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-border pt-5" aria-labelledby="asset-heading">
                        <h2 id="asset-heading" class="text-[15px] font-semibold text-text">Asset metadata</h2>
                        <p class="mt-1 text-[12px] text-text-sub">Optional inventory details. They appear on the server page.</p>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="ip_address" class="field-label">Primary IP</label>
                                <input
                                    id="ip_address"
                                    type="text"
                                    wire:model="ip_address"
                                    class="field"
                                    autocomplete="off"
                                    spellcheck="false"
                                    aria-describedby="ip-address-help ip-address-error"
                                    @error('ip_address') aria-invalid="true" @enderror
                                >
                                <p id="ip-address-help" class="mt-1.5 text-[11px] text-text-muted">IPv4 or IPv6. Identification only, not reported by the agent.</p>
                                @error('ip_address')
                                    <p id="ip-address-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
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
                                <p id="label-help" class="mt-1.5 text-[11px] text-text-muted">Short inventory tag, for example a rack or asset ID.</p>
                                @error('label')
                                    <p id="label-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="provider" class="field-label">Provider</label>
                                <input
                                    id="provider"
                                    type="text"
                                    wire:model="provider"
                                    class="field"
                                    list="create-provider-options"
                                    autocomplete="off"
                                    aria-describedby="provider-help provider-error"
                                    @error('provider') aria-invalid="true" @enderror
                                >
                                <datalist id="create-provider-options">
                                    @foreach ($providers as $provider)
                                        <option value="{{ $provider }}"></option>
                                    @endforeach
                                </datalist>
                                <p id="provider-help" class="mt-1.5 text-[11px] text-text-muted">Hosting provider or platform owner.</p>
                                @error('provider')
                                    <p id="provider-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="location" class="field-label">Location</label>
                                <input
                                    id="location"
                                    type="text"
                                    wire:model="location"
                                    class="field"
                                    list="create-location-options"
                                    autocomplete="off"
                                    aria-describedby="location-help location-error"
                                    @error('location') aria-invalid="true" @enderror
                                >
                                <datalist id="create-location-options">
                                    @foreach ($locations as $location)
                                        <option value="{{ $location }}"></option>
                                    @endforeach
                                </datalist>
                                <p id="location-help" class="mt-1.5 text-[11px] text-text-muted">Datacenter, region, or city.</p>
                                @error('location')
                                    <p id="location-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-border pt-5" aria-labelledby="collection-heading">
                        <h2 id="collection-heading" class="text-[15px] font-semibold text-text">Collection</h2>
                        <p class="mt-1 text-[12px] text-text-sub">The agent sends one sample per interval. Thresholds follow the interval.</p>

                        <div class="mt-5">
                            <p class="field-label">Enable alerts</p>
                            <label for="alerts_enabled" class="mt-1 flex cursor-pointer items-center gap-3">
                                <input
                                    id="alerts_enabled"
                                    type="checkbox"
                                    wire:model="alerts_enabled"
                                    class="peer sr-only"
                                >
                                <span
                                    aria-hidden="true"
                                    class="relative h-5 w-9 shrink-0 rounded-full bg-surface-2 ring-1 ring-inset ring-border transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-text-muted after:transition-transform after:content-[''] peer-checked:bg-text peer-checked:after:translate-x-4 peer-checked:after:bg-canvas peer-focus-visible:ring-2 peer-focus-visible:ring-focus"
                                ></span>
                                <span class="text-[13px] text-text-sub">Open alerts for CPU, memory, disk, load, services, and modules.</span>
                            </label>
                            <p class="mt-2 text-[11px] text-text-muted">
                                A one-time agent token is generated after you review these details. Alerts apply to this server only.
                            </p>
                        </div>
                    </section>

                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-divider pt-5">
                        <a href="{{ route('servers.index') }}" class="secondary-button">Cancel</a>
                        <button type="submit" class="primary-button">
                            Review server
                            <span aria-hidden="true">&rarr;</span>
                        </button>
                    </div>
                </form>
            @else
                <form wire:submit="create" class="flex flex-col gap-8">
                    <section class="border-t border-border pt-5" aria-labelledby="review-heading">
                        <h2 id="review-heading" class="text-[15px] font-semibold text-text">Review</h2>
                        <p class="mt-1 text-[12px] text-text-sub">
                            Confirm these details. The server and its first token are created together.
                        </p>

                        <dl class="mt-5 divide-y divide-divider border-y border-border">
                            @foreach ([
                                ['label' => 'Server name', 'value' => $name],
                                ['label' => 'Hostname', 'value' => $hostname],
                                ['label' => 'Primary IP', 'value' => $ip_address !== '' ? $ip_address : '—'],
                                ['label' => 'Asset label', 'value' => $label !== '' ? $label : '—'],
                                ['label' => 'Group', 'value' => $server_type !== '' ? $server_type : '—'],
                                ['label' => 'Provider', 'value' => $provider !== '' ? $provider : '—'],
                                ['label' => 'Location', 'value' => $location !== '' ? $location : '—'],
                                ['label' => 'Collection interval', 'value' => $interval_seconds.' seconds'],
                                ['label' => 'Alerts', 'value' => $alerts_enabled ? 'Enabled' : 'Disabled'],
                                ['label' => 'Notes', 'value' => $notes !== '' ? $notes : '—'],
                            ] as $row)
                                <div class="grid gap-1 py-3 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-baseline">
                                    <dt class="text-[11px] font-medium uppercase tracking-[0.08em] text-text-muted">{{ $row['label'] }}</dt>
                                    <dd class="text-[13px] text-text">{{ $row['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <p class="mt-4 text-[11px] text-text-muted">
                            The token is shown once on the next screen. It is never stored in plaintext.
                        </p>
                    </section>

                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-divider pt-5">
                        <button type="button" wire:click="back" class="secondary-button">Back</button>
                        <button type="submit" class="primary-button" wire:loading.attr="disabled" wire:target="create">
                            <span wire:loading.remove wire:target="create">Create server</span>
                            <span wire:loading wire:target="create">Creating…</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>

        <aside class="flex min-w-0 flex-col gap-8 text-[12px] leading-5 text-text-sub">
            <section aria-labelledby="next-heading">
                <h2 id="next-heading" class="text-[15px] font-semibold text-text">What happens next</h2>
                <ol class="mt-4 border-t border-divider">
                    @foreach ([
                        ['01', 'Register', 'The server and its token are created together.'],
                        ['02', 'Copy the command', 'Install the agent with the one-time token.'],
                        ['03', 'Wait for data', 'The first sample usually arrives within a minute.'],
                    ] as $item)
                        <li class="grid grid-cols-[22px_minmax(0,1fr)] gap-2 border-b border-divider py-3">
                            <span class="font-mono text-[12px] text-text-muted">{{ $item[0] }}</span>
                            <span>
                                <span class="block text-[13px] font-medium text-text">{{ $item[1] }}</span>
                                <span class="mt-0.5 block text-[12px] text-text-muted">{{ $item[2] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section aria-labelledby="requirements-heading">
                <h2 id="requirements-heading" class="text-[15px] font-semibold text-text">Requirements</h2>
                <ul class="mt-3 flex list-disc flex-col gap-1.5 pl-4 text-[12px] text-text-muted">
                    <li>Linux or cPanel with Bash 4+</li>
                    <li>curl and standard coreutils</li>
                    <li>Outbound HTTPS to the dashboard</li>
                    <li>64 KiB maximum payload</li>
                </ul>
            </section>

            <section aria-labelledby="security-heading">
                <h2 id="security-heading" class="text-[15px] font-semibold text-text">Token security</h2>
                <p class="mt-2 text-[12px] text-text-muted">
                    Tokens are HMAC-hashed with APP_KEY. Rotate or revoke at any time from the server page.
                    Old tokens keep working for the 24-hour grace period.
                </p>
                <a href="{{ route('agent.guide') }}" class="mt-3 inline-block text-[12px] text-focus hover:underline">Read the agent guide &rarr;</a>
            </section>
        </aside>
    </div>
</div>
