<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — {{ config('app.name') }}</title>
    @include('auth._styles')
</head>
<body>
    <main>
        <h1>Sign in</h1>

        @if ($errors->any())
            <div class="alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <p>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </p>

            <p>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </p>

            <p>
                <label class="checkbox">
                    <input name="remember" type="checkbox" value="1">
                    Remember me
                </label>
            </p>

            <button type="submit">Sign in</button>
        </form>

        @if (Route::has('password.request'))
            <p class="links"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
        @endif
    </main>
</body>
</html>
