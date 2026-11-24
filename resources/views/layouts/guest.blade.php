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
                var root = document.documentElement;

                root.classList.toggle('dark', theme === 'dark');
                root.setAttribute('data-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-text">
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="guest-shell">
        <header class="flex items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a href="/login" class="flex min-w-0 items-center gap-3" aria-label="NOC Center">
                <span class="flex h-6 w-6 items-center justify-center rounded-[6px] bg-text" aria-hidden="true">
                    <span class="flex h-3.5 items-end gap-[2px]">
                        <span class="h-1.5 w-[3px] rounded-[1px] bg-ink"></span>
                        <span class="h-3.5 w-[3px] rounded-[1px] bg-ink"></span>
                        <span class="h-2.5 w-[3px] rounded-[1px] bg-ink"></span>
                    </span>
                </span>

                <span class="min-w-0">
                    <span class="block truncate text-[15px] font-semibold leading-5 text-text">NOC Center</span>
                    <span class="block text-[11px] font-medium uppercase leading-4 tracking-[0.1em] text-text-muted">Operations</span>
                </span>
            </a>

            <div class="flex items-center gap-3">
                <p class="hidden text-[13px] text-text-sub sm:block">Need access? Contact your administrator.</p>

                <button
                    type="button"
                    data-theme-toggle
                    aria-label="Switch to dark theme"
                    aria-pressed="false"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-border bg-surface text-text-sub hover:text-text"
                >
                    <x-heroicon-o-moon class="theme-icon-moon h-[18px] w-[18px]" />
                    <x-heroicon-o-sun class="theme-icon-sun h-[18px] w-[18px]" />
                </button>
            </div>
        </header>

        <main id="main" tabindex="-1" class="flex flex-1 flex-col items-center justify-center px-4 py-10">
            <div class="w-full max-w-[400px] rounded-panel border border-border bg-surface p-6 sm:p-7">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>

            <div class="mt-12 flex flex-col items-center gap-2 text-center">
                <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-2">
                    <x-status state="online" label="All systems operational" />
                    <span class="text-[11px] text-text-muted">NOC Center {{ $appVersion }}</span>
                </div>

                <p class="text-[11px] text-text-muted">UTC in storage · Asia/Jakarta display</p>
            </div>
        </main>

        <footer class="flex flex-wrap items-center justify-between gap-2 px-5 py-5 text-[11px] text-text-muted sm:px-8">
            <p>Internal operations console</p>
            <p>Agent guide · Changelog</p>
        </footer>
    </div>

    @stack('scripts')
    @livewireScriptConfig(['nonce' => $cspNonce])
</body>
</html>
