@extends('layouts.app')

@section('title', 'Dashboard — '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-[1440px]">
        <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Dashboard</h1>
        <p class="mt-1 text-[11.5px] text-text-sub">Signed in as {{ auth()->user()->name }}.</p>

        <div class="mt-6 max-w-xl rounded-panel border border-border bg-surface p-5">
            <p class="text-[12.5px] text-text-sub">The fleet overview arrives in the next task. This shell is the shared application frame.</p>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="secondary-button">Sign out</button>
            </form>
        </div>
    </div>
@endsection
