<div
    @if (config('noc.realtime.enabled'))
        wire:poll.60s.visible="pollRefresh"
        data-realtime="alert-detail"
        x-data="nocRealtimeRefresh({ listeners: [{ channel: 'alert.{{ $alert->getKey() }}', event: 'AlertChanged' }@if ($alert->server_id), { channel: 'server.{{ $alert->server_id }}', event: 'AlertChanged' }@endif], debounce: {{ (int) config('noc.realtime.debounce_ms', 750) }} })"
    @else
        wire:poll.30s.visible="pollRefresh"
    @endif
    class="mx-auto w-full max-w-[1440px]"
>
    @section('title')
        {{ $alert->title }} · NOC Center
    @endsection
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('alerts.index') }}" class="hover:text-text">Alerts</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">{{ $alert->title }}</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
                <span @class([
                    'inline-flex items-center gap-1.5 font-medium uppercase tracking-[0.06em]',
                    'text-critical' => $alert->severity->value === 'critical',
                    'text-warning' => $alert->severity->value === 'warning',
                ])>
                    <span aria-hidden="true" @class([
                        'h-[6px] w-[6px] rounded-full',
                        'bg-critical' => $alert->severity->value === 'critical',
                        'bg-warning' => $alert->severity->value === 'warning',
                    ])></span>
                    {{ $alert->severity->value }}
                </span>
                <span class="text-border-strong">·</span>
                <span class="text-text-sub">{{ ucfirst($alert->status->value) }}</span>
                <span class="text-border-strong">·</span>
                <span class="text-text-muted">{{ $alert->first_seen_at?->diffForHumans(short: true) }} old</span>
            </div>

            <h1 class="mt-2 text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">{{ $alert->title }}</h1>

            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[13px] text-text-sub">
                @if ($alert->server)
                    <a href="{{ route('servers.show', $alert->server) }}" class="font-mono hover:text-text">{{ $alert->server->hostname }}</a>
                @else
                    <span class="font-mono">Deleted server</span>
                @endif
                <span class="text-border-strong">·</span>
                <span>{{ $alert->server?->name ?? 'Deleted server' }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ ucfirst($alert->rule_key->value) }}</span>
            </p>

            <p class="mt-1 text-[12px] text-text-muted">
                First seen {{ $this->formatDate($alert->first_seen_at) }} · Last seen {{ $this->formatDate($alert->last_seen_at) }}
            </p>
        </div>

        <a href="{{ route('alerts.index') }}" class="secondary-button">Back to alerts</a>
    </div>

    <x-status-toast :message="$statusMessage" :tone="$statusTone" />

    <div class="mt-6 grid gap-10 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div class="min-w-0">
            <section aria-labelledby="context-heading">
                <h2 id="context-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Context</h2>

                @if ($contextRows === [])
                    <p class="mt-3 border-y border-divider py-4 text-[13px] text-text-muted">No context was recorded for this alert.</p>
                @else
                    <dl class="mt-2 border-t border-divider">
                        @foreach ($contextRows as $row)
                            <div class="grid gap-1 border-b border-divider py-2.5 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-baseline">
                                <dt class="font-mono text-[12px] text-text-muted">{{ $row['key'] }}</dt>
                                <dd class="break-words text-[13px] text-text">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="mt-8" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Timeline</h2>

                <ol class="mt-2 border-t border-divider">
                    @foreach ($timeline as $entry)
                        <li class="grid gap-1 border-b border-divider py-2.5 sm:grid-cols-[170px_120px_minmax(0,1fr)] sm:items-baseline">
                            <span class="font-mono text-[12px] text-text-muted">{{ $entry['at'] }}</span>
                            <span class="text-[13px] font-medium text-text">{{ $entry['label'] }}</span>
                            <span class="text-[12px] text-text-sub">{{ $entry['detail'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="mt-8" aria-labelledby="delivery-heading">
                <h2 id="delivery-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Delivery</h2>

                <div class="mt-2 overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="border-y border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                                <th scope="col" class="py-2 pr-4 font-medium">Channel</th>
                                <th scope="col" class="py-2 pr-4 font-medium">Opened</th>
                                <th scope="col" class="py-2 font-medium">Recovery</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deliveryRows as $row)
                                <tr class="border-b border-divider align-top text-[13px]" wire:key="delivery-{{ $row['channel'] }}">
                                    <td class="py-3 pr-4 font-medium text-text">{{ $row['channel'] }}</td>
                                    @foreach (['opened', 'recovery'] as $event)
                                        <td class="py-3 pr-4">
                                            @php($cell = $row[$event])
                                            <span @class([
                                                'font-medium',
                                                'text-ok' => in_array($cell['status'], ['Delivered', 'Sent'], true),
                                                'text-critical' => $cell['status'] === 'Failed',
                                                'text-warning' => $cell['status'] === 'Pending',
                                                'text-text-muted' => ! in_array($cell['status'], ['Delivered', 'Sent', 'Failed', 'Pending'], true),
                                            ])>{{ $cell['status'] }}</span>

                                            @if ($cell['meta'])
                                                <p class="mt-0.5 text-[11px] text-text-muted">{{ $cell['meta'] }}</p>
                                            @endif

                                            @if ($cell['error'])
                                                <p class="mt-0.5 break-words text-[11px] text-text-muted">{{ $cell['error'] }}</p>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-[11px] text-text-muted">
                    In-app delivery is the alert row itself. Email and Telegram fail independently and retry.
                </p>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-6">
            <section class="border-t border-border pt-5" aria-labelledby="state-heading">
                <h2 id="state-heading" class="text-[15px] font-semibold text-text">State</h2>
                <dl class="mt-3 border-t border-divider">
                    @foreach ([
                        ['Severity', ucfirst($alert->severity->value)],
                        ['Status', ucfirst($alert->status->value)],
                        ['Server', $alert->server?->name ?? 'Deleted server'],
                        ['Rule', $alert->rule_key->value],
                        ['Notifications sent', (string) $alert->notification_count],
                        ['Last notified', $alert->last_notified_at ? $this->formatDate($alert->last_notified_at) : 'Never'],
                    ] as $row)
                        <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                            <dt class="text-[12px] text-text-muted">{{ $row[0] }}</dt>
                            <dd class="text-right text-[12px] text-text">{{ $row[1] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            @if ($canOperate)
                <section class="border-t border-border pt-5" aria-labelledby="actions-heading">
                    <h2 id="actions-heading" class="text-[15px] font-semibold text-text">Actions</h2>

                    @if ($alert->status->value === 'open')
                        <button type="button" wire:click="acknowledge" class="secondary-button mt-3 w-full">Acknowledge</button>
                        <p class="mt-1.5 text-[11px] text-text-muted">Pauses email and Telegram reminders until the alert resolves.</p>
                    @endif

                    @if ($alert->status->value !== 'resolved')
                        <form wire:submit="resolve" class="mt-5 border-t border-divider pt-4">
                            <label for="resolution_note" class="field-label">Resolution note</label>
                            <textarea
                                id="resolution_note"
                                wire:model="note"
                                rows="3"
                                class="field field-area"
                                aria-describedby="note-help note-error"
                                @error('note') aria-invalid="true" @enderror
                            ></textarea>
                            <p id="note-help" class="mt-1.5 text-[11px] text-text-muted">Required. Record what fixed the condition.</p>
                            @error('note')
                                <p id="note-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                            @enderror

                            <button type="submit" class="primary-button mt-3 w-full">Resolve alert</button>
                            <p class="mt-1.5 text-[11px] text-text-muted">Sends one recovery message per channel that actually opened this alert.</p>
                        </form>
                    @else
                        <p class="mt-3 border-t border-divider pt-3 text-[12px] text-text-sub">
                            Resolved {{ $alert->resolved_at ? $this->formatDate($alert->resolved_at) : '' }}
                            @if ($alert->resolution_note)
                                · {{ $alert->resolution_note }}
                            @endif
                        </p>
                    @endif
                </section>
            @else
                <section class="border-t border-border pt-5" aria-labelledby="readonly-heading">
                    <h2 id="readonly-heading" class="text-[15px] font-semibold text-text">Read-only</h2>
                    <p class="mt-2 text-[12px] text-text-muted">Operators and administrators can acknowledge or resolve this alert.</p>
                </section>
            @endif
        </aside>
    </div>
</div>
