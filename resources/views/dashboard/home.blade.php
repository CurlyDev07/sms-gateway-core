@extends('dashboard.layouts.app')

@section('title', 'Gateway Dashboard')
@section('page_heading', 'Gateway Dashboard')

@push('styles')
<style>
    .muted {
        color: #6b7280;
        margin-bottom: 16px;
        max-width: 900px;
    }

    .grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        margin-bottom: 16px;
    }

    .card {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 14px;
    }

    .card h2 {
        margin: 0 0 8px 0;
        font-size: 18px;
    }

    .card p {
        margin: 0 0 12px 0;
        font-size: 14px;
        color: #4b5563;
    }

    .card a {
        color: #1d4ed8;
        text-decoration: none;
        font-size: 14px;
    }

    .card a:hover {
        text-decoration: underline;
    }

    .note {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 12px;
        font-size: 14px;
        color: #374151;
        margin-bottom: 10px;
    }
</style>
@endpush

@section('content')
<p class="muted">
    Entry point for operator tools. Choose a page below to inspect fleet state, assignments,
    migration flows, or message delivery status.
</p>

<div class="grid">
    <section class="card">
        <h2>SIM Fleet</h2>
        <p>View SIM health, operator status, stuck indicators, and queue depth.</p>
        <a href="/dashboard/sims">Open SIM Fleet</a>
    </section>

    <section class="card">
        <h2>Assignments</h2>
        <p>Check customer-to-SIM assignment state and migration safety indicators.</p>
        <a href="/dashboard/assignments">Open Assignments</a>
    </section>

    <section class="card">
        <h2>Migration</h2>
        <p>Run manual assignment and SIM migration workflows with existing APIs.</p>
        <a href="/dashboard/migration">Open Migration Tools</a>
    </section>

    <section class="card">
        <h2>Message Status</h2>
        <p>Look up delivery state by client_message_id with optional SIM filter.</p>
        <a href="/dashboard/messages/status">Open Message Status Lookup</a>
    </section>

    <section class="card">
        <h2>My Account</h2>
        <p>View your tenant-bound operator account details.</p>
        <a href="/dashboard/account">Open My Account</a>
    </section>

    <section class="card">
        <h2>Operators</h2>
        <p>View tenant-local dashboard operators and owner-managed role controls.</p>
        <a href="/dashboard/operators">Open Operator Management</a>
    </section>

    <section class="card">
        <h2>Audit Log</h2>
        <p>Review tenant-local operator activity history for dashboard control actions.</p>
        <a href="/dashboard/audit">Open Audit Log</a>
    </section>

    <section class="card">
        <h2>Change Password</h2>
        <p>Update your own dashboard password using your current password.</p>
        <a href="/dashboard/password">Open Password Change</a>
    </section>
</div>

<div class="note">
    SIM detail/control pages use URLs like <code>/dashboard/sims/{id}</code> and are usually opened from known SIM IDs.
</div>

<div class="note">
    Linked pages run on your authenticated dashboard session and no longer require browser-side API key/secret entry.
</div>
@endsection
