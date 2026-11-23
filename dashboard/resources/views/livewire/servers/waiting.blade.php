<div
    class="mx-auto w-full max-w-[1000px]"
    @if ($polling) wire:poll.5s.visible="poll" @endif
>
    @section('title', 'Waiting for first sample · '.$server->name)
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('servers.show', $server) }}" class="hover:text-text">{{ $server->name }}</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Waiting</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('servers.show', $server) }}" class="inline-flex items-center gap-1.5 text-[10.5px] text-text-muted hover:text-text">
                <x-heroicon-o-arrow-left class="size-3.5" />
                Back to server
            </a>

            <h1 class="mt-2 text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Waiting for first sample</h1>
            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[11px] text-text-sub">
                <span>{{ $server->name }}</span>
                <span class="text-border-strong">·</span>
                <span class="font-mono text-[10.5px]">{{ $server->hostname }}</span>
                <span class="text-border-strong">·</span>
                <span>{{ $server->interval_seconds }}s interval</span>
            </p>
        </div>

        <x-status :state="$connected ? 'online' : 'never'" :label="$connected ? 'Connected' : 'Never seen'" />
    </div>

    @if ($connected)
        <section class="mt-6 border-y border-divider py-6" aria-labelledby="connected-heading">
            <div class="flex flex-wrap items-center gap-3">
                <x-status state="online" label="Agent connected" />
                <h2 id="connected-heading" class="text-[13px] font-semibold text-text">Agent connected</h2>
            </div>
            <p class="mt-2 max-w-[640px] text-[11px] text-text-sub">
                The first sample arrived{{ $server->last_seen_at ? ' at '.$server->last_seen_at->timezone('Asia/Jakarta')->format('H:i:s').' WIB' : '' }}.
                Live metrics and history now update from this server.
            </p>
            <a href="{{ route('servers.show', $server) }}" class="primary-button mt-4">Open server</a>
        </section>
    @elseif ($this->timedOut())
        <section class="mt-6 border-y border-divider py-6" aria-labelledby="timeout-heading">
            <h2 id="timeout-heading" class="text-[13px] font-semibold text-text">No sample after 10 minutes</h2>
            <p class="mt-2 max-w-[640px] text-[11px] text-text-sub">
                The agent has not checked in yet. Confirm it is installed, running, and able to reach
                <span class="font-mono text-[10.5px] text-text">{{ $appUrl }}</span>.
            </p>

            <div class="mt-5 flex flex-col gap-4">
                <div>
                    <p class="text-[8.5px] font-medium uppercase tracking-[0.08em] text-text-muted">Troubleshooting</p>
                    <div class="mt-2 flex items-start gap-3 rounded-control border border-border bg-surface px-4 py-3">
                        <pre id="agent-status-command" class="min-w-0 flex-1 overflow-x-auto whitespace-pre font-mono text-[10.5px] leading-5 text-text">systemctl status noc-agent</pre>
                        <button type="button" data-copy-target="agent-status-command" class="secondary-button h-9 shrink-0 px-3 text-[11px]">Copy</button>
                    </div>
                </div>

                <div>
                    <p class="text-[8.5px] font-medium uppercase tracking-[0.08em] text-text-muted">Recent agent log</p>
                    <div class="mt-2 flex items-start gap-3 rounded-control border border-border bg-surface px-4 py-3">
                        <pre id="agent-log-command" class="min-w-0 flex-1 overflow-x-auto whitespace-pre font-mono text-[10.5px] leading-5 text-text">journalctl -u noc-agent -n 50 --no-pager</pre>
                        <button type="button" data-copy-target="agent-log-command" class="secondary-button h-9 shrink-0 px-3 text-[11px]">Copy</button>
                    </div>
                </div>

                <div>
                    <p class="text-[8.5px] font-medium uppercase tracking-[0.08em] text-text-muted">Dashboard reachability</p>
                    <div class="mt-2 flex items-start gap-3 rounded-control border border-border bg-surface px-4 py-3">
                        <pre id="health-command" class="min-w-0 flex-1 overflow-x-auto whitespace-pre font-mono text-[10.5px] leading-5 text-text">curl -sS {{ $appUrl }}/up</pre>
                        <button type="button" data-copy-target="health-command" class="secondary-button h-9 shrink-0 px-3 text-[11px]">Copy</button>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <a href="{{ route('servers.waiting', $server) }}" class="secondary-button">Check again</a>
                <a href="{{ route('servers.show', $server) }}" class="text-[10.5px] text-focus hover:underline">Open server &rarr;</a>
            </div>
        </section>
    @else
        <section class="mt-6 border-y border-divider py-6" aria-labelledby="waiting-heading">
            <h2 id="waiting-heading" class="text-[13px] font-semibold text-text">Waiting for first check-in</h2>
            <p class="mt-2 max-w-[640px] text-[11px] text-text-sub">
                The agent has not sent a sample yet. Install it with the token from the previous screen; this page
                refreshes on its own when the first sample arrives.
            </p>

            <dl class="mt-5 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                <div class="flex items-baseline gap-2">
                    <dt class="text-[8.5px] font-medium uppercase tracking-[0.08em] text-text-muted">Elapsed</dt>
                    <dd class="font-mono text-[12px] tabular-nums text-text">{{ $this->elapsedLabel() }}</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="text-[8.5px] font-medium uppercase tracking-[0.08em] text-text-muted">Checks</dt>
                    <dd class="text-[10px] text-text-sub">Every 5 seconds, for up to 10 minutes</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <a href="{{ route('servers.show', $server) }}" class="secondary-button">Open server</a>
                <a href="{{ route('dashboard') }}" class="text-[10.5px] text-focus hover:underline">Back to fleet &rarr;</a>
            </div>
        </section>
    @endif

    <span id="copy-status" class="sr-only" role="status" aria-live="polite"></span>
</div>
