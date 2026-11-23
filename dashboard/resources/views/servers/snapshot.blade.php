@extends('layouts.app')

@section('title')
    Stored snapshot · {{ $server->name }} · NOC Center
@endsection

@section('breadcrumb')
    <span>Fleet</span>
    <span class="text-divider" aria-hidden="true">/</span>
    <a href="{{ route('servers.show', $server) }}" class="hover:text-text">{{ $server->name }}</a>
    <span class="text-divider" aria-hidden="true">/</span>
    <span class="text-text">Stored snapshot</span>
@endsection

@section('content')
    <div class="mx-auto w-full max-w-[1440px]">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('servers.show', $server) }}" class="inline-flex items-center gap-1.5 text-[10.5px] text-text-muted hover:text-text">
                    <x-heroicon-o-arrow-left class="h-3.5 w-3.5" aria-hidden="true" />
                    Back to {{ $server->name }}
                </a>
                <h1 class="mt-2 text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Stored snapshot</h1>
                <p class="mt-1 text-[11px] text-text-sub">
                    {{ $server->name }}
                    @if ($server->hostname)
                        <span class="text-text-muted">·</span>
                        <span class="font-mono text-[10px]">{{ $server->hostname }}</span>
                    @endif
                    @if ($sample)
                        <span class="text-text-muted">· collected {{ $sample->collected_at->utc()->format('Y-m-d H:i:s') }} UTC</span>
                    @endif
                </p>
            </div>
            <span class="rounded-control border border-border px-2 py-1 text-[8.5px] font-medium uppercase tracking-[0.09em] text-text-muted">Admin only</span>
        </div>

        <div class="mt-6 border-y border-divider py-8">
            @if ($snapshot === null)
                <div class="text-center">
                    <p class="text-[11px] text-text-sub">No stored snapshot is available for this server.</p>
                    <p class="mt-1 text-[9.5px] text-text-muted">The first snapshot appears after the agent reports a sample.</p>
                </div>
            @else
                <p class="mb-3 text-[9.5px] text-text-muted">
                    Latest sample only. MySQL statement values are masked before display.
                </p>
                <pre class="overflow-x-auto rounded-panel border border-border bg-surface p-4 font-mono text-[10.5px] leading-5 text-text">{{ $snapshot }}</pre>
            @endif
        </div>
    </div>
@endsection
