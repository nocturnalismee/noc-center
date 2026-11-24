<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'NOC Center')</title>

    <script nonce="{{ $cspNonce }}">
        (function () {
            try {
                var theme = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
                var sidebar = localStorage.getItem('sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
                var root = document.documentElement;

                root.classList.toggle('dark', theme === 'dark');
                root.setAttribute('data-theme', theme);
                root.setAttribute('data-sidebar', sidebar);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.setAttribute('data-sidebar', 'expanded');
            }
        })();
    </script>

    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-text">
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>

    <div class="app-shell">
        <aside id="sidebar" class="sidebar" aria-label="Primary" tabindex="-1">
            <div class="sidebar-header flex h-16 items-center justify-between gap-2 border-b border-divider px-4">
                <a href="/dashboard" class="sidebar-brand flex min-w-0 items-center gap-3" aria-label="NOC Center dashboard">
                    <span class="flex h-5 items-end gap-[2px]" aria-hidden="true">
                        <span class="h-2.5 w-[3px] rounded-[1px] bg-text"></span>
                        <span class="h-5 w-[3px] rounded-[1px] bg-text"></span>
                        <span class="h-3.5 w-[3px] rounded-[1px] bg-text"></span>
                    </span>

                    <span class="sidebar-brand-copy min-w-0">
                        <span class="block truncate text-[14px] font-semibold leading-5 text-text">NOC Center</span>
                        <span class="block truncate text-[8.5px] font-medium uppercase leading-3 tracking-[0.1em] text-text-muted">Operations Console</span>
                    </span>
                </a>

                <button
                    type="button"
                    data-sidebar-close
                    aria-label="Close navigation"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-control text-text-sub hover:bg-surface-2 hover:text-text md:hidden"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-5">
                <div>
                    <p class="sidebar-group-label px-2 pb-2 text-[9px] font-medium uppercase tracking-[0.1em] text-text-muted">Fleet</p>

                    <div class="flex flex-col gap-0.5">
                        <a href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif class="nav-link" title="Dashboard" aria-label="Dashboard">
                            <x-heroicon-o-squares-2x2 class="h-5 w-5 shrink-0" />
                            <span class="sidebar-label">Dashboard</span>
                        </a>

                        <a href="/servers" @if (request()->is('servers*')) aria-current="page" @endif class="nav-link" title="Servers" aria-label="Servers">
                            <x-heroicon-o-server-stack class="h-5 w-5 shrink-0" />
                            <span class="sidebar-label">Servers</span>
                        </a>

                        <a href="/alerts" @if (request()->is('alerts*')) aria-current="page" @endif class="nav-link" title="Alerts" aria-label="Alerts">
                            <x-heroicon-o-bell-alert class="h-5 w-5 shrink-0" />
                            <span class="sidebar-label">Alerts</span>
                        </a>

                        @auth
                            @if (auth()->user()->canOperate())
                                <a href="/activity" @if (request()->is('activity*')) aria-current="page" @endif class="nav-link" title="Activity" aria-label="Activity">
                                    <x-heroicon-o-signal class="h-5 w-5 shrink-0" />
                                    <span class="sidebar-label">Activity</span>
                                </a>
                            @endif
                        @endauth
                    </div>
                </div>

                <div>
                    <p class="sidebar-group-label px-2 pb-2 text-[9px] font-medium uppercase tracking-[0.1em] text-text-muted">Admin</p>

                    <div class="flex flex-col gap-0.5">
                        <a href="/settings" @if (request()->is('settings*')) aria-current="page" @endif class="nav-link" title="Settings" aria-label="Settings">
                            <x-heroicon-o-cog-6-tooth class="h-5 w-5 shrink-0" />
                            <span class="sidebar-label">Settings</span>
                        </a>

                        @auth
                            @if (auth()->user()->isAdmin())
                                <a href="/settings/users" @if (request()->is('settings/users*')) aria-current="page" @endif class="nav-link" title="Users" aria-label="Users">
                                    <x-heroicon-o-users class="h-5 w-5 shrink-0" />
                                    <span class="sidebar-label">Users</span>
                                </a>
                            @endif

                            @if (auth()->user()->canOperate())
                                <a href="/activity" @if (request()->is('activity*')) aria-current="page" @endif class="nav-link" title="Audit log" aria-label="Audit log">
                                    <x-heroicon-o-clipboard-document-list class="h-5 w-5 shrink-0" />
                                    <span class="sidebar-label">Audit log</span>
                                </a>
                            @endif
                        @endauth
                    </div>
                </div>
            </nav>

            @auth
                @php
                    $user = auth()->user();
                    $role = match ($user->role()->value) {
                        'admin' => 'Administrator',
                        'operator' => 'Operator',
                        default => 'Viewer',
                    };
                    $initials = collect(preg_split('/\s+/', trim($user->name)))
                        ->filter()
                        ->take(2)
                        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
                        ->implode('');
                @endphp

                <div class="sidebar-footer flex items-center justify-between gap-2 border-t border-divider px-4 py-3">
                    <div class="sidebar-footer-copy min-w-0">
                        <p class="truncate text-[12px] font-medium leading-4 text-text">{{ $user->name }}</p>
                        <p class="truncate text-[10px] leading-4 text-text-muted">{{ $role }}</p>
                    </div>

                    <span class="sidebar-footer-avatar" title="{{ $user->name }}" aria-hidden="true">{{ $initials }}</span>

                    <x-heroicon-o-chevron-down class="sidebar-footer-chevron h-4 w-4 shrink-0 text-text-muted" />
                </div>
            @endauth

            <div class="sidebar-collapse hidden border-t border-divider p-3 lg:block">
                <button
                    type="button"
                    data-sidebar-toggle
                    aria-controls="sidebar"
                    aria-expanded="true"
                    aria-label="Collapse sidebar"
                    class="flex min-h-9 w-full items-center gap-2.5 rounded-control px-2.5 py-1.5 text-[11.5px] text-text-sub hover:bg-surface-2 hover:text-text"
                >
                    <x-heroicon-o-chevron-double-left class="h-4 w-4 shrink-0" />
                    <span class="sidebar-label">Collapse</span>
                </button>
            </div>
        </aside>

        <div class="flex min-h-dvh min-w-0 flex-col">
            <header class="topbar">
                <div class="flex min-w-0 items-center gap-2">
                    <button
                        type="button"
                        data-sidebar-open
                        aria-controls="sidebar"
                        aria-expanded="false"
                        aria-label="Open navigation"
                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-control text-text-sub hover:bg-surface-2 hover:text-text md:hidden"
                    >
                        <x-heroicon-o-bars-3 class="h-5 w-5" />
                    </button>

                    <nav class="min-w-0" aria-label="Breadcrumb">
                        @hasSection('breadcrumb')
                            @yield('breadcrumb')
                        @else
                            <ol class="flex items-center gap-2 text-[12px] text-text-muted">
                                <li>Fleet</li>
                                <li aria-hidden="true" class="text-divider">/</li>
                                <li class="truncate font-medium text-text">Overview</li>
                            </ol>
                        @endif
                    </nav>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <form method="GET" action="{{ route('servers.index') }}" role="search" class="hidden lg:block">
                        <label for="global-search" class="sr-only">Search servers</label>
                        <div class="relative">
                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" />
                            <input
                                id="global-search"
                                name="q"
                                type="search"
                                value="{{ request()->routeIs('servers.index') ? (string) request()->query('q', '') : '' }}"
                                placeholder="Search servers"
                                autocomplete="off"
                                class="h-9 w-[212px] rounded-control border border-border bg-surface-2 pl-9 pr-12 text-[11.5px] text-text placeholder:text-text-muted hover:border-border-strong focus:border-border-strong"
                            >
                            <kbd class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-border px-1 py-0.5 font-mono text-[9px] text-text-muted">⌘K</kbd>
                        </div>
                    </form>

                    <button
                        type="button"
                        data-theme-toggle
                        aria-label="Switch to dark theme"
                        aria-pressed="false"
                        class="icon-button"
                    >
                        <x-heroicon-o-moon class="theme-icon-moon h-5 w-5" />
                        <x-heroicon-o-sun class="theme-icon-sun h-5 w-5" />
                    </button>

                    <div data-alert-sound class="flex items-center">
                        <audio
                            data-alert-sound-audio
                            src="{{ asset('sounds/alert.wav') }}"
                            preload="auto"
                            hidden
                        ></audio>

                        <button
                            type="button"
                            data-alert-sound-toggle
                            class="icon-button"
                            aria-label="Alert sound"
                            aria-pressed="true"
                            title="Mute alert sound"
                        >
                            <span data-alert-sound-on>
                                <x-heroicon-o-speaker-wave class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <span data-alert-sound-off hidden>
                                <x-heroicon-o-speaker-x-mark class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </button>
                    </div>

                    <livewire:alerts.alert-bell />

                    @auth
                        @php
                            $account = auth()->user();
                            $initials = collect(preg_split('/\s+/', trim($account->name)))
                                ->filter()
                                ->take(2)
                                ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                ->implode('');
                        @endphp

                        <details class="relative">
                            <summary class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-control border border-border bg-surface-2 text-[11px] font-medium text-text [&::-webkit-details-marker]:hidden" aria-label="Account menu">
                                {{ $initials }}
                            </summary>

                            <div class="absolute right-0 top-11 z-30 w-56 rounded-control border border-border bg-surface p-1.5 shadow-lg">
                                <div class="px-2.5 py-2">
                                    <p class="truncate text-[12px] font-medium text-text">{{ $account->name }}</p>
                                    <p class="truncate text-[10px] text-text-muted">{{ $account->email }}</p>
                                </div>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="flex min-h-8 w-full items-center rounded-control px-2.5 text-left text-[11.5px] text-text-sub hover:bg-surface-2 hover:text-text">
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </details>
                    @endauth
                </div>
            </header>

            <main id="main" tabindex="-1" class="flex-1 px-4 py-6 lg:px-6 xl:px-8">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-divider px-4 py-4 text-[9px] text-text-muted lg:px-6 xl:px-8">
                <p>NOC Center {{ $appVersion ?? 'v1.0.0' }} · UTC in storage · Asia/Jakarta display</p>
                <p>Internal operations console</p>
            </footer>
        </div>
    </div>

    @stack('scripts')
    @livewireScriptConfig(['nonce' => $cspNonce])
</body>
</html>
