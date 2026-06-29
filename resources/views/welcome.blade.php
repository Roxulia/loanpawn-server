<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="LonePawn is the operating system for SME pawn shops. Manage contracts, collateral, interest, risk, and tenant oversight from one platform.">

    <title>{{ config('app.name', 'LonePawn') }} - Operating System for SME Pawn Shops</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('loanpawn-64x64.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="landing-page">
@php
    $registerUrl = Route::has('platform.register.show') ? route('platform.register.show') : url('/register');
    $loginUrl = Route::has('platform.login.show') ? route('platform.login.show') : url('/login');
    $dashboardImage = 'https://lh3.googleusercontent.com/aida-public/AB6AXuChUk935TymLeZe6jKVlGR1y2aLeG48kwI0tehGNFSemyP59T9FpiJuyH2mfqMxbOkL2RZwJzdx8QV7ymCiC04_eGKWu9LtDUT0XN24ZHMyBbDHUe-rtDo30907q2BgDkMd-vZ0zEp1YaSaAxqbwach75z6MFYRXuxb3QXYEQN7Kf9pfpAbfSKhWN5k_me2U1K0WeL7MVQI5m68mmMHRSyq_USL5oxDuuBbzYW-AalOAT6lOVDH9Kg-rudVoB4UTru1bTCww9VipUg';
@endphp

<nav class="landing-nav" aria-label="Primary">
    <div class="landing-nav-inner">
        <a class="landing-brand" href="{{ url('/') }}" aria-label="{{ config('app.name', 'LonePawn') }} home">
            <img class="landing-brand-logo" src="{{ asset('loanpawn-64x64.png') }}" alt="{{ config('app.name', 'LonePawn') }}">
        </a>
        <div class="landing-nav-links" aria-label="Sections">
            <a href="#features">Features</a>
            <a href="#solutions">Solutions</a>
            <a href="#pricing">Pricing</a>
            <a href="#about">About</a>
        </div>
        <div class="landing-nav-actions">
            <a class="landing-link-button" href="{{ $loginUrl }}">Sign In</a>
            <a class="landing-primary-button" href="{{ $registerUrl }}">Register</a>
        </div>
    </div>
</nav>

<header class="landing-hero">
    <div class="landing-hero-inner">
        <div class="landing-badge">
            <span class="material-symbols-outlined" aria-hidden="true">verified</span>
            <span>Built for high-trust pawn shop operations</span>
        </div>
        <h1>The Operating System for SME Pawn Shops</h1>
        <p class="landing-hero-copy">
            Streamline contracts, collateral, and risk control with an all-in-one platform built for trust and efficiency.
        </p>
        <div class="landing-hero-actions">
            <a class="landing-primary-button landing-primary-button-large" href="{{ $registerUrl }}">
                Start Managing
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
            <a class="landing-secondary-button landing-secondary-button-large" href="{{ $loginUrl }}">
                Member Sign In
            </a>
        </div>
        <div class="landing-visual-card" aria-label="LonePawn dashboard preview">
            <img src="{{ $dashboardImage }}" alt="LonePawn dashboard interface preview">
        </div>
    </div>
</header>

<section class="landing-section landing-section-muted" id="features">
    <div class="landing-section-inner">
        <div class="landing-section-heading">
            <h2>Simplify Complex Operations</h2>
            <p>Focus on growing your business while we handle the operational heavy lifting.</p>
        </div>
        <div class="landing-card-grid">
            <article class="landing-card">
                <div class="landing-card-icon"><span class="material-symbols-outlined" aria-hidden="true">calculate</span></div>
                <h3>Interest Calculations</h3>
                <p>Automate complex interest rates, grace periods, and fee structures with precision across every slip.</p>
            </article>
            <article class="landing-card">
                <div class="landing-card-icon"><span class="material-symbols-outlined" aria-hidden="true">notifications_active</span></div>
                <h3>Expiring Slip Alerts</h3>
                <p>Never miss critical dates with automatic visibility into contracts nearing expiry and follow-up windows.</p>
            </article>
            <article class="landing-card">
                <div class="landing-card-icon"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></div>
                <h3>High-Volume Collateral</h3>
                <p>Organize, track, and locate pledged items quickly with structured item data and searchable records.</p>
            </article>
        </div>
    </div>
</section>

<section class="landing-section" id="solutions">
    <div class="landing-section-inner">
        <div class="landing-section-heading landing-section-heading-center">
            <h2>Seamless Daily Workflow</h2>
        </div>
        <div class="landing-step-grid">
            <article class="landing-step-card landing-step-card-a">
                <span class="landing-step-label">Step 1</span>
                <h3>Item Intake</h3>
                <p>Rapidly log items, upload photos, and assess initial valuation.</p>
            </article>
            <article class="landing-step-card landing-step-card-b">
                <span class="landing-step-label">Step 2</span>
                <h3>Contract Creation</h3>
                <p>Generate compliant tickets instantly with structured loan terms and customer data.</p>
            </article>
            <article class="landing-step-card landing-step-card-c">
                <span class="landing-step-label">Step 3</span>
                <h3>Collateral Tracking</h3>
                <p>Assign secure storage locations and monitor item status continuously.</p>
            </article>
            <article class="landing-step-card landing-step-card-d">
                <span class="landing-step-label">Step 4</span>
                <h3>Redemption / Sale</h3>
                <p>Process payments smoothly or transition defaulted items to retail inventory.</p>
            </article>
        </div>
    </div>
</section>

<section class="landing-section landing-section-muted" id="about">
    <div class="landing-section-inner landing-visibility-grid">
        <div>
            <div class="landing-eyebrow">Executive Dashboard</div>
            <h2 class="landing-visibility-title">Complete Visibility &amp; Control</h2>
            <p class="landing-visibility-copy">
                Maintain oversight of your operations. Review risk metrics, manage staff permissions, and keep license oversight and audit history in one place.
            </p>
            <ul class="landing-check-list">
                <li>
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <div>
                        <strong>Real-time Risk Metrics</strong>
                        <span>Monitor outstanding loans, expiring contracts, and pressure points instantly.</span>
                    </div>
                </li>
                <li>
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <div>
                        <strong>Staff Access Permissions</strong>
                        <span>Control what each user can view and do across operational workflows.</span>
                    </div>
                </li>
                <li>
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <div>
                        <strong>License Oversight</strong>
                        <span>Keep tenant licenses, plan limits, and billing visibility aligned.</span>
                    </div>
                </li>
            </ul>
        </div>

        <div class="landing-mini-dashboard">
            <article class="landing-mini-card landing-mini-card-top">
                <div class="landing-mini-card-head">
                    <span>Portfolio Risk Overview</span>
                    <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                </div>
                <div>
                    <span class="landing-mini-label">Loan-to-Value Average</span>
                    <strong>65%</strong>
                    <div class="landing-progress"><span style="width: 65%"></span></div>
                </div>
            </article>
            <article class="landing-mini-card landing-mini-card-bottom">
                <div class="landing-mini-card-head">
                    <span>Recent Alerts</span>
                    <span class="material-symbols-outlined" aria-hidden="true">warning</span>
                </div>
                <ul class="landing-alerts">
                    <li><span></span> Contract LP-23040 is overdue</li>
                    <li><span></span> System backup completed</li>
                </ul>
            </article>
        </div>
    </div>
</section>

<section class="landing-section" id="pricing">
    <div class="landing-section-inner">
        <div class="landing-cta-card">
            <h2>Ready to scale your pawn operations?</h2>
            <p>Join forward-thinking shops modernizing their workflow with LonePawn.</p>
            <a class="landing-primary-button landing-primary-button-large landing-cta-button" href="{{ $registerUrl }}">
                Create Your Account
            </a>
        </div>
    </div>
</section>

<footer class="landing-footer">
    <div class="landing-footer-inner">
        <a class="landing-brand" href="{{ url('/') }}" aria-label="{{ config('app.name', 'LonePawn') }} home">
            <img class="landing-brand-logo" src="{{ asset('loanpawn-64x64.png') }}" alt="{{ config('app.name', 'LonePawn') }}">
        </a>
        <div class="landing-footer-links">
            <span>Pawn shop operations, license control, and portfolio visibility.</span>
            <div>
                <a href="{{ $loginUrl }}">Sign In</a>
                <a href="{{ $registerUrl }}">Register</a>
            </div>
        </div>
    </div>
</footer>
</body>
</html>
