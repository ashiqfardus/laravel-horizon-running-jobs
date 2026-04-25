@php
    /**
     * Standalone Horizon Running Jobs dashboard. Loads CSS + Alpine + the
     * dashboard component. Used as the /horizon/queue-monitor route.
     */
    $cssUrl = route('horizon-running-jobs.assets', ['file' => 'horizon-running-jobs.css']);
    $jsUrl = route('horizon-running-jobs.assets', ['file' => 'horizon-running-jobs.js']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Horizon Queue Monitor</title>
    <link rel="stylesheet" href="{{ $cssUrl }}">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="{{ $jsUrl }}"></script>
    <style>
        body {
            margin: 0;
            background: var(--hrj-bg-elev, #f9fafb);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; }
        }
        .hrj-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .hrj-page__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .hrj-page__header h1 {
            font-size: 18px;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="hrj-page">
        <div class="hrj hrj-page__header">
            <h1>Horizon Queue Monitor</h1>
            <span class="hrj-muted hrj-mono" style="font-size: 12px;">
                {{ gethostname() }} • {{ config('app.env') }}
            </span>
        </div>

        <x-horizon-running-jobs::dashboard />
    </div>
</body>
</html>
