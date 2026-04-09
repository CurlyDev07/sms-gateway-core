<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $pageTitle = trim($__env->yieldContent('page_title'));
        if ($pageTitle === '') {
            $pageTitle = trim($__env->yieldContent('title', 'Gateway Dashboard'));
        }
    @endphp
    <title>{{ $pageTitle }} | Gateway Dashboard</title>
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
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            align-items: center;
        }

        .links-group {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            align-items: center;
        }

        .links-label {
            color: #4b5563;
            font-weight: 600;
        }

        .links .nav-link {
            color: #1d4ed8;
            text-decoration: none;
            border-radius: 4px;
            padding: 3px 6px;
        }

        .links .nav-link:hover {
            text-decoration: underline;
        }

        .links .nav-link.is-active {
            background: #dbeafe;
            color: #1e3a8a;
            font-weight: 600;
            text-decoration: none;
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
    $pageHeading = trim($__env->yieldContent('page_heading'));
    if ($pageHeading === '') {
        $pageHeading = $pageTitle !== '' ? $pageTitle : 'Gateway Dashboard';
    }

    $navItems = [
        [
            'label' => 'Dashboard Home',
            'href' => '/dashboard',
            'patterns' => ['dashboard.home'],
        ],
        [
            'label' => 'SIM Fleet',
            'href' => '/dashboard/sims',
            'patterns' => ['dashboard.sims.*'],
        ],
        [
            'label' => 'Assignments',
            'href' => '/dashboard/assignments',
            'patterns' => ['dashboard.assignments.*'],
        ],
        [
            'label' => 'Migration',
            'href' => '/dashboard/migration',
            'patterns' => ['dashboard.migration.*'],
        ],
        [
            'label' => 'Message Status',
            'href' => '/dashboard/messages/status',
            'patterns' => ['dashboard.messages.status.*'],
        ],
        [
            'label' => 'Operators',
            'href' => '/dashboard/operators',
            'patterns' => ['dashboard.operators.*'],
        ],
        [
            'label' => 'Audit Log',
            'href' => '/dashboard/audit',
            'patterns' => ['dashboard.audit.*'],
        ],
    ];
@endphp

<h1>{{ $pageHeading }}</h1>

@if ($showNav)
    <div class="links">
        <span class="links-group">
            @foreach ($navItems as $item)
                @php
                    $isActive = request()->routeIs(...$item['patterns']);
                @endphp
                <a href="{{ $item['href'] }}" class="nav-link{{ $isActive ? ' is-active' : '' }}"{!! $isActive ? ' aria-current="page"' : '' !!}>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </span>
        <span class="links-group">
            <span class="links-label">Account:</span>
            @php
                $isAccountActive = request()->routeIs('dashboard.account.*');
                $isPasswordActive = request()->routeIs('dashboard.password.self.*');
            @endphp
            <a href="/dashboard/account" class="nav-link{{ $isAccountActive ? ' is-active' : '' }}"{!! $isAccountActive ? ' aria-current="page"' : '' !!}>
                My Account
            </a>
            <a href="/dashboard/password" class="nav-link{{ $isPasswordActive ? ' is-active' : '' }}"{!! $isPasswordActive ? ' aria-current="page"' : '' !!}>
                Change Password
            </a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                @csrf
                <button type="submit" class="logout-button">Logout</button>
            </form>
        </span>
    </div>
@endif

@if ($showOperatorContext)
    @include('dashboard.partials.operator-context')
@endif

@yield('content')

@stack('scripts')
</body>
</html>
