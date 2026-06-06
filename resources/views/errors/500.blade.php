<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('app.common.view.server_error') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: var(--font-sans);
            color: var(--color-text);
            background: var(--color-background);
        }
        main {
            width: min(680px, 100%);
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 28px;
        }
        h1 {
            margin: 0;
            color: var(--color-heading);
            font-size: 32px;
            letter-spacing: 0;
        }
        p {
            margin: 12px 0 0;
            color: var(--color-text-muted);
            line-height: 1.6;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 800;
            text-decoration: none;
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
    </style>
</head>
<body>
<main>
    <h1>{{ __('app.common.view.server_error') }}</h1>
    <p>{{ __('app.common.view.server_error_description') }}</p>
    <div class="actions">
        <a class="button primary" href="{{ route('platform.customer-service.create', [
            'type' => 'bugs',
            'subject' => __('app.common.view.server_error_report_subject'),
            'message' => __('app.common.view.server_error_report_message', ['url' => request()->fullUrl()]),
        ]) }}">{{ __('app.common.view.actions.report_to_admin') }}</a>
        <a class="button secondary" href="{{ route('platform.dashboard') }}">{{ __('app.common.view.actions.back_to_dashboard') }}</a>
    </div>
</main>
</body>
</html>
