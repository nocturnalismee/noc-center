@if ($panel === null)
    <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
        No panel details are stored for the latest sample.
    </div>
@else
    @php
        $groups = $panel['groups'] ?? [];
        $services = $panel['services'] ?? [];
        $queries = $panel['queries'] ?? [];
        $canViewQueries = $canViewSnapshot ?? false;
        $primaryGroups = array_values(array_filter($groups, fn ($group) => $group['key'] !== 'other'));
        $other = collect($groups)->firstWhere('key', 'other');
        $hasFacts = $primaryGroups !== [] || ($other['rows'] ?? []) !== [] || $services !== [] || $queries !== [];
    @endphp

    @if (! $hasFacts)
        <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
            No panel metrics were recorded for this sample.
        </div>
    @else
        <div class="mt-4 space-y-8">
            @foreach ($primaryGroups as $group)
                <section aria-labelledby="panel-group-{{ $group['key'] }}">
                    <h3 id="panel-group-{{ $group['key'] }}" class="text-[13px] font-semibold text-text">
                        {{ $group['label'] }}
                    </h3>
                    @if ($group['rows'] === [])
                        <p class="mt-2 text-[12px] text-text-muted">Not reported.</p>
                    @else
                        <dl class="mt-2 border-t border-divider">
                            @foreach ($group['rows'] as $row)
                                <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                                    <dt class="text-[13px] text-text-sub">{{ $row['label'] }}</dt>
                                    <dd class="max-w-[60%] truncate text-right font-mono text-[13px] text-text" title="{{ $row['value'] }}">{{ $row['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endforeach

            @if ($services !== [])
                <section aria-labelledby="panel-services">
                    <h3 id="panel-services" class="text-[13px] font-semibold text-text">Services</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="border-y border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                                    <th class="py-2 pr-4 font-medium">Service</th>
                                    <th class="py-2 pr-4 font-medium">Installed</th>
                                    <th class="py-2 pr-4 font-medium">Enabled</th>
                                    <th class="py-2 pr-4 font-medium">Running</th>
                                    <th class="py-2 font-medium">State</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($services as $service)
                                    @php
                                        $tone = match ($service['severity']) {
                                            'ok' => 'text-ok',
                                            'warning' => 'text-warning',
                                            'critical' => 'text-critical',
                                            default => 'text-text-muted',
                                        };
                                    @endphp
                                    <tr class="border-b border-divider">
                                        <td class="py-2.5 pr-4 font-mono text-[13px] text-text">{{ $service['name'] }}</td>
                                        <td class="py-2.5 pr-4 text-[13px] text-text-sub">{{ $service['installed'] }}</td>
                                        <td class="py-2.5 pr-4 text-[13px] text-text-sub">{{ $service['enabled'] }}</td>
                                        <td class="py-2.5 pr-4 text-[13px] text-text-sub">{{ $service['running'] }}</td>
                                        <td class="py-2.5">
                                            <span class="inline-flex items-center gap-1.5 text-[13px] {{ $tone }}">
                                                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                                {{ ucfirst($service['severity']) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($queries !== [] && $canViewQueries)
                <section aria-labelledby="panel-queries">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 id="panel-queries" class="text-[13px] font-semibold text-text">MySQL queries</h3>
                        <p class="text-[11px] text-text-muted">Query values are masked.</p>
                    </div>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="border-y border-border text-[11px] uppercase tracking-[0.08em] text-text-muted">
                                    <th class="py-2 pr-4 font-medium">Time</th>
                                    <th class="py-2 pr-4 font-medium">User</th>
                                    <th class="py-2 pr-4 font-medium">Database</th>
                                    <th class="py-2 pr-4 font-medium">State</th>
                                    <th class="py-2 font-medium">Statement</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($queries as $query)
                                    <tr class="border-b border-divider">
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $query['time'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $query['user'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $query['db'] }}</td>
                                        <td class="py-2.5 pr-4 text-[12px] text-text-sub">{{ $query['state'] }}</td>
                                        <td class="max-w-[420px] break-all py-2.5 font-mono text-[12px] text-text">{{ $query['info'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @elseif ($queries !== [])
                <p class="text-[12px] text-text-muted">MySQL statement text is visible to administrators only.</p>
            @endif

            @if ($other && ($other['rows'] ?? []) !== [])
                <details class="group">
                    <summary class="cursor-pointer list-none text-[13px] font-medium text-text-sub marker:content-none">
                        Other metrics ({{ count($other['rows']) }})
                    </summary>
                    <dl class="mt-2 border-t border-divider">
                        @foreach ($other['rows'] as $row)
                            <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                                <dt class="text-[13px] text-text-sub">{{ $row['label'] }}</dt>
                                <dd class="max-w-[60%] truncate text-right font-mono text-[13px] text-text" title="{{ $row['value'] }}">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endif

            @if (($panel['generic_total'] ?? 0) > ($panel['generic_shown'] ?? 0))
                <p class="text-[11px] text-text-muted">
                    Showing the first {{ $panel['generic_shown'] ?? 0 }} of {{ $panel['generic_total'] ?? 0 }} panel values.
                </p>
            @endif
        </div>
    @endif
@endif
