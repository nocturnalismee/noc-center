<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your password — {{ config('app.name') }}</title>
    @include('auth._styles')
</head>
<body>
    <main>
        <h1>Confirm your password</h1>

        <p>For your security, please confirm your password before continuing.</p>

        @if ($errors->any())
            <div class="alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.confirm.store') }}">
            @csrf

            <p>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
            </p>

            <button type="submit">Confirm password</button>
        </form>

        <p class="links"><a href="{{ route('dashboard') }}">Back to dashboard</a></p>
    </main>
</body>
</html>
