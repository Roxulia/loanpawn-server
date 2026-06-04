<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LonePawn Admin')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font-sans);
            color: var(--color-text);
            background: var(--color-background);
        }
        a { color: inherit; }
        .platform-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
        }
        .platform-sidebar {
            background: var(--color-brand);
            color: var(--color-on-primary);
            padding: 24px 18px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .platform-brand {
            padding: 8px 10px 18px;
            border-bottom: 1px solid var(--color-secondary-100);
        }
        .platform-brand strong { display: block; font-size: 20px; }
        .platform-brand span {
            display: block;
            margin-top: 6px;
            color: var(--color-primary-300);
            font-size: 13px;
        }
        .platform-nav { display: grid; gap: 6px; }
        .platform-nav a,
        .logout-button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 12px;
            background: transparent;
            color: var(--color-primary-200);
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .platform-nav a.active,
        .platform-nav a:hover,
        .logout-button:hover {
            background: var(--color-secondary-100);
            border-color: var(--color-secondary-100);
            box-shadow: inset 3px 0 0 var(--color-cta);
            color: var(--color-on-primary);
        }
        .platform-main { min-width: 0; padding: 28px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        .topbar h1 {
            margin: 0;
            color: var(--color-heading);
            font-size: 30px;
            letter-spacing: 0;
        }
        .topbar p {
            margin: 8px 0 0;
            max-width: 720px;
            color: var(--color-text-muted);
            line-height: 1.6;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }
        .button.primary {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: var(--color-on-primary);
        }
        .button.secondary {
            background: var(--color-surface);
            border-color: var(--color-border-strong);
            color: var(--color-heading);
        }
        .button.danger {
            background: var(--color-danger-soft);
            border-color: var(--color-danger);
            color: var(--color-danger);
        }
        .panel {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
        }
        .grid { display: grid; gap: 16px; }
        .grid.kpi { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .metric-label {
            margin: 0;
            color: var(--color-text-muted);
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .metric-value {
            margin: 10px 0 0;
            color: var(--color-heading);
            font-size: 30px;
            font-weight: 900;
        }
        .muted { color: var(--color-text-muted); }
        .empty-state {
            min-height: 240px;
            display: grid;
            place-items: center;
            text-align: center;
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 13px 12px;
            border-bottom: 1px solid var(--color-border);
            text-align: left;
            white-space: nowrap;
        }
        th {
            background: var(--color-background);
            border-bottom-color: var(--color-border-strong);
            color: var(--color-heading);
            font-size: 13px;
            text-transform: uppercase;
        }
        .badge {
            display: inline-flex;
            border-radius: 999px;
            padding: 5px 9px;
            border: 1px solid var(--color-border-strong);
            background: var(--color-background);
            color: var(--color-heading);
            font-size: 12px;
            font-weight: 800;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        label {
            display: block;
            margin-bottom: 7px;
            color: var(--color-heading);
            font-size: 13px;
            font-weight: 800;
        }
        input, textarea, select {
            width: 100%;
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 12px;
            background: var(--color-surface);
            color: var(--color-text);
            font: inherit;
        }
        textarea { min-height: 96px; resize: vertical; }
        .field-error {
            margin-top: 6px;
            color: var(--color-danger);
            font-size: 13px;
        }
        .flash {
            margin-bottom: 18px;
            padding: 13px 14px;
            border-radius: 8px;
            background: var(--color-success-soft);
            border: 1px solid var(--color-success);
            color: var(--color-success);
            font-weight: 700;
        }
        .pagination { margin-top: 16px; }
        .action-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .ticket-toast-container {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 50;
            display: grid;
            gap: 10px;
            width: min(360px, calc(100vw - 36px));
        }
        .ticket-toast {
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 12px 14px;
            background: var(--color-surface);
            color: var(--color-heading);
            box-shadow: 0 12px 32px rgba(3, 0, 61, 0.14);
            font-weight: 700;
        }
        .ticket-live-highlight {
            animation: ticket-live-highlight 4s ease;
        }
        @keyframes ticket-live-highlight {
            0%, 70% { background: var(--color-success-soft); }
            100% { background: transparent; }
        }
        @media (max-width: 940px) {
            .platform-shell { grid-template-columns: 1fr; }
            .grid.kpi, .grid.two, .form-grid { grid-template-columns: 1fr; }
            .topbar { display: grid; }
        }
    </style>
</head>
<body>
<div class="platform-shell">
    <aside class="platform-sidebar">
        <div class="platform-brand">
            <strong>LonePawn</strong>
            <span>Admin Workspace</span>
        </div>

        <nav class="platform-nav" aria-label="Admin navigation">
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('admin.tenants.index') }}" class="{{ request()->routeIs('admin.tenants.*') ? 'active' : '' }}">Tenant Management</a>
            <a href="{{ route('admin.platform-users.index') }}" class="{{ request()->routeIs('admin.platform-users.*') ? 'active' : '' }}">Platform User Management</a>
            <a href="{{ route('admin.billing.index') }}" class="{{ request()->routeIs('admin.billing.*') ? 'active' : '' }}">Billing Management</a>
            <a href="{{ route('admin.package-flags.index') }}" class="{{ request()->routeIs('admin.package-flags.*') ? 'active' : '' }}">Feature & Plan Flags</a>
            <a href="{{ route('admin.payment-requests.index') }}" class="{{ request()->routeIs('admin.payment-requests.*') ? 'active' : '' }}">Payment Requests</a>
            <a href="{{ route('admin.issued-tickets.index') }}" class="{{ request()->routeIs('admin.issued-tickets.*') ? 'active' : '' }}">Issued Tickets</a>
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" style="margin-top: auto;">
            @csrf
            <button type="submit" class="logout-button">Logout</button>
        </form>
    </aside>

    <main class="platform-main">
        <header class="topbar">
            <div>
                <h1>@yield('pageTitle', 'Dashboard')</h1>
                <p>@yield('pageDescription')</p>
            </div>
            @yield('pageAction')
        </header>

        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
