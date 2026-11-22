<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor authentication — {{ config('app.name') }}</title>
    @include('auth._styles')
</head>
<body>
    <main>
        <h1>Two-factor authentication</h1>

        @if ($errors->any())
            <div class="alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <p>
                <label for="code">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus>
            </p>

            <button type="submit">Verify</button>
        </form>

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <p>
                <label for="recovery_code">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text" autocomplete="off">
            </p>

            <button type="submit">Use recovery code</button>
        </form>
    </main>
</body>
</html>
