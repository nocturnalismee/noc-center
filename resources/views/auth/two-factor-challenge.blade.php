@extends('layouts.guest')

@section('title', 'Two-factor authentication — '.config('app.name'))

@section('content')
    <h1 class="text-[28px] font-semibold leading-9 tracking-[-0.01em] text-text">Two-factor authentication</h1>
    <p class="mt-1 text-[13.5px] text-text-sub">Enter the 6-digit code from your authenticator app to finish signing in.</p>

    @if ($errors->any())
        <div class="mt-5 rounded-control border border-critical/40 bg-critical/5 px-3 py-2.5" role="alert">
            <ul class="space-y-1 text-[13px] text-critical">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <label for="code" class="field-label">Authentication code</label>
            <input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                autocomplete="one-time-code"
                autofocus
                class="field font-mono tracking-[0.18em]"
                aria-describedby="{{ $errors->has('code') ? 'code-error' : '' }}"
            >
            @error('code')
                <p id="code-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="primary-button h-11 w-full">Verify</button>
    </form>

    <div class="mt-6 border-t border-divider pt-5">
        <p class="text-[13px] text-text-sub">Lost your authenticator device? Use one of your single-use recovery codes instead.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-4 space-y-4">
            @csrf

            <div>
                <label for="recovery_code" class="field-label">Recovery code</label>
                <input
                    id="recovery_code"
                    name="recovery_code"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    class="field font-mono"
                    aria-describedby="{{ $errors->has('recovery_code') ? 'recovery-code-error' : '' }}"
                >
                @error('recovery_code')
                    <p id="recovery-code-error" class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="secondary-button h-10 w-full">Use recovery code</button>
        </form>
    </div>

    <p class="mt-6 text-[13px] text-text-sub">
        <a href="{{ route('login') }}" class="text-focus hover:underline">Back to sign in</a>
    </p>
@endsection
