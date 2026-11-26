@props(['chart', 'sample', 'interval', 'height' => 260])

@php
    $hasSample = $sample !== null;
    $chartHeight = max(180, (int) $height);
@endphp

<figure data-chart-key="{{ $chart['key'] }}" class="min-w-0">
    <figcaption>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-[15px] font-semibold text-text">{{ $chart['title'] }}</h3>
                <p class="mt-1 text-[12px] text-text-muted">{{ $chart['subtitle'] }}</p>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px]">
            @foreach ($chart['series'] as $line)
                <span class="flex items-center gap-1.5 text-text-sub">
                    <span class="h-0.5 w-3" style="background: var({{ $line['color'] }})"></span>
                    {{ $line['label'] }}
                </span>
            @endforeach
        </div>
    </figcaption>

    <div
        class="relative mt-3"
        style="height: {{ $chartHeight }}px"
        data-series-chart
        data-chart-unit="{{ $chart['unit'] }}"
        data-series="{{ json_encode($chart['series'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
        data-interval="{{ max(1, (int) $interval) }}"
        data-chart-has-sample="{{ $hasSample ? 'true' : 'false' }}"
        aria-busy="false"
    >
        <canvas role="img" aria-label="{{ $chart['title'] }} chart. {{ $chart['summary'] }}"></canvas>

        <div data-chart-status class="pointer-events-none absolute left-3 top-2 z-10 text-[12px] text-text-muted" role="status" aria-live="polite" hidden></div>

        <div data-chart-error class="absolute inset-x-3 bottom-3 z-20 flex items-center justify-between gap-3 border border-border bg-canvas px-3 py-2 text-[12px] text-critical" role="alert" hidden>
            <span>Chart data could not be loaded.</span>
            <button type="button" data-chart-retry class="pointer-events-auto underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-focus">Try again</button>
        </div>

        @if (! $hasSample)
            <div class="absolute inset-0 z-10 flex items-center justify-center border-y border-divider bg-canvas">
                <div class="max-w-[36ch] text-center">
                    <p class="text-[13.5px] font-medium text-text">No samples yet</p>
                    <p class="mt-1 text-[12px] text-text-muted">The chart appears after this server sends its first sample.</p>
                </div>
            </div>
        @endif

        <noscript>
            <p class="py-8 text-[13px] text-text-sub">
                Charts require JavaScript. The latest values are shown in the summary strip above.
            </p>
        </noscript>
    </div>

    <p class="border-t border-divider pt-3 text-[12px] text-text-muted">{{ $chart['summary'] }}</p>
</figure>
