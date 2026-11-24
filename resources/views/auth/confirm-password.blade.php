@extends('layouts.guest')

@section('title', 'Confirm your password — '.config('app.name'))

@section('content')
    <h1 class="text-[28px] font-semibold leading-9 tracking-[-0.01em] text-text">Confirm your password</h1>
    <p class="mt-1 text-[13.5px] text-text-sub">For your security, please confirm your password before continuing.</p>

    @if ($errors->any())
        <div class="mt-5 rounded-control border border-critical/40 bg-critical/5 px-3 py-2.5" role="alert">
            <ul class="space-y-1 text-[13px] text-critical">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <label for="password" class="field-label">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                class="field"
            >
            @error('password')
                <p class="mt-1.5 text-[12px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="primary-button h-11 w-full">Confirm password</button>
    </form>

    <p class="mt-6 border-t border-divider pt-5 text-[13px]">
        <a href="{{ route('dashboard') }}" class="text-focus hover:underline">Back to dashboard</a>
    </p>
@endsection
