<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LonePawn Admin')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-layout platform-admin-layout">
@php
    $adminNavItems = [
        [
            'route' => 'admin.dashboard',
            'active' => request()->routeIs('admin.dashboard'),
            'label' => __('app.platform.view.dashboard'),
        ],
        [
            'route' => 'admin.tenants.index',
            'active' => request()->routeIs('admin.tenants.*'),
            'label' => __('app.platform.view.tenant_management'),
        ],
        [
            'route' => 'admin.platform-users.index',
            'active' => request()->routeIs('admin.platform-users.*'),
            'label' => __('app.platform.view.platform_user_management'),
        ],
        [
            'route' => 'admin.billing.index',
            'active' => request()->routeIs('admin.billing.*'),
            'label' => __('app.platform.view.billing_management'),
        ],
        [
            'route' => 'admin.package-flags.index',
            'active' => request()->routeIs('admin.package-flags.*'),
            'label' => __('app.platform.view.feature_plan_flags'),
        ],
        [
            'route' => 'admin.payment-requests.index',
            'active' => request()->routeIs('admin.payment-requests.*'),
            'label' => __('app.billing.view.payment_requests'),
        ],
        [
            'route' => 'admin.issued-tickets.index',
            'active' => request()->routeIs('admin.issued-tickets.*'),
            'label' => __('app.support.view.issued_tickets'),
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
                <span>{{ __('app.platform.view.admin_workspace') }}</span>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="mobile-logout-button">{{ __('app.common.view.actions.logout') }}</button>
        </form>
    </div>
    <div class="platform-mobile-nav" id="platform-mobile-nav">
        <nav aria-label="{{ __('app.platform.view.admin_workspace') }}">
            @foreach ($adminNavItems as $item)
                <a href="{{ route($item['route']) }}" class="{{ $item['active'] ? 'active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </div>
</header>

<div class="platform-shell">
    <aside class="platform-sidebar" id="platform-sidebar">
        <div class="platform-brand">
            <strong>LonePawn</strong>
            <span>{{ __('app.platform.view.admin_workspace') }}</span>
        </div>

        <nav class="platform-nav" aria-label="{{ __('app.platform.view.admin_workspace') }}">
            @foreach ($adminNavItems as $item)
                <a href="{{ route($item['route']) }}" class="{{ $item['active'] ? 'active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" style="margin-top: auto;">
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
                <div class="locale-toggle" aria-label="{{ __('app.common.view.locale.language') }}">
                    @foreach (config('app.supported_locales', ['en', 'mm']) as $locale)
                        <a href="{{ route('locale.set', $locale) }}" class="{{ app()->getLocale() === $locale ? 'active' : '' }}">
                            {{ strtoupper($locale) }}
                        </a>
                    @endforeach
                </div>
            </div>
        </header>

        <section class="mobile-page-heading">
            <h1>@yield('pageTitle', __('app.platform.view.dashboard'))</h1>
            <p>@yield('pageDescription')</p>
            <div class="mobile-page-actions">
                @yield('pageAction')
                <div class="locale-toggle" aria-label="{{ __('app.common.view.locale.language') }}">
                    @foreach (config('app.supported_locales', ['en', 'mm']) as $locale)
                        <a href="{{ route('locale.set', $locale) }}" class="{{ app()->getLocale() === $locale ? 'active' : '' }}">
                            {{ strtoupper($locale) }}
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
<script>
    (function () {
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
