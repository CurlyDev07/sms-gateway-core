<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account</title>
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

        .muted {
            color: #6b7280;
            margin-bottom: 16px;
        }

        .card {
            max-width: 720px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 14px;
        }

        .row {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }

        .row:last-child {
            border-bottom: none;
        }

        .label {
            color: #4b5563;
            font-weight: 600;
        }
    </style>
</head>
<body>
<h1>My Account</h1>
<div class="links">
    <a href="/dashboard">Dashboard Home</a>
    <a href="/dashboard/sims">SIM Fleet</a>
    <a href="/dashboard/assignments">Assignments</a>
    <a href="/dashboard/migration">Migration</a>
    <a href="/dashboard/messages/status">Message Status</a>
    <a href="/dashboard/operators">Operators</a>
    <a href="/dashboard/audit">Audit Log</a>
    <a href="/dashboard/account">My Account</a>
    <a href="/dashboard/password">Change Password</a>
    <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="logout-button">Logout</button>
    </form>
</div>
@include('dashboard.partials.operator-context')

<p class="muted">
    Read-only profile/account details for your current dashboard session.
</p>

<section class="card">
    <div class="row">
        <div class="label">Name</div>
        <div>{{ $user->name }}</div>
    </div>
    <div class="row">
        <div class="label">Email</div>
        <div>{{ $user->email }}</div>
    </div>
    <div class="row">
        <div class="label">Company ID</div>
        <div>{{ $user->company_id }}</div>
    </div>
    <div class="row">
        <div class="label">Company Name</div>
        <div>{{ optional($user->company)->name ?? 'N/A' }}</div>
    </div>
    <div class="row">
        <div class="label">Operator Role</div>
        <div>{{ $user->operator_role }}</div>
    </div>
    <div class="row">
        <div class="label">Active</div>
        <div>{{ $user->is_active ? 'yes' : 'no' }}</div>
    </div>
</section>
</body>
</html>
