@php
    $settingsUser = auth()->user();
    $sections = [
        ['label' => 'Profile', 'href' => route('settings.profile'), 'active' => request()->is('settings/profile')],
        ['label' => 'Security', 'href' => '/settings/security', 'active' => request()->is('settings/security')],
    ];

    if ($settingsUser?->isAdmin()) {
        $sections[] = ['label' => 'Users', 'href' => route('settings.users'), 'active' => request()->is('settings/users')];
        $sections[] = ['label' => 'Alerts', 'href' => route('settings.alerts'), 'active' => request()->is('settings/alerts')];
    }

    if ($settingsUser?->canOperate()) {
        $sections[] = ['label' => 'Activity', 'href' => route('activity.index'), 'active' => request()->is('activity')];
    }
@endphp

<nav class="mt-6 flex gap-5 overflow-x-auto border-b border-border" aria-label="Settings sections">
    @foreach ($sections as $section)
        <a
            href="{{ $section['href'] }}"
            @class([
                '-mb-px flex shrink-0 items-center border-b-2 py-3 text-[11px] transition-colors',
                'border-focus font-medium text-text' => $section['active'],
                'border-transparent text-text-sub hover:text-text' => ! $section['active'],
            ])
            @if ($section['active']) aria-current="page" @endif
        >
            {{ $section['label'] }}
        </a>
    @endforeach
</nav>
