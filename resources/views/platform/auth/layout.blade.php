<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LonePawn Platform Auth')</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('loanpawn-64x64.png') }}">
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

        body.platform-login-page {
            background:
                radial-gradient(circle at top right, rgba(0,103,127,.8) 0%, transparent 45%),
                linear-gradient(135deg, #46636a 0%, #356575 50%, #00677f 100%);
        }

        body.platform-login-page .brand-panel,
        body.platform-login-page .form-panel {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.32);
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.24);
            backdrop-filter: blur(22px);
        }

        body.platform-login-page .brand-panel,
        body.platform-login-page .brand-panel p,
        body.platform-login-page .panel-header h2,
        body.platform-login-page label {
            color: var(--color-on-primary);
        }

        body.platform-login-page .panel-header p {
            color: rgba(255, 255, 255, 0.78);
        }

        body.platform-login-page .feature-list li {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.24);
            color: rgba(255, 255, 255, 0.82);
        }

        .shell {
            width: min(1040px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0;
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(360px, 1fr);
            gap: 24px;
            align-items: start;
        }

        .brand-panel,
        .form-panel {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            background: var(--color-surface);
            overflow: hidden;
        }

        .brand-panel {
            background: var(--color-brand);
            color: var(--color-on-primary);
            padding: 32px;
        }

        .eyebrow {
            display: inline-block;
            padding: 6px 10px;
            border: 1px solid var(--color-cta);
            border-radius: 999px;
            color: var(--color-cta);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .brand-panel h1 {
            margin: 18px 0 12px;
            font-size: 32px;
            line-height: 1.12;
            font-weight: 800;
        }

        .brand-panel p {
            margin: 0;
            max-width: 480px;
            color: var(--color-primary-200);
            line-height: 1.6;
        }

        .feature-list {
            margin: 28px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 12px;
        }

        .feature-list li {
            padding: 12px 14px;
            border: 1px solid var(--color-secondary-100);
            border-radius: var(--radius-md);
            background: var(--color-secondary-300);
            color: var(--color-primary-200);
            box-shadow: inset 3px 0 0 var(--color-cta);
        }

        .form-panel {
            padding: 32px;
        }

        .panel-header h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 28px;
        }

        .panel-header p {
            margin: 10px 0 0;
            color: var(--color-text-muted);
            line-height: 1.6;
        }

        .alert,
        .error-box,
        .form-status {
            margin-top: 20px;
            padding: 13px 14px;
            border-radius: var(--radius-md);
            line-height: 1.55;
        }

        .alert,
        .form-status.success {
            background: var(--color-success-soft);
            border: 1px solid var(--color-success);
            color: var(--color-success);
        }

        .error-box,
        .form-status.error {
            background: var(--color-danger-soft);
            border: 1px solid var(--color-danger);
            color: var(--color-danger);
        }

        .hidden {
            display: none !important;
        }

        form {
            margin-top: 24px;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .grid.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--color-heading);
            font-size: 13px;
            font-weight: 800;
        }

        input {
            width: 100%;
            border: 1px solid var(--color-border-strong);
            background: var(--color-surface);
            border-radius: var(--radius-md);
            padding: 12px;
            font: inherit;
            color: var(--color-text);
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: var(--shadow-focus);
        }

        .field-error {
            margin-top: 7px;
            color: var(--color-danger);
            font-size: 13px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 24px;
        }

        .button,
        button {
            appearance: none;
            min-height: 42px;
            border: 1px solid transparent;
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .button.primary,
        button.primary {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: var(--color-on-primary);
        }

        .button.primary:hover,
        button.primary:hover {
            background: var(--color-primary-hover);
            box-shadow: var(--shadow-focus);
        }

        .button.secondary,
        button.secondary {
            background: var(--color-surface);
            color: var(--color-heading);
            border: 1px solid var(--color-border-strong);
        }

        button[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .sub-links {
            margin-top: 18px;
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        .sub-links a {
            color: var(--color-primary);
            font-weight: 700;
            text-decoration: none;
        }

        .sub-links a:hover {
            text-decoration: underline;
        }

        .section-card {
            margin-top: 22px;
            padding: 20px;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            background: var(--color-background);
            box-shadow: inset 0 3px 0 var(--color-cta);
        }

        .section-card h3 {
            margin: 0 0 10px;
            color: var(--color-heading);
            font-size: 20px;
        }

        .section-card p {
            margin: 0;
            color: var(--color-text-muted);
            line-height: 1.6;
        }

        .otp-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: end;
        }

        .timer {
            margin-top: 8px;
            color: var(--color-text-muted);
            font-size: 13px;
        }

        @media (max-width: 920px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .grid.two,
            .otp-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="@yield('bodyClass')">
    <div class="shell">


        <section class="form-panel">
            <div class="panel-header">
                <h2>@yield('heading', 'Platform Access')</h2>
                <p>@yield('description')</p>
            </div>

            @yield('content')
        </section>
    </div>

    @stack('scripts')
</body>
</html>
