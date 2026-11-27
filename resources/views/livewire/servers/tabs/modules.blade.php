@if ($modules === null)
    <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
        No module details are stored for the latest sample.
    </div>
@else
    @php
        $smart = $modules['smart'] ?? [];
        $quota = $modules['quota'] ?? [];
        $imunify = $modules['imunify'] ?? [];
        $hasSmart = ($smart['disks'] ?? []) !== [] || in_array($smart['status'] ?? 'unknown', ['ok', 'warning', 'failed', 'unknown'], true);
        $hasQuota = ($quota['accounts'] ?? 'Not reported') !== 'Not reported' || ($quota['top'] ?? []) !== [];
        $hasImunify = ($imunify['scanning'] ?? 'Not reported') !== 'Not reported' || ($imunify['files'] ?? 'Not reported') !== 'Not reported';
        $quotaOver = $quota['over'] ?? 'Not reported';
        $quotaOverWarning = is_numeric($quotaOver) && (float) $quotaOver > 0;
    @endphp

    @if (! $hasSmart && ! $hasQuota && ! $hasImunify)
        <div class="mt-4 border-y border-divider py-8 text-center text-[13px] text-text-muted">
            No module data was reported for this sample.
        </div>
    @else
        <div class="mt-4 space-y-8">
            <section aria-labelledby="modules-smart">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h3 id="modules-smart" class="text-[13px] font-semibold text-text">SMART</h3>
                    @php
                        $smartState = match ($smart['status'] ?? 'unknown') {
                            'ok' => 'online',
                            'failed' => 'critical',
                            'warning' => 'warning',
                            default => 'unknown',
                        };
                    @endphp
                    <x-status :state="$smartState" :label="$smart['status_label'] ?? 'Unknown'" />
                </div>

                @if (($smart['detail'] ?? '—') !== '—')
                    <p class="mt-1 text-[12px] text-text-muted">{{ $smart['detail'] }}</p>
                @endif
                <p class="mt-1 text-[11px] text-text-muted">Cache age {{ $smart['cache_age'] ?? 'Not reported' }}</p>

                @if (($smart['disks'] ?? []) === [])
                    <p class="mt-3 border-y border-divider py-4 text-[12px] text-text-muted">No disk health data reported.</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="border-y border-border table-heading">
                                    <th class="py-2 pr-4 font-medium">Disk</th>
                                    <th class="py-2 pr-4 font-medium">Health</th>
                                    <th class="py-2 pr-4 font-medium">Reallocated</th>
                                    <th class="py-2 pr-4 font-medium">Pending</th>
                                    <th class="py-2 pr-4 font-medium">Power-on</th>
                                    <th class="py-2 font-medium">Written</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($smart['disks'] as $disk)
                                    @php
                                        $healthTone = match ($disk['health']) {
                                            'healthy' => 'text-ok',
                                            'warning' => 'text-warning',
                                            'failed' => 'text-critical',
                                            default => 'text-text-muted',
                                        };
                                    @endphp
                                    <tr class="border-b border-divider">
                                        <td class="py-2.5 pr-4 font-mono text-[13px] text-text">{{ $disk['device'] }}</td>
                                        <td class="py-2.5 pr-4 text-[13px] {{ $healthTone }}">{{ $disk['health_label'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $disk['realloc'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $disk['pending'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-[12px] text-text-sub">{{ $disk['power_on_hours'] }}</td>
                                        <td class="py-2.5 font-mono text-[12px] text-text-sub">{{ $disk['written_gb'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section aria-labelledby="modules-quota">
                <h3 id="modules-quota" class="text-[13px] font-semibold text-text">Disk quota</h3>
                <dl class="mt-2 grid grid-cols-1 border-t border-divider sm:grid-cols-3">
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2 sm:block sm:pr-4">
                        <dt class="text-[12px] font-medium text-text-muted">Accounts</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $quota['accounts'] ?? 'Not reported' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2 sm:block sm:px-4">
                        <dt class="text-[12px] font-medium text-text-muted">Over quota</dt>
                        <dd class="font-mono text-[13px] {{ $quotaOverWarning ? 'text-warning' : 'text-text' }}">{{ $quotaOver }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-b border-divider py-2 sm:block sm:pl-4">
                        <dt class="text-[12px] font-medium text-text-muted">Cache age</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $quota['cache_age'] ?? 'Not reported' }}</dd>
                    </div>
                </dl>

                @if (($quota['top'] ?? []) === [])
                    <p class="mt-3 border-b border-divider pb-3 text-[12px] text-text-muted">No quota usage reported.</p>
                @else
                    <table class="mt-2 w-full border-collapse text-left">
                        <thead>
                            <tr class="border-y border-border table-heading">
                                <th class="py-2 pr-4 font-medium">Account</th>
                                <th class="py-2 text-right font-medium">Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quota['top'] as $entry)
                                <tr class="border-b border-divider">
                                    <td class="py-2 pr-4 font-mono text-[13px] text-text">{{ $entry['user'] }}</td>
                                    <td class="py-2 text-right font-mono text-[13px] text-text-sub">{{ $entry['used_gb'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section aria-labelledby="modules-imunify">
                <h3 id="modules-imunify" class="text-[13px] font-semibold text-text">Imunify</h3>
                <dl class="mt-2 border-t border-divider">
                    <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                        <dt class="text-[13px] text-text-sub">Scanning</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $imunify['scanning'] ?? 'Not reported' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                        <dt class="text-[13px] text-text-sub">Scan age</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $imunify['age'] ?? 'Not reported' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                        <dt class="text-[13px] text-text-sub">Files scanned</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $imunify['files'] ?? 'Not reported' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                        <dt class="text-[13px] text-text-sub">Target</dt>
                        <dd class="max-w-[60%] truncate text-right font-mono text-[13px] text-text" title="{{ $imunify['target'] ?? '' }}">{{ $imunify['target'] ?? 'Not reported' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-6 border-b border-divider py-2">
                        <dt class="text-[13px] text-text-sub">Cache age</dt>
                        <dd class="font-mono text-[13px] text-text">{{ $imunify['cache_age'] ?? 'Not reported' }}</dd>
                    </div>
                </dl>
            </section>

            @if ($modules['module_truncated'] ?? false)
                <p class="text-[11px] text-text-muted">Some reported module rows are not shown because the list was truncated.</p>
            @endif
        </div>
    @endif
@endif
