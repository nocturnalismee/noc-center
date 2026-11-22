<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password — {{ config('app.name') }}</title>
    @include('auth._styles')
</head>
<body>
    <main>
        <h1>Choose a new password</h1>

        @if ($errors->any())
            <div class="alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf

            <input type="hidden" name="token" value="{{ old('token', request()->route('token')) }}">

            <p>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" required autofocus autocomplete="username">
            </p>

            <p>
                <label for="password">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </p>

            <p>
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </p>

            <button type="submit">Reset password</button>
        </form>

        <p class="links"><a href="{{ route('login') }}">Back to sign in</a></p>
    </main>
</body>
</html>
