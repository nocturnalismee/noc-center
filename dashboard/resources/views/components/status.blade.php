@props(['state', 'label' => null])

@php
    $stateValue = $state instanceof \BackedEnum ? $state->value : (string) $state;

    $states = [
        'online' => ['label' => 'Online', 'colour' => 'text-ok', 'dot' => 'bg-ok'],
        'warning' => ['label' => 'Warning', 'colour' => 'text-warning', 'dot' => 'bg-warning'],
        'critical' => ['label' => 'Critical', 'colour' => 'text-critical', 'dot' => 'bg-critical'],
        'stale' => ['label' => 'Stale', 'colour' => 'text-warning', 'dot' => 'bg-warning'],
        'offline' => ['label' => 'Offline', 'colour' => 'text-critical', 'dot' => 'bg-critical'],
        'never' => ['label' => 'Never seen', 'colour' => 'text-unknown', 'dot' => 'bg-unknown'],
        'unknown' => ['label' => 'Unknown', 'colour' => 'text-unknown', 'dot' => 'bg-unknown'],
        'acknowledged' => ['label' => 'Acknowledged', 'colour' => 'text-text-sub', 'dot' => null],
    ];

    $config = $states[$stateValue] ?? $states['unknown'];
    $text = $label ?? $config['label'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-[9.5px] font-medium whitespace-nowrap '.$config['colour']]) }} data-state="{{ $stateValue }}">
    @if ($config['dot'])
        <span aria-hidden="true" class="h-[6px] w-[6px] shrink-0 rounded-full {{ $config['dot'] }}"></span>
    @endif
    <span>{{ $text }}</span>
</span>
