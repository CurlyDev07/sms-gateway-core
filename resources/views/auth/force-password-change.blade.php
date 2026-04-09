@extends('dashboard.layouts.app')

@section('title', 'Change Temporary Password')
@section('page_heading', 'Change Temporary Password')
@section('show_nav', '0')

@push('styles')
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

        .logout {
            margin-top: 14px;
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
@endpush

@section('content')
<div class="container">
    <p class="muted">
        Your account is using a temporary password. Set a new password before continuing to the dashboard.
    </p>

    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('dashboard.password.change.update') }}">
        @csrf

        <label>
            New Password
            <input type="password" name="password" required autocomplete="new-password">
        </label>

        <label>
            Confirm New Password
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>

        <button type="submit">Update Password</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="logout">
        @csrf
        <button type="submit">Logout</button>
    </form>
</div>
@endsection
