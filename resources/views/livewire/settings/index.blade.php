<div class="mx-auto w-full max-w-[1000px]">
    @section('title', 'Settings · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Settings</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Settings</h1>
        <p class="mt-1.5 max-w-[72ch] text-[13px] text-text-sub">
            Account, access, and alert delivery configuration. Administrators see every area; operators can review activity.
        </p>
    </div>

    @include('livewire.settings.nav')

    @php
        $user = auth()->user();
        $entries = [
            [
                'label' => 'Profile',
                'href' => route('settings.profile'),
                'description' => 'Your name, email address, alert email preference, and password.',
                'meta' => 'Every role',
            ],
            [
                'label' => 'Security',
                'href' => '/settings/security',
                'description' => 'Two-factor authentication and recovery codes.',
                'meta' => 'Every role',
            ],
        ];

        if ($user?->isAdmin()) {
            $entries[] = [
                'label' => 'Users',
                'href' => route('settings.users'),
                'description' => 'Create accounts, change roles, deactivate users, and reset passwords.',
                'meta' => 'Administrators',
            ];
            $entries[] = [
                'label' => 'Alerts',
                'href' => route('settings.alerts'),
                'description' => 'Thresholds, reminder cadence, SMTP status, and Telegram delivery.',
                'meta' => 'Administrators',
            ];
            $entries[] = [
                'label' => 'Deleted servers',
                'href' => route('settings.deleted-servers'),
                'description' => 'Auto-purge policy and permanent purge for servers removed from the fleet.',
                'meta' => 'Administrators',
            ];
        }

        if ($user?->canOperate()) {
            $entries[] = [
                'label' => 'Activity',
                'href' => route('activity.index'),
                'description' => 'Read-only audit trail of sign-ins, server, token, alert, and settings changes.',
                'meta' => 'Administrators and operators',
            ];
        }
    @endphp

    <ul class="mt-8 border-t border-border">
        @foreach ($entries as $entry)
            <li class="border-b border-divider">
                <a href="{{ $entry['href'] }}" class="flex flex-col gap-1 py-4 hover:text-text sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                    <span class="min-w-0">
                        <span class="block text-[15px] font-medium text-text">{{ $entry['label'] }}</span>
                        <span class="mt-0.5 block max-w-[70ch] text-[13px] leading-5 text-text-sub">{{ $entry['description'] }}</span>
                    </span>
                    <span class="shrink-0 text-[11px] uppercase tracking-[0.08em] text-text-muted">{{ $entry['meta'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
