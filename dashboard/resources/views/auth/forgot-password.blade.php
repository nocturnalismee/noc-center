<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password — {{ config('app.name') }}</title>
    @include('auth._styles')
</head>
<body>
    <main>
        <h1>Reset your password</h1>

        <p>Enter your email address and we will send you a link to reset your password.</p>

        @if (session('status'))
            <div class="status" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <p>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </p>

            <button type="submit">Email password reset link</button>
        </form>

        <p class="links"><a href="{{ route('login') }}">Back to sign in</a></p>
    </main>
</body>
</html>
