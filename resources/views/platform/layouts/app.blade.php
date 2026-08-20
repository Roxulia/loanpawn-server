<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LoanPawn Platform')</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('loanpawn-64x64.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-layout">
@php
    $platformNavItems = [
        [
            'route' => 'platform.dashboard',
            'active' => request()->routeIs('platform.dashboard'),
            'label' => __('app.platform.view.dashboard'),
        ],
        [
            'route' => 'platform.tenants.index',
            'active' => request()->routeIs('platform.tenants.*'),
            'label' => __('app.platform.view.tenant_management'),
        ],
        [
            'route' => 'platform.billing.index',
            'active' => request()->routeIs('platform.billing.*'),
            'label' => __('app.platform.view.billing_management'),
        ],
        [
            'route' => 'platform.customer-service.index',
            'active' => request()->routeIs('platform.customer-service.*'),
            'label' => __('app.platform.view.customer_service'),
        ],
        [
            'route' => 'platform.settings',
            'active' => request()->routeIs('platform.settings'),
            'label' => __('app.platform.view.user_setting'),
        ],
    ];
@endphp

<header class="platform-mobile-header">
    <div class="platform-mobile-bar">
        <div class="mobile-brand-row">
            <button type="button" class="mobile-menu-button" data-platform-menu-open aria-controls="platform-mobile-nav" aria-expanded="false" aria-label="{{ __('app.common.view.actions.open') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <div class="mobile-brand">
                <strong>LonePawn</strong>
                <span>{{ __('app.platform.view.workspace') }}</span>
            </div>
        </div>
        <form method="POST" action="{{ route('platform.logout') }}">
            @csrf
            <button type="submit" class="mobile-logout-button">{{ __('app.common.view.actions.logout') }}</button>
        </form>
    </div>
    <div class="platform-mobile-nav" id="platform-mobile-nav">
        <nav aria-label="{{ __('app.platform.view.workspace') }}">
            @foreach ($platformNavItems as $item)
                <a href="{{ route($item['route']) }}" class="{{ $item['active'] ? 'active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </div>
</header>

<div class="platform-shell">
    <aside class="platform-sidebar" id="platform-sidebar">
        <div class="platform-brand">
            <strong>LonePawn</strong>
            <span>{{ __('app.platform.view.workspace') }}</span>
        </div>

        <nav class="platform-nav" aria-label="{{ __('app.platform.view.workspace') }}">
            @foreach ($platformNavItems as $item)
                <a href="{{ route($item['route']) }}" class="{{ $item['active'] ? 'active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <form method="POST" action="{{ route('platform.logout') }}" style="margin-top: auto;">
            @csrf
            <button type="submit" class="logout-button">{{ __('app.common.view.actions.logout') }}</button>
        </form>
    </aside>

    <main class="platform-main">
        <header class="topbar">
            <div>
                <h1>@yield('pageTitle', __('app.platform.view.dashboard'))</h1>
                <p>@yield('pageDescription')</p>
            </div>
            <div class="topbar-actions">
                @yield('pageAction')
            </div>
        </header>

        <section class="mobile-page-heading">
            <h1>@yield('pageTitle', __('app.platform.view.dashboard'))</h1>
            <p>@yield('pageDescription')</p>
            <div class="mobile-page-actions">
                @yield('pageAction')
            </div>
        </section>

        @if (session('status'))
            <div class="flash" role="status" data-auto-dismiss>{{ session('status') }}<button type="button" data-dismiss-flash aria-label="Dismiss">&times;</button></div>
        @endif

        @if (session('error'))
            <div class="flash error" role="alert">{{ session('error') }}<button type="button" data-dismiss-flash aria-label="Dismiss">&times;</button></div>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-dismiss-flash]').forEach(function (button) { button.addEventListener('click', function () { button.closest('.flash')?.remove(); }); });
        document.querySelectorAll('[data-auto-dismiss]').forEach(function (flash) { window.setTimeout(function () { flash.remove(); }, 5000); });
        const openButton = document.querySelector('[data-platform-menu-open]');
        const navLinks = document.querySelectorAll('.platform-mobile-nav a');

        function setMenuOpen(isOpen) {
            document.body.classList.toggle('platform-nav-open', isOpen);
            openButton?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        openButton?.addEventListener('click', function () {
            setMenuOpen(!document.body.classList.contains('platform-nav-open'));
        });

        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                setMenuOpen(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 940) {
                setMenuOpen(false);
            }
        });
    })();
</script>
</body>
</html>
