@props([
    'message' => null,
    'tone' => 'success',
])

@if (filled($message))
    @php
        [$icon, $color] = match ($tone) {
            'warning' => ['heroicon-o-exclamation-triangle', 'text-warning'],
            'error' => ['heroicon-o-x-circle', 'text-critical'],
            'info' => ['heroicon-o-information-circle', 'text-focus'],
            default => ['heroicon-o-check-circle', 'text-ok'],
        };
    @endphp

    <div
        data-status-toast
        x-data="{ visible: true }"
        x-show="visible"
        x-transition.opacity.duration.150ms
        class="fixed inset-x-4 bottom-4 z-50 flex items-start gap-3 rounded-control border border-border-strong bg-surface px-4 py-3 shadow-2xl sm:inset-x-auto sm:bottom-6 sm:right-6 sm:w-[24rem]"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <x-dynamic-component :component="$icon" class="mt-0.5 h-4 w-4 shrink-0 {{ $color }}" aria-hidden="true" />

        <p class="min-w-0 flex-1 text-[13px] leading-5 text-text">{{ $message }}</p>

        <button
            type="button"
            @click="visible = false"
            class="-mr-1 -mt-1 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-control text-text-muted hover:bg-surface-2 hover:text-text"
            aria-label="Dismiss notification"
        >
            <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
@endif
