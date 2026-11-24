@extends('layouts.guest')

@section('title', 'Reset your password — '.config('app.name'))

@section('content')
    <h1 class="text-[28px] font-semibold leading-9 tracking-[-0.01em] text-text">Reset your password</h1>
    <p class="mt-1 text-[13.5px] text-text-sub">Enter your email address and we will send you a link to reset your password.</p>

    @if (session('status'))
        <div class="mt-5 rounded-control border border-ok/40 bg-ok/5 px-3 py-2.5 text-[13px] text-ok" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-5 rounded-control border border-critical/40 bg-critical/5 px-3 py-2.5" role="alert">
            <ul class="space-y-1 text-[13px] text-critical">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
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

        <button type="submit" class="primary-button h-11 w-full">Email password reset link</button>
    </form>

    <p class="mt-6 border-t border-divider pt-5 text-[13px]">
        <a href="{{ route('login') }}" class="text-focus hover:underline">Back to sign in</a>
    </p>
@endsection
