@extends('layouts.guest')

@section('title', 'Sign in — '.config('app.name'))

@section('content')
    <h1 class="text-[28px] font-semibold leading-9 tracking-[-0.01em] text-text">Sign in</h1>
    <p class="mt-1 text-[13.5px] text-text-sub">Use your NOC Center account to continue.</p>

    @if ($errors->any())
        <div class="mt-5 rounded-control border border-critical/40 bg-critical/5 px-3 py-2.5" role="alert">
            <ul class="space-y-1 text-[13px] text-critical">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <label for="email" class="field-label">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                required
                autofocus
                autocomplete="username"
                class="field"
                aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}"
            >
            @error('email')
                <p id="email-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="field-label">Password</label>
            <div class="relative" data-password-field>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    class="field pr-11"
                    aria-describedby="{{ $errors->has('password') ? 'password-error' : '' }}"
                >
                <button
                    type="button"
                    data-password-toggle
                    aria-pressed="false"
                    aria-label="Show password"
                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-control text-text-muted hover:text-text"
                >
                    <x-heroicon-o-eye class="h-4 w-4" />
                </button>
            </div>
            @error('password')
                <p id="password-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between gap-4">
            <label for="remember" class="flex min-h-11 cursor-pointer items-center gap-2 text-[13px] text-text-sub">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    value="1"
                    class="h-4 w-4 rounded border-border-strong accent-text"
                >
                Remember this device
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-[13px] text-focus hover:underline">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="primary-button h-11 w-full">Sign in</button>
    </form>

    <div class="mt-6 border-t border-divider pt-5">
        <p class="text-[13px] text-text-sub">Accounts are created by administrators.</p>
        <p class="mt-0.5 text-[13px] text-text-sub">Public registration is disabled.</p>
        <p class="mt-2 text-[13px] font-medium text-focus">Read the access policy →</p>
    </div>
@endsection
