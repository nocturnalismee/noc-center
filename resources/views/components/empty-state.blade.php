@props(['action' => null])

<div {{ $attributes->merge(['class' => 'rounded-panel border border-border bg-surface px-6 py-10 text-center']) }}>
    <p class="mx-auto max-w-[52ch] text-[12.5px] text-text-sub">{{ $slot }}</p>

    @if ($action)
        <div class="mt-4 flex justify-center">{{ $action }}</div>
    @endif
</div>
