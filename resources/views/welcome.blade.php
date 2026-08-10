@php
    $brandName = 'LonePawn';
    $baseUrl = rtrim((string) config('app.url'), '/');
    $canonicalUrl = $baseUrl . '/';
    $pageTitle = 'LonePawn | Pawn Shop Management Software for Myanmar';
    $pageDescription = 'LonePawn is pawn shop management software for Myanmar SMEs, covering pawn tickets, collateral, interest, inventory, accounting, staff access, and reporting.';
    $logoUrl = $baseUrl . '/loanpawn-64x64.png';
    $socialImageUrl = $baseUrl . '/images/landing/lonepawn-social-card.png';
    $dashboardImageUrl = $baseUrl . '/images/landing/lonepawn-preview.png';
    $registerUrl = Route::has('platform.register.show') ? route('platform.register.show') : url('/register');
    $loginUrl = Route::has('platform.login.show') ? route('platform.login.show') : url('/login');
    $faqs = [
        [
            'question' => 'What is LonePawn?',
            'answer' => 'LonePawn is web-based pawn shop management software that brings pawn tickets, collateral, interest calculations, inventory, accounting, staff access, and reporting into one platform.',
        ],
        [
            'question' => 'Who is LonePawn designed for?',
            'answer' => 'LonePawn is designed for small and medium-sized pawn shops in Myanmar that want a structured way to manage daily operations and business records.',
        ],
        [
            'question' => 'What can a pawn shop manage with LonePawn?',
            'answer' => 'Teams can manage customer and pawn records, pledged items, contract dates, interest calculations, redemptions, sale inventory, income, expenses, and operational reports.',
        ],
        [
            'question' => 'Does LonePawn support accounting workflows?',
            'answer' => 'Yes. LonePawn includes accounting-related features for recording income and expenses, tracking transactions, and reviewing financial reports alongside pawn operations.',
        ],
        [
            'question' => 'Can owners control staff access?',
            'answer' => 'Yes. Owners can manage staff roles and permissions so each user can access the appropriate operational workflows and information.',
        ],
        [
            'question' => 'Can a new shop try LonePawn before choosing a paid plan?',
            'answer' => 'Yes. Every new tenant starts with a four-month trial, allowing the shop to evaluate the platform before activating a paid plan.',
        ],
    ];
    $structuredData = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => $canonicalUrl . '#organization',
                'name' => $brandName,
                'url' => $canonicalUrl,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                    'width' => 64,
                    'height' => 64,
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $canonicalUrl . '#website',
                'name' => $brandName,
                'url' => $canonicalUrl,
                'description' => $pageDescription,
                'inLanguage' => 'en-MM',
                'publisher' => ['@id' => $canonicalUrl . '#organization'],
            ],
            [
                '@type' => 'SoftwareApplication',
                '@id' => $canonicalUrl . '#software',
                'name' => $brandName,
                'url' => $canonicalUrl,
                'description' => $pageDescription,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'inLanguage' => 'en-MM',
                'areaServed' => ['@type' => 'Country', 'name' => 'Myanmar'],
                'audience' => ['@type' => 'Audience', 'audienceType' => 'Myanmar SME pawn shops'],
                'publisher' => ['@id' => $canonicalUrl . '#organization'],
                'featureList' => [
                    'Pawn ticket and contract records',
                    'Collateral and inventory tracking',
                    'Interest calculations and expiry visibility',
                    'Income, expense, and accounting records',
                    'Staff roles and permissions',
                    'Operational dashboards and reports',
                ],
            ],
            [
                '@type' => 'FAQPage',
                '@id' => $canonicalUrl . '#faq',
                'mainEntity' => array_map(fn (array $faq) => [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ],
                ], $faqs),
            ],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en-MM" class="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="theme-color" content="#00677f">
    <meta name="application-name" content="{{ $brandName }}">

    <title>{{ $pageTitle }}</title>
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="alternate" hreflang="en-MM" href="{{ $canonicalUrl }}">
    <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('loanpawn-64x64.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('loanpawn-64x64.png') }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:locale" content="en_MM">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $socialImageUrl }}">
    <meta property="og:image:secure_url" content="{{ $socialImageUrl }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="LonePawn pawn shop management software for Myanmar">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $socialImageUrl }}">
    <meta name="twitter:image:alt" content="LonePawn pawn shop management software for Myanmar">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body class="landing-page">

<nav class="landing-nav" aria-label="Primary">
    <div class="landing-nav-inner">
        <a class="landing-brand" href="{{ url('/') }}" aria-label="{{ $brandName }} home">
            <img class="landing-brand-logo" src="{{ asset('loanpawn-64x64.png') }}" alt="{{ $brandName }}">
        </a>
        <div class="landing-nav-links" aria-label="Sections">
            <a href="#overview">Overview</a>
            <a href="#features">Features</a>
            <a href="#solutions">Solutions</a>
            <a href="#about">About</a>
            <a href="#faq">FAQ</a>
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
        <h1>Pawn Shop Management Software for Myanmar SMEs</h1>
        <p class="landing-hero-copy">
            Manage pawn tickets, collateral, interest, inventory, accounting, staff access, and reporting from one web-based platform.
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
            <img
                src="{{ $dashboardImageUrl }}"
                alt="LonePawn shop situation dashboard showing financial, risk, collateral, and accounting information"
                width="1340"
                height="629"
                decoding="async"
                fetchpriority="high"
            >
        </div>
    </div>
</header>

<main>
<section class="landing-section landing-overview" id="overview" aria-labelledby="overview-title">
    <div class="landing-section-inner landing-overview-grid">
        <div>
            <div class="landing-eyebrow">What is LonePawn?</div>
            <h2 id="overview-title">One system for daily pawn shop operations</h2>
        </div>
        <div class="landing-overview-copy">
            <p>
                LonePawn is web-based pawn shop management software for small and medium-sized businesses in Myanmar. It keeps operational and financial records connected, helping owners and teams work from consistent information.
            </p>
            <p>
                Use it to record customers and pledged items, manage pawn contracts and redemptions, calculate interest, track inventory, review income and expenses, control staff access, and understand business performance.
            </p>
        </div>
    </div>
</section>

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
                <p>Create structured pawn tickets with clear loan terms and customer data.</p>
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

<section class="landing-section landing-faq-section" id="faq" aria-labelledby="faq-title">
    <div class="landing-section-inner landing-faq-layout">
        <div class="landing-faq-intro">
            <div class="landing-eyebrow">Questions &amp; Answers</div>
            <h2 id="faq-title">Frequently asked questions</h2>
            <p>Direct answers about how LonePawn supports pawn shop operations in Myanmar.</p>
        </div>
        <div class="landing-faq-list">
            @foreach ($faqs as $faq)
                <details class="landing-faq-item" @if ($loop->first) open @endif>
                    <summary>
                        {{ $faq['question'] }}
                        <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    </summary>
                    <p>{{ $faq['answer'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

<section class="landing-section" id="get-started">
    <div class="landing-section-inner">
        <div class="landing-cta-card">
            <h2>Ready to simplify your pawn shop operations?</h2>
            <p>Create your account and start a four-month tenant trial with LonePawn.</p>
            <a class="landing-primary-button landing-primary-button-large landing-cta-button" href="{{ $registerUrl }}">
                Create Your Account
            </a>
        </div>
    </div>
</section>
</main>

<footer class="landing-footer">
    <div class="landing-footer-inner">
        <a class="landing-brand" href="{{ url('/') }}" aria-label="{{ $brandName }} home">
            <img class="landing-brand-logo" src="{{ asset('loanpawn-64x64.png') }}" alt="{{ $brandName }}">
        </a>
        <div class="landing-footer-links">
            <span>Pawn shop operations, license control, and portfolio visibility.</span>
            <span class="landing-powered-by">
                Powered by
                <a
                    class="landing-powered-brand"
                    href="https://1morerbit.tech"
                    target="_blank"
                    rel="noopener noreferrer"
                >1MOREBiT</a>
            </span>
            <div>
                <a href="{{ $loginUrl }}">Sign In</a>
                <a href="{{ $registerUrl }}">Register</a>
            </div>
        </div>
    </div>
</footer>
</body>
</html>
