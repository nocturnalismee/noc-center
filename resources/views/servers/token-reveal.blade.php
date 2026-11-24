@extends('layouts.app')

@section('title', 'Server created · NOC Center')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
    <span class="px-2 text-border-strong">/</span>
    <a href="{{ route('servers.show', $server) }}" class="hover:text-text">{{ $server->name }}</a>
    <span class="px-2 text-border-strong">/</span>
    <span class="text-text">Server created</span>
@endsection

@section('content')
    <div class="mx-auto w-full max-w-[1440px]">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-[13px] text-text-muted hover:text-text">
                    <x-heroicon-o-arrow-left class="size-3.5" />
                    Back to fleet
                </a>

                <h1 class="mt-2 text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Server created</h1>
                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[13px] text-text-sub">
                    <span>{{ $server->name }}</span>
                    <span class="text-border-strong">·</span>
                    <span>{{ $server->server_type ?: 'Unassigned' }}</span>
                    <span class="text-border-strong">·</span>
                    <span>{{ $server->interval_seconds }}s interval</span>
                    <span class="text-border-strong">·</span>
                    <span>token prefix {{ $prefix }}</span>
                </p>
            </div>

            <a href="{{ route('servers.show', $server) }}" class="primary-button">View server</a>
        </div>

        <div class="mt-6 grid gap-10 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="min-w-0">
                <div class="flex items-start gap-3 border border-border bg-surface-2 px-4 py-3">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                    <div>
                        <p class="text-[13px] font-medium text-text">Copy this token now. It will not be shown again.</p>
                        <p class="mt-0.5 text-[12px] text-text-muted">Only an HMAC hash is stored. If you lose the token, rotate it from the server page.</p>
                    </div>
                </div>

                <section class="mt-6" aria-labelledby="agent-token-heading">
                    <h2 id="agent-token-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Agent token</h2>

                    <div class="mt-2 flex items-start gap-3 rounded-control border border-border bg-surface px-4 py-4">
                        <code id="agent-token" class="min-w-0 flex-1 break-all font-mono text-[13px] leading-5 text-text">{{ $plain }}</code>
                        <button
                            type="button"
                            data-copy-target="agent-token"
                            class="secondary-button h-9 shrink-0 px-3 text-[13px]"
                        >Copy</button>
                    </div>

                    <p class="mt-2 text-[11px] text-text-muted">Created just now · never expires · revoke or rotate at any time</p>
                </section>

                <section class="mt-6 border-t border-border pt-5" aria-labelledby="install-command-heading">
                    <h2 id="install-command-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Install command</h2>

                    <div class="mt-2 rounded-control border border-border bg-surface">
                        <div class="flex items-start gap-3 px-4 py-4">
                            <pre id="install-command" class="min-w-0 flex-1 overflow-x-auto whitespace-pre font-mono text-[13px] leading-5 text-text">{{ $installCommand }}</pre>
                            <button
                                type="button"
                                data-copy-target="install-command"
                                class="secondary-button h-9 shrink-0 px-3 text-[13px]"
                            >Copy</button>
                        </div>
                        <p class="border-t border-divider px-4 py-3 font-mono text-[12px] text-text-muted">
                            # downloads the agent from this dashboard, then installs as root on {{ $server->hostname }}
                        </p>
                        <p class="border-t border-divider px-4 py-3 text-[11px] text-text-muted">
                            No repository checkout is needed. <a href="{{ url('/agent/download') }}" class="text-focus hover:underline">Download noc-agent.tar.gz</a>.
                            The installer reads the token from a mode-600 file; it is never passed in argv or shell history.
                        </p>
                    </div>
                </section>

                <section class="mt-6 border-t border-border pt-5" aria-labelledby="checklist-heading">
                    <h2 id="checklist-heading" class="text-[11px] font-medium uppercase tracking-[0.09em] text-text-muted">Install checklist</h2>

                    <ol class="mt-3 flex flex-col gap-3">
                        @foreach ([
                            ['Token copied', 'Shown once. Store it in your password manager.'],
                            ['Install the agent', 'Run the command above as root.'],
                            ['Wait for the first sample', 'Usually arrives within 60 seconds.'],
                        ] as $index => $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center border border-border font-mono text-[12px] text-text-muted">{{ $index + 1 }}</span>
                                <span>
                                    <span class="block text-[13px] font-medium text-text">{{ $item[0] }}</span>
                                    <span class="mt-0.5 block text-[12px] text-text-muted">{{ $item[1] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-5 border-t border-divider pt-3 text-[11px] text-text-muted">
                        Keep this page open while you install. The agent authenticates with the token, not this browser.
                    </p>
                </section>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-divider pt-5">
                    <a href="{{ route('servers.waiting', $server) }}" class="secondary-button">Waiting for first sample</a>
                    <a href="{{ route('servers.show', $server) }}" class="primary-button">Open server</a>
                </div>
            </div>

            <aside class="flex min-w-0 flex-col gap-8 text-[12px] leading-5 text-text-sub">
                <section aria-labelledby="token-details-heading">
                    <h2 id="token-details-heading" class="text-[15px] font-semibold text-text">Token details</h2>
                    <dl class="mt-3 border-t border-divider">
                        @foreach ([
                            ['Prefix', $prefix],
                            ['Created', $createdAt],
                            ['Expires', 'Never'],
                            ['Rotation grace', '24 hours'],
                            ['Hash', 'HMAC-SHA256'],
                        ] as $row)
                            <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2.5">
                                <dt class="text-[12px] text-text-muted">{{ $row[0] }}</dt>
                                <dd @class([
                                    'text-right text-[12px] text-text',
                                    'font-mono' => in_array($row[0], ['Prefix', 'Hash'], true),
                                ])>{{ $row[1] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="border-t border-border pt-5" aria-labelledby="lost-token-heading">
                    <h2 id="lost-token-heading" class="text-[15px] font-semibold text-text">If you lose this token</h2>
                    <p class="mt-2 text-[12px] text-text-muted">
                        Rotate it from the server page. The old token keeps working for 24 hours, then it stops.
                    </p>
                    <a href="{{ route('servers.show', $server) }}" class="mt-3 inline-block text-[12px] text-focus hover:underline">Manage tokens &rarr;</a>
                </section>

                <section class="border-t border-border pt-5" aria-labelledby="reveal-security-heading">
                    <h2 id="reveal-security-heading" class="text-[15px] font-semibold text-text">Token security</h2>
                    <p class="mt-2 text-[12px] text-text-muted">
                        Tokens are hashed with APP_KEY. Database access alone cannot recover a working token.
                    </p>
                    <a href="{{ route('agent.guide') }}" class="mt-3 inline-block text-[12px] text-focus hover:underline">Read the agent guide &rarr;</a>
                </section>

                <section class="border-t border-divider pt-4 text-[11px] text-text-muted">
                    <p class="font-medium uppercase tracking-[0.08em]">Agent requirements</p>
                    <p class="mt-1">Bash 4+ · curl · outbound HTTPS · 64 KiB payload</p>
                </section>
            </aside>
        </div>

        <span id="copy-status" class="sr-only" role="status" aria-live="polite"></span>
    </div>
@endsection
