<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        .container {
            max-width: 520px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 16px;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 24px;
        }

        .muted {
            color: #6b7280;
            margin: 0 0 14px 0;
            font-size: 14px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            margin-top: 4px;
        }

        .errors {
            margin: 0;
            padding-left: 18px;
            color: #b91c1c;
            font-size: 14px;
        }

        button {
            width: fit-content;
            padding: 9px 14px;
            border: 1px solid #111827;
            background: #111827;
            color: #ffffff;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Dashboard Login</h1>
    <p class="muted">
        Sign in with your Laravel operator account. Tenant access is resolved from your bound company.
    </p>

    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>

        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <label class="checkbox">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            Remember me
        </label>

        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
