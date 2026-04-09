<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Gateway Dashboard')</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 24px;
        }

        .links {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .links a {
            color: #1d4ed8;
            text-decoration: none;
            margin-right: 12px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .links .logout-button {
            border: none;
            background: none;
            color: #1d4ed8;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .links .logout-button:hover {
            text-decoration: underline;
        }
    </style>
    @stack('styles')
</head>
<body>
@php
    $showNav = trim($__env->yieldContent('show_nav', '1')) === '1';
    $showOperatorContext = trim($__env->yieldContent('show_operator_context', '1')) === '1';
@endphp

<h1>@yield('page_heading', 'Gateway Dashboard')</h1>

@if ($showNav)
    <div class="links">
        <a href="/dashboard">Dashboard Home</a>
        <a href="/dashboard/sims">SIM Fleet</a>
        <a href="/dashboard/assignments">Assignments</a>
        <a href="/dashboard/migration">Migration</a>
        <a href="/dashboard/messages/status">Message Status</a>
        <a href="/dashboard/account">My Account</a>
        <a href="/dashboard/operators">Operators</a>
        <a href="/dashboard/audit">Audit Log</a>
        <a href="/dashboard/password">Change Password</a>
        <form method="POST" action="{{ route('logout') }}" style="display:inline;">
            @csrf
            <button type="submit" class="logout-button">Logout</button>
        </form>
    </div>
@endif

@if ($showOperatorContext)
    @include('dashboard.partials.operator-context')
@endif

@yield('content')

@stack('scripts')
</body>
</html>
