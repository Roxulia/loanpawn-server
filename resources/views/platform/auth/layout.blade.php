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
            color: var(--color-on-primary);
            background:
                radial-gradient(circle at 82% 12%, rgba(53, 213, 255, 0.46) 0%, transparent 34%),
                radial-gradient(circle at 12% 82%, rgba(125, 211, 252, 0.30) 0%, transparent 38%),
                linear-gradient(135deg, #233f48 0%, #1b5f71 48%, #00677f 100%);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(120deg, rgba(255, 255, 255, 0.10), transparent 38%),
                radial-gradient(circle at center, transparent 0%, rgba(0, 28, 38, 0.24) 100%);
        }

        .brand-panel,
        .brand-panel p,
        .panel-header h2,
        label {
            color: var(--color-on-primary);
        }

        .panel-header p {
            color: rgba(255, 255, 255, 0.82);
        }

        .feature-list li {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.24);
            color: rgba(255, 255, 255, 0.82);
        }

        .shell {
            position: relative;
            width: min(720px, calc(100% - 32px));
            min-height: 100vh;
            margin: 0 auto;
            padding: 32px 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            align-items: center;
            justify-items: center;
        }

        .brand-panel,
        .form-panel {
            border-radius: var(--radius-md);
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
            width: 100%;
            padding: 34px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.34);
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
        }

        .panel-header h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 28px;
        }

        .panel-header p {
            margin: 10px 0 0;
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
            font-size: 13px;
            font-weight: 800;
        }

        input {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.34);
            background: rgba(255, 255, 255, 0.86);
            border-radius: var(--radius-md);
            padding: 12px;
            font: inherit;
            color: var(--color-on-primary-container);
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        input:focus {
            outline: none;
            border-color: #67e8f9;
            box-shadow: 0 0 0 3px rgba(103, 232, 249, 0.28);
        }

        input[readonly] {
            background: rgba(255, 255, 255, 0.64);
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
            background: #35d5ff;
            border-color: #35d5ff;
            color: #002b36;
            box-shadow: 0 12px 30px rgba(53, 213, 255, 0.28);
        }

        .button.primary:hover,
        button.primary:hover {
            background: #7de7ff;
            border-color: #7de7ff;
            box-shadow: 0 0 0 3px rgba(125, 231, 255, 0.24), 0 16px 34px rgba(53, 213, 255, 0.32);
        }

        .button.secondary,
        button.secondary {
            background: rgba(255, 255, 255, 0.12);
            color: #e8fbff;
            border: 1px solid rgba(255, 255, 255, 0.34);
        }

        .button.secondary:hover,
        button.secondary:hover {
            background: rgba(255, 255, 255, 0.20);
            color: #ffffff;
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
            color: #9df1ff;
            font-weight: 800;
            text-decoration: none;
        }

        .sub-links a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .section-card {
            margin-top: 22px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.26);
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.10);
            box-shadow: inset 0 3px 0 #35d5ff;
        }

        .section-card h3 {
            margin: 0 0 10px;
            color: var(--color-on-primary);
            font-size: 20px;
        }

        .section-card p {
            margin: 0;
            color: rgba(255, 255, 255, 0.78);
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
            color: rgba(255, 255, 255, 0.76);
            font-size: 13px;
        }

        @media (max-width: 920px) {
            .shell {
                grid-template-columns: 1fr;
                align-items: center;
            }

            .grid.two,
            .otp-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .shell {
                width: min(100% - 20px, 720px);
                padding: 20px 0;
            }

            .form-panel {
                padding: 24px 18px;
            }
        }
    </style>
</head>
<body class="platform-auth-page @yield('bodyClass')">
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
