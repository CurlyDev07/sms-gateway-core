<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        .container {
            max-width: 560px;
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

        .status {
            margin: 0 0 12px 0;
            color: #065f46;
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

        input[type="password"] {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        .errors {
            margin: 0 0 12px 0;
            padding-left: 18px;
            color: #b91c1c;
            font-size: 14px;
        }

        .actions {
            margin-top: 8px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        button {
            width: fit-content;
            padding: 9px 14px;
            border: 1px solid #111827;
            background: #111827;
            color: #ffffff;
            border-radius: 4px;
            cursor: pointer;
        }

        .link {
            font-size: 14px;
            color: #1d4ed8;
            text-decoration: none;
        }

        .link:hover {
            text-decoration: underline;
        }

        .logout {
            margin-top: 16px;
            font-size: 14px;
        }

        .logout button {
            border: none;
            background: none;
            color: #1d4ed8;
            padding: 0;
            margin: 0;
            cursor: pointer;
        }

        .logout button:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    @include('dashboard.partials.operator-context')

    <h1>Change Password</h1>
    <p class="muted">
        Update your dashboard password. You must enter your current password to continue.
    </p>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('dashboard.password.self.update') }}">
        @csrf

        <label>
            Current Password
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>

        <label>
            New Password
            <input type="password" name="password" required autocomplete="new-password">
        </label>

        <label>
            Confirm New Password
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>

        <div class="actions">
            <button type="submit">Update Password</button>
            <a href="/dashboard" class="link">Back to Dashboard</a>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="logout">
        @csrf
        <button type="submit">Logout</button>
    </form>
</div>
</body>
</html>
