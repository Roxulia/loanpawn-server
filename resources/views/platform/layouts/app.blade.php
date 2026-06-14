<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LoanPawn Platform')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-layout">
<div class="platform-shell">
    <button type="button" class="platform-sidebar-backdrop" data-platform-menu-close aria-label="{{ __('app.common.view.actions.close') }}"></button>
    <aside class="platform-sidebar" id="platform-sidebar">
        <button type="button" class="mobile-sidebar-close" data-platform-menu-close aria-label="{{ __('app.common.view.actions.close') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
        <div class="platform-brand">
            <strong>LonePawn</strong>
            <span>{{ __('app.platform.view.workspace') }}</span>
        </div>

        <nav class="platform-nav" aria-label="{{ __('app.platform.view.workspace') }}">
            <a href="{{ route('platform.dashboard') }}" class="{{ request()->routeIs('platform.dashboard') ? 'active' : '' }}">{{ __('app.platform.view.dashboard') }}</a>
            <a href="{{ route('platform.tenants.index') }}" class="{{ request()->routeIs('platform.tenants.*') ? 'active' : '' }}">{{ __('app.platform.view.tenant_management') }}</a>
            <a href="{{ route('platform.billing.index') }}" class="{{ request()->routeIs('platform.billing.*') ? 'active' : '' }}">{{ __('app.platform.view.billing_management') }}</a>
            <a href="{{ route('platform.customer-service.index') }}" class="{{ request()->routeIs('platform.customer-service.*') ? 'active' : '' }}">{{ __('app.platform.view.customer_service') }}</a>
            <a href="{{ route('platform.settings') }}" class="{{ request()->routeIs('platform.settings') ? 'active' : '' }}">{{ __('app.platform.view.user_setting') }}</a>
        </nav>

        <form method="POST" action="{{ route('platform.logout') }}" style="margin-top: auto;">
            @csrf
            <button type="submit" class="logout-button">{{ __('app.common.view.actions.logout') }}</button>
        </form>
    </aside>

    <main class="platform-main">
        <header class="topbar">
            <button type="button" class="mobile-menu-button" data-platform-menu-open aria-controls="platform-sidebar" aria-expanded="false" aria-label="{{ __('app.common.view.actions.open') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <div>
                <h1>@yield('pageTitle', __('app.platform.view.dashboard'))</h1>
                <p>@yield('pageDescription')</p>
            </div>
            <div class="topbar-actions">
                @yield('pageAction')
                {{-- <div class="locale-toggle" aria-label="{{ __('app.common.view.locale.language') }}">
                    @foreach (config('app.supported_locales', ['en', 'mm']) as $locale)
                        <a href="{{ route('locale.set', $locale) }}" class="{{ app()->getLocale() === $locale ? 'active' : '' }}">
                            {{ strtoupper($locale) }}
                        </a>
                    @endforeach
                </div> --}}
            </div>
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
@stack('scripts')
<script>
    (function () {
        const openButton = document.querySelector('[data-platform-menu-open]');
        const closeButtons = document.querySelectorAll('[data-platform-menu-close]');
        const navLinks = document.querySelectorAll('.platform-sidebar a');

        function setMenuOpen(isOpen) {
            document.body.classList.toggle('platform-nav-open', isOpen);
            openButton?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        openButton?.addEventListener('click', function () {
            setMenuOpen(true);
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setMenuOpen(false);
            });
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
    })();
</script>
</body>
</html>
