<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LonePawn Platform')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font-sans);
            color: var(--color-text);
            background: var(--color-background);
        }

        a {
            color: inherit;
        }

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

        .platform-brand strong {
            display: block;
            font-size: 20px;
        }

        .platform-brand span {
            display: block;
            margin-top: 6px;
            color: var(--color-primary-300);
            font-size: 13px;
        }

        .platform-nav {
            display: grid;
            gap: 6px;
        }

        .platform-nav a,
        .logout-button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 12px 12px;
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

        .platform-main {
            min-width: 0;
            padding: 28px;
        }

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
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .button.primary {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: var(--color-on-primary);
        }

        .button.primary:hover {
            background: var(--color-primary-hover);
            box-shadow: var(--shadow-focus);
        }

        .button.secondary {
            background: var(--color-surface);
            border-color: var(--color-border-strong);
            color: var(--color-heading);
        }

        .button.secondary:hover {
            border-color: var(--color-primary);
            box-shadow: var(--shadow-focus);
        }

        .panel {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
        }

        .grid.kpi .panel {
            border-color: var(--color-border);
            box-shadow: inset 0 3px 0 var(--color-cta);
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .grid.kpi {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .grid.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

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

        .muted {
            color: var(--color-text-muted);
        }

        .empty-state {
            min-height: 280px;
            display: grid;
            place-items: center;
            text-align: center;
        }

        .empty-state h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 24px;
        }

        .empty-state p {
            margin: 10px auto 18px;
            max-width: 540px;
            color: var(--color-text-muted);
            line-height: 1.6;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
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

        input,
        textarea,
        select {
            width: 100%;
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 12px;
            background: var(--color-surface);
            color: var(--color-text);
            font: inherit;
        }

        textarea {
            min-height: 96px;
            resize: vertical;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: 0;
            border-color: var(--color-primary);
            box-shadow: var(--shadow-focus);
        }

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

        .flash.error {
            background: var(--color-danger-soft);
            border-color: var(--color-danger);
            color: var(--color-danger);
        }

        .pagination {
            margin-top: 16px;
        }

        .platform-dialog {
            width: min(560px, calc(100% - 32px));
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            background: var(--color-surface);
            color: var(--color-text);
            box-shadow: 0 24px 60px rgba(3, 0, 61, 0.18);
        }

        .platform-dialog::backdrop {
            background: rgba(0, 0, 21, 0.42);
        }

        .dialog-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-bottom: 16px;
        }

        .dialog-header h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 22px;
        }

        .dialog-close {
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 8px 10px;
            background: var(--color-surface);
            color: var(--color-heading);
            cursor: pointer;
        }

        @media (max-width: 940px) {
            .platform-shell {
                grid-template-columns: 1fr;
            }

            .platform-sidebar {
                position: static;
            }

            .grid.kpi,
            .grid.two,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                display: grid;
            }
        }
    </style>
</head>
<body>
<div class="platform-shell">
    <aside class="platform-sidebar">
        <div class="platform-brand">
            <strong>LonePawn</strong>
            <span>Platform Workspace</span>
        </div>

        <nav class="platform-nav" aria-label="Platform navigation">
            <a href="{{ route('platform.dashboard') }}" class="{{ request()->routeIs('platform.dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('platform.tenants.index') }}" class="{{ request()->routeIs('platform.tenants.*') ? 'active' : '' }}">Tenant Management</a>
            <a href="{{ route('platform.billing.index') }}" class="{{ request()->routeIs('platform.billing.*') ? 'active' : '' }}">Billing Management</a>
            <a href="{{ route('platform.customer-service.index') }}" class="{{ request()->routeIs('platform.customer-service.*') ? 'active' : '' }}">Customer Service</a>
        </nav>

        <form method="POST" action="{{ route('platform.logout') }}" style="margin-top: auto;">
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

        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</div>
</body>
</html>
