<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LonePawn Admin')</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('loanpawn-64x64.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-layout platform-admin-layout">
@php
    $adminNavGroups = [
        ['label' => 'Overview', 'items' => [
            ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'label' => __('app.platform.view.dashboard'), 'icon' => 'dashboard'],
        ]],
        ['label' => 'Organization', 'items' => [
            ['route' => 'admin.tenants.index', 'pattern' => 'admin.tenants.*', 'label' => __('app.platform.view.tenant_management'), 'icon' => 'tenants'],
            ['route' => 'admin.platform-users.index', 'pattern' => 'admin.platform-users.*', 'label' => __('app.platform.view.platform_user_management'), 'icon' => 'users'],
        ]],
        ['label' => 'Plans & Entitlements', 'items' => [
            ['route' => 'admin.plans.index', 'pattern' => 'admin.plans.*', 'label' => 'Plans & Tenant Types', 'icon' => 'plans'],
            ['route' => 'admin.package-flags.index', 'pattern' => 'admin.package-flags.*', 'label' => __('app.platform.view.feature_plan_flags'), 'icon' => 'features'],
        ]],
        ['label' => 'Finance Configuration', 'items' => [
            ['route' => 'admin.currencies.index', 'pattern' => 'admin.currencies.*', 'label' => 'Currencies', 'icon' => 'currency'],
            ['route' => 'admin.exchange-pairs.index', 'pattern' => 'admin.exchange-pairs.*', 'label' => 'Exchange Pairs', 'icon' => 'pairs'],
            ['route' => 'admin.exchange-rates.index', 'pattern' => 'admin.exchange-rates.*', 'label' => 'Exchange Rates', 'icon' => 'rates'],
        ]],
        ['label' => 'Billing & Payments', 'items' => [
            ['route' => 'admin.billing.index', 'pattern' => 'admin.billing.*', 'label' => __('app.platform.view.billing_management'), 'icon' => 'billing'],
            ['route' => 'admin.payment-requests.index', 'pattern' => 'admin.payment-requests.*', 'label' => __('app.billing.view.payment_requests'), 'icon' => 'payments'],
            ['route' => 'admin.payment-qrs.index', 'pattern' => 'admin.payment-qrs.*', 'label' => __('app.platform.view.payment_qr_management'), 'icon' => 'qr'],
        ]],
        ['label' => 'Support', 'items' => [
            ['route' => 'admin.issued-tickets.index', 'pattern' => 'admin.issued-tickets.*', 'label' => __('app.support.view.issued_tickets'), 'icon' => 'support'],
        ]],
    ];
    $activeGroupMatch = collect($adminNavGroups)->first(fn ($group) => collect($group['items'])->contains(fn ($item) => request()->routeIs($item['pattern'])));
    $activeGroup = $activeGroupMatch['label'] ?? 'Admin';
@endphp

<header class="admin-mobile-header">
    <button type="button" class="admin-menu-button" data-admin-menu-open aria-controls="platform-sidebar" aria-expanded="false" aria-label="Open navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <div class="mobile-brand"><strong>LonePawn</strong><span>{{ __('app.platform.view.admin_workspace') }}</span></div>
    <div class="admin-mobile-locale">{{ strtoupper(app()->getLocale()) }}</div>
</header>
<button type="button" class="admin-sidebar-backdrop" data-admin-menu-close aria-label="Close navigation"></button>

<div class="platform-shell">
    <aside class="platform-sidebar" id="platform-sidebar" aria-label="{{ __('app.platform.view.admin_workspace') }}">
        <div class="platform-brand-row">
            <div class="platform-brand-mark" aria-hidden="true">LP</div>
            <div class="platform-brand"><strong>LonePawn</strong><span>{{ __('app.platform.view.admin_workspace') }}</span></div>
            <button type="button" class="admin-sidebar-close" data-admin-menu-close aria-label="Close navigation">&times;</button>
        </div>
        <nav class="platform-nav">
            @foreach ($adminNavGroups as $group)
                <section class="admin-nav-group" aria-labelledby="nav-group-{{ $loop->index }}">
                    <h2 id="nav-group-{{ $loop->index }}">{{ $group['label'] }}</h2>
                    <div class="admin-nav-list">
                        @foreach ($group['items'] as $item)
                            <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['pattern']) ? 'active' : '' }}" @if(request()->routeIs($item['pattern'])) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true">@include('platform.admin.partials.nav-icon', ['icon' => $item['icon']])</svg>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>
        <form method="POST" action="{{ route('admin.logout') }}" class="admin-sidebar-footer">
            @csrf
            <button type="submit" class="logout-button"><span aria-hidden="true">↪</span>{{ __('app.common.view.actions.logout') }}</button>
        </form>
    </aside>

    <main class="platform-main">
        <header class="admin-page-header">
            <div class="admin-page-heading">
                <p class="admin-breadcrumb"><span>Admin</span><span aria-hidden="true">/</span><strong>{{ $activeGroup }}</strong></p>
                <h1>@yield('pageTitle', __('app.platform.view.dashboard'))</h1>
                <p class="admin-page-description">@yield('pageDescription')</p>
            </div>
            <div class="topbar-actions">
                @yield('pageAction')
                <div class="locale-toggle" aria-label="{{ __('app.common.view.locale.language') }}">
                    @foreach (config('app.supported_locales', ['en', 'mm']) as $locale)
                        <a href="{{ route('locale.set', $locale) }}" class="{{ app()->getLocale() === $locale ? 'active' : '' }}">{{ strtoupper($locale) }}</a>
                    @endforeach
                </div>
            </div>
        </header>

        @if (session('status')) <div class="flash" role="status">{{ session('status') }}</div> @endif
        @if (session('error')) <div class="flash error" role="alert">{{ session('error') }}</div> @endif
        <div class="admin-content">@yield('content')</div>
    </main>
</div>
@stack('scripts')
<script>
    (function () {
        const openButton = document.querySelector('[data-admin-menu-open]');
        const closeButtons = document.querySelectorAll('[data-admin-menu-close]');
        const navLinks = document.querySelectorAll('.platform-sidebar a');
        function setMenuOpen(isOpen) {
            document.body.classList.toggle('platform-nav-open', isOpen);
            openButton?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
        openButton?.addEventListener('click', () => setMenuOpen(true));
        closeButtons.forEach((button) => button.addEventListener('click', () => setMenuOpen(false)));
        navLinks.forEach((link) => link.addEventListener('click', () => setMenuOpen(false)));
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setMenuOpen(false); });
        window.addEventListener('resize', () => { if (window.innerWidth > 940) setMenuOpen(false); });
    })();
</script>
</body>
</html>
