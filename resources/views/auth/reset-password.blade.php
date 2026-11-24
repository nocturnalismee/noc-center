@extends('layouts.guest')

@section('title', 'Choose a new password — '.config('app.name'))

@section('content')
    <h1 class="text-[28px] font-semibold leading-9 tracking-[-0.01em] text-text">Choose a new password</h1>
    <p class="mt-1 text-[13.5px] text-text-sub">Use at least twelve characters and keep it unique to this console.</p>

    @if ($errors->any())
        <div class="mt-5 rounded-control border border-critical/40 bg-critical/5 px-3 py-2.5" role="alert">
            <ul class="space-y-1 text-[13px] text-critical">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ old('token', request()->route('token')) }}">

        <div>
            <label for="email" class="field-label">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email', request('email')) }}"
                required
                autofocus
                autocomplete="username"
                class="field"
            >
            @error('email')
                <p class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="field-label">New password</label>
            <input
                id="password"
                name="password"
                type="password"
                required
                autocomplete="new-password"
                class="field"
            >
            @error('password')
                <p class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="field-label">Confirm new password</label>
            <input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                required
                autocomplete="new-password"
                class="field"
            >
        </div>

        <button type="submit" class="primary-button h-11 w-full">Reset password</button>
    </form>

    <p class="mt-6 border-t border-divider pt-5 text-[13px]">
        <a href="{{ route('login') }}" class="text-focus hover:underline">Back to sign in</a>
    </p>
@endsection
