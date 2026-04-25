# Laravel Horizon Running Jobs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![License](https://img.shields.io/packagist/l/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)

**Monitor currently running jobs in Laravel Horizon.**

Laravel Horizon shows pending, completed, and failed jobs—but not what's **currently running**. This package fills that gap for single-instance setups and distributed deployments where multiple Laravel applications share a single Redis.

---

## Features

- 🔍 **Real-time Monitoring** - See jobs as they execute
- 🖥️ **CLI Command** - `php artisan horizon:running-jobs`
- 🌐 **HTTP API** - JSON endpoint for dashboards
- 🏢 **Distributed-Aware** - Filter by the current instance or view all jobs across instances sharing the same Redis
- ⏱️ **Duration Tracking** - See how long each job has been running
- 📊 **Statistics** - Aggregate stats by server, queue, and job class
- 💾 **Response Caching** - Configurable caching for high-traffic APIs

---

## Requirements

| Package | Versions Supported |
|---------|-------------------|
| PHP | 8.1, 8.2, 8.3, 8.4 |
| Laravel | 9.x, 10.x, 11.x, 12.x, 13.x |
| Horizon | 5.x, 6.x |
| Redis | 6.0+ |

> Laravel 13 requires PHP 8.3+. Laravel 11 / 12 require PHP 8.2+. Composer resolves the correct Laravel version for your PHP automatically.

---

## Installation

### Step 1: Install via Composer

```bash
composer require ashiqfardus/horizon-running-jobs
```

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=horizon-running-jobs-config
```

### Step 3: Choose Your Setup

#### 🖥️ Single Server Setup (Default)

If you have **one application server** with Redis on the same or separate machine, no additional configuration is needed. The package works out of the box:

```php
// config/horizon-running-jobs.php
'distributed' => false,  // Default - shows all running jobs
```

**That's it!** Just run:
```bash
php artisan horizon:running-jobs
```

#### 🌐 Distributed Setup (Shared Redis)

Enable distributed mode whenever **more than one Laravel application instance shares a single Redis**. That covers any of:

- Several apps on the same machine
- Apps spread across multiple hosts
- Containers / pods pointing at the same Redis service

```php
// config/horizon-running-jobs.php
'distributed' => true,
```

With this enabled, each instance only sees jobs reserved by its own Horizon supervisor. Use `--all` (CLI) or `?all=true` (HTTP) to view everything across the cluster.

**Server identification depends on your `horizon.php` setup:**

##### Option A: Using `gethostname()` (Auto-detected ✅)

If your `horizon.php` keys supervisors by `gethostname()`:

```php
// config/horizon.php
'defaults' => [
    gethostname() => [
        'connection' => 'redis',
        'queue' => ['default'],
    ],
],
```

No additional configuration needed — each instance automatically identifies itself by its hostname. Works for separate hosts; for multiple instances on one machine, see Option B.

##### Option B: Using Static Names (Manual config required)

When each instance uses a fixed supervisor name (typical for containers, multi-tenant single-host deployments, or any setup where `gethostname()` doesn't uniquely identify the instance):

```php
// config/horizon.php
'defaults' => [
    'supervisor-01' => [...],
    'supervisor-02' => [...],
],
```

Tell each instance which supervisor it is — usually from an env var so the same image can be deployed to multiple targets:

```php
// config/horizon-running-jobs.php
'server_identifier' => env('HORIZON_SUPERVISOR_NAME'),
```

Then set the env var per instance:

```bash
# Instance 1
HORIZON_SUPERVISOR_NAME=supervisor-01

# Instance 2
HORIZON_SUPERVISOR_NAME=supervisor-02
```

For containers / pods, set the env var via your orchestrator (Kubernetes downward API, Docker Compose service name, etc.).

---

**Then** add the `TracksServer` trait to your job classes:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;

class YourJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TracksServer; // ← Add this trait

    public function __construct()
    {
        $this->initializeServerTracking(); // ← Call in constructor
    }

    public function handle(): void
    {
        // Your job logic
    }
}
```

This allows filtering jobs by server:
```bash
# Show jobs on current server only
php artisan horizon:running-jobs

# Show jobs from all servers
php artisan horizon:running-jobs --all
```

That's it! 🎉

---

## Usage

### CLI Command

```bash
# List running jobs on current server
php artisan horizon:running-jobs

# Show jobs from ALL servers
php artisan horizon:running-jobs --all

# Monitor specific queues
php artisan horizon:running-jobs --queue=emails --queue=notifications

# Limit results
php artisan horizon:running-jobs --limit=50

# Output as JSON
php artisan horizon:running-jobs --json

# Show statistics
php artisan horizon:running-jobs --stats
```

### Inspecting supervisors and master processes

```bash
# List every Horizon supervisor registered in Redis (across all instances)
php artisan horizon:supervisors

# Include the master process table
php artisan horizon:supervisors --masters

# Raw JSON for scripting
php artisan horizon:supervisors --json
```

#### Example output

```
+-----------------------------------+---------+------+------------------------+-------+---------+
| Name                              | Status  | PID  | Queues                 | Procs | Expires |
+-----------------------------------+---------+------+------------------------+-------+---------+
| supervisor-01:app-01.example.com  | running | 8298 | default,emails,reports | 3     | 67s     |
| supervisor-02:app-02.example.com  | running | 4521 | default,emails,reports | 3     | 73s     |
| supervisor-03:app-03.example.com  | ⚠ stale | -    | -                      | 0     | OVERDUE 12s |
+-----------------------------------+---------+------+------------------------+-------+---------+
⚠ 1 supervisor(s) past their expiry — workers may have died without cleanup.
```

`Expires` is the time until the registration lapses if the supervisor stops pinging. A supervisor whose registration has already expired but has not yet been reaped is marked `⚠ stale` — usually a sign that the worker process died.

#### Example Output

```
🔍 Scanning queues: default
📍 Current host: app-server-01

+----------+------------------------+----------+---------------+----------+----------+----------+
| ID       | Job                    | Queue    | Server        | Started  | Duration | Attempts |
+----------+------------------------+----------+---------------+----------+----------+----------+
| 4b5ecc82 | App\Jobs\ProcessOrder  | default  | app-server-01 | 14:30:15 | 2m 34s   | 1        |
| 8a2b3c4d | App\Jobs\SendEmail     | emails   | app-server-01 | 14:31:42 | 45s      | 1        |
+----------+------------------------+----------+---------------+----------+----------+----------+

✓ Found 2 running job(s)
```

### HTTP API

The package automatically registers API routes (configurable):

```bash
# List running jobs
GET /api/horizon/running-jobs

# Show all servers
GET /api/horizon/running-jobs?all=true

# Specific queues
GET /api/horizon/running-jobs?queues=emails,reports

# Get statistics
GET /api/horizon/running-jobs/stats

# Inspect supervisors and masters
GET /api/horizon/supervisors
```

#### Example Response

```json
{
  "success": true,
  "hostname": "app-server-01",
  "timestamp": "2026-04-25T10:30:00+00:00",
  "queues_monitored": ["default", "emails", "reports"],
  "running_jobs_count": 2,
  "total_count": 2,
  "dropped_count": 0,
  "jobs": [
    {
      "job_id": "4b5ecc82-07a7-40db-97db-bfab5ac5c500",
      "job_class": "App\\Jobs\\ProcessOrder",
      "queue": "default",
      "server": "app-server-01",
      "status": "running",
      "start_time": "2026-04-25T10:27:26+00:00",
      "start_timestamp": 1745576846,
      "running_for_seconds": 154,
      "running_for_formatted": "2m 34s",
      "attempts": 1,
      "timeout": 120,
      "tags": ["server:app-server-01", "environment:production"]
    }
  ],
  "warnings": []
}
```

#### Response fields

| Field | Meaning |
|---|---|
| `running_jobs_count` | jobs returned in this payload (may be limited by `max_jobs`) |
| `total_count` | total reserved-set entries found before truncation |
| `dropped_count` | malformed reserved-set entries skipped; each is logged via `Log::warning` |
| `jobs[].status` | `"running"` (reservation valid) or `"zombie"` (reservation expired, still in queue) |
| `jobs[].start_time` / `start_timestamp` | **actual reservation time** (not the Redis expiry score) |
| `warnings[]` | human-readable summary lines — long-running, zombie count, dropped count |

### Using the Facade

```php
use Ashiqfardus\HorizonRunningJobs\Facades\RunningJobs;

// Get running jobs for current server
$result = RunningJobs::getRunningJobs();

// Get running jobs from all servers
$result = RunningJobs::getRunningJobs(null, true);

// Get running jobs for specific queues
$result = RunningJobs::getRunningJobs(null, false, ['emails', 'reports']);

// Get statistics
$stats = RunningJobs::getStats();
```

---

## Configuration

After publishing the config file, you can customize:

```php
// config/horizon-running-jobs.php

return [
    // Default queues to monitor (null = auto-detect from Horizon)
    'queues' => null,

    // Maximum jobs per query (prevents memory issues)
    'max_jobs' => 1000,

    // Long-running job threshold in seconds
    'long_running_threshold' => 300,

    // API response caching
    'cache' => [
        'enabled' => true,
        'ttl' => 10,
        'prefix' => 'horizon_running_jobs',
    ],

    // Route configuration
    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api'], // Add 'auth:sanctum' for protection
        'uri' => 'horizon/running-jobs',
    ],

    // Redis connection (null = auto-detect from config('horizon.use'))
    'redis_connection' => null,

    // Queue retry_after window in seconds.
    // Redis stores reserved-set scores as (reservation_time + retry_after).
    // Leave null to auto-detect from config('queue.connections.<horizon.use>.retry_after'),
    // falling back to 90. Override only if the heuristic picks the wrong value.
    'retry_after' => null,
];
```

### How `status` is decided

Each job in the response has a `status` field:

- `"running"` — the reservation's expiry is still in the future (normal).
- `"zombie"` — the reservation has expired but the entry is still in `queues:<q>:reserved`. This usually means the worker processing the job died (OOM, SIGKILL) or Horizon hasn't reaped it yet. Warnings include a zombie count.

Previously these jobs were silently dropped from the response. They are now surfaced so operators can see them and release/retry them manually.

---

## Dashboard Integration

This package provides multiple ways to display running jobs in a web interface.

### Option 1: Standalone JavaScript Widget

The easiest way to add a running jobs panel to any page:

```bash
# Publish the assets
php artisan vendor:publish --tag=horizon-running-jobs-assets
```

Then add to your HTML:

```html
<!-- Add the widget container -->
<div id="running-jobs-widget"></div>

<!-- Include the widget script -->
<script src="/vendor/horizon-running-jobs/widget.js"></script>

<!-- Initialize -->
<script>
    HorizonRunningJobs.init({
        container: '#running-jobs-widget',
        apiUrl: '/api/horizon/running-jobs',
        refreshInterval: 5000,  // Auto-refresh every 5 seconds
        showAllServers: false
    });
</script>
```

### Option 2: Vue.js Component

For Vue.js applications, copy the component from the published assets:

```javascript
// In your Vue app
import RunningJobs from './vendor/horizon-running-jobs/components/RunningJobs.vue';

export default {
    components: {
        RunningJobs
    }
}
```

```html
<template>
    <running-jobs />
</template>
```

### Option 3: Custom Integration via API

Build your own UI by consuming the JSON API:

```javascript
// Fetch running jobs
fetch('/api/horizon/running-jobs?all=true')
    .then(response => response.json())
    .then(data => {
        console.log(`${data.running_jobs_count} jobs running`);
        data.jobs.forEach(job => {
            console.log(`${job.job_class} on ${job.server} - ${job.running_for_formatted}`);
        });
    });

// Fetch statistics
fetch('/api/horizon/running-jobs/stats')
    .then(response => response.json())
    .then(data => {
        console.log('Stats:', data.stats);
    });
```

### Option 4: Blade Component (DIY)

Create a simple Blade component:

```php
// resources/views/components/running-jobs.blade.php
@php
    $runningJobs = app(\Ashiqfardus\HorizonRunningJobs\RunningJobsManager::class)
        ->getRunningJobs(null, true);
@endphp

<div class="running-jobs-panel">
    <h3>Running Jobs ({{ count($runningJobs['jobs']) }})</h3>
    
    @forelse($runningJobs['jobs'] as $job)
        <div class="job-item {{ $job['running_for_seconds'] > 300 ? 'warning' : '' }}">
            <strong>{{ class_basename($job['job_class']) }}</strong>
            <span>{{ $job['queue'] }}</span>
            <span>{{ $job['server'] }}</span>
            <span>{{ $job['running_for_formatted'] }}</span>
        </div>
    @empty
        <p>No jobs currently running</p>
    @endforelse
</div>
```

### Option 5: Standalone Dashboard Page (Recommended)

Create a dedicated page that matches Horizon's dark theme:

**1. Create a route:**

```php
// routes/web.php
Route::get('/running-jobs', function () {
    return view('running-jobs');
})->middleware(['web']); // Add your auth middleware
```

> **Important:** Do NOT use `/horizon/*` path as it conflicts with Horizon's routes.

**2. Create the view:**

```blade
{{-- resources/views/running-jobs.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Running Jobs - Horizon</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e; 
            color: #fff; 
            min-height: 100vh;
        }
        .nav { 
            background: #16162a; 
            padding: 16px 24px; 
            display: flex; 
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #2a2a4a;
        }
        .nav h1 { font-size: 18px; font-weight: 600; }
        .nav a { color: #6366f1; text-decoration: none; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        #running-jobs-widget { margin-top: 20px; }
    </style>
</head>
<body>
    <nav class="nav">
        <h1>🔄 Running Jobs</h1>
        <a href="/horizon">← Back to Horizon</a>
    </nav>
    
    <div class="container">
        <div id="running-jobs-widget"></div>
    </div>

    <script src="/vendor/horizon-running-jobs/widget.js"></script>
    <script>
        HorizonRunningJobs.init({
            container: '#running-jobs-widget',
            apiUrl: '/api/horizon/running-jobs',
            refreshInterval: 3000,
            showAllServers: true
        });
    </script>
</body>
</html>
```

**3. Access at:** `http://your-app.com/running-jobs`

**4. (Optional) Add a link in Horizon dashboard:**

You can add a custom link to your running jobs page by publishing Horizon's views and modifying them, or simply bookmark the `/running-jobs` URL.

> **Note:** Direct integration into Horizon's compiled Vue dashboard requires forking the Horizon package, which is not recommended as it complicates upgrades.

---

## How It Works

### The Problem

Laravel Horizon stores running jobs in Redis sorted sets:
- Key: `queues:{queue_name}:reserved`
- Score: Unix timestamp when job was picked up
- Value: JSON payload with job details

But Horizon doesn't expose this data per-server.

### The Solution

This package queries Redis directly and uses a **hybrid identification system**:

1. **Primary**: Horizon tags (`server:hostname`)
2. **Fallback**: `supervisor_id` property on the job class

This ensures 100% reliability across different job configurations.

### Distributed Architecture

```
                    ┌─────────────────┐
                    │  Redis Server   │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
         ┌────▼────┐    ┌────▼────┐   ┌────▼────┐
         │ Server A│    │ Server B│   │ Server C│
         │ 5 jobs  │    │ 3 jobs  │   │ 7 jobs  │
         └─────────┘    └─────────┘   └─────────┘
```

Each server can see its own jobs or all jobs across the cluster.

---

## Alternative: Manual Setup (Without Trait)

If you prefer not to use the trait:

```php
class YourJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $supervisor_id;

    public function __construct()
    {
        $this->supervisor_id = gethostname();
    }

    public function tags(): array
    {
        return [
            'server:' . gethostname(),
            'environment:' . app()->environment(),
            'type:' . class_basename($this),
        ];
    }

    public function handle(): void
    {
        // Your logic
    }
}
```

---

## Securing the API

The API is **safe by default**:

- In `local` and `testing` environments — open. Zero-friction development.
- Anywhere else — denied with a 403 unless you register an auth callback.
- Throttled to 60 requests/minute per caller out of the box.

### Production: register an auth callback

In your `AppServiceProvider::boot()` (or any service provider):

```php
use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs;

public function boot(): void
{
    HorizonRunningJobs::auth(function ($request) {
        return $request->user()?->is_admin === true;
    });
}
```

The closure receives the incoming `Illuminate\Http\Request`. Return `true` to allow, `false` to deny. Works with whatever auth scheme you already have — Sanctum, Passport, sessions, custom.

If you forget to register the callback in production, the 403 response includes a copy-paste example showing exactly how to fix it.

### Layering with auth middleware (optional)

If you'd rather use a middleware-driven flow alongside the callback, just add it to the route config:

```php
// config/horizon-running-jobs.php
'routes' => [
    'middleware' => ['api', 'throttle:60,1', 'auth:sanctum'],
],
```

The bundled `Authorize` middleware runs *after* whatever you configure here, so you get defense-in-depth — middleware AND callback must both pass.

### Disable the routes entirely

If you'd rather wire your own controllers / Gate-based authorization:

```php
'routes' => [
    'enabled' => false,
],
```

### Query parameter validation

The `?queues=` parameter is validated:

- Names must match `[A-Za-z0-9_:.-]+`
- Maximum 20 names per request
- Invalid input → `422 Unprocessable Entity` with a clear `Invalid queue parameter` error

---

## Testing

### Unit tests

```bash
composer test
```

Runs the PHPUnit suite via Testbench. No Redis needed — unit tests cover pure logic (duration formatting, server-identifier extraction).

### End-to-end testing against a real Laravel app

A sibling demo app lives at [`../laravel-horizon-running-jobs-demo`](../laravel-horizon-running-jobs-demo). It's a fresh Laravel 13 install that consumes this package via Composer path symlink — edits in this repo are live in the demo with no reinstall.

```bash
cd ../laravel-horizon-running-jobs-demo
php artisan horizon                              # terminal 1
php artisan demo:dispatch-workload               # terminal 2
php artisan horizon:running-jobs --stats         # terminal 2
```

Dummy jobs cover: fast (500ms), medium (5-10s), slow (30-60s), flaky (50% failure), memory-heavy (200MB), and stuck (`sleep(9999)` — for orphan-detection testing).

See the demo's [README](../laravel-horizon-running-jobs-demo/README.md) for scenarios.

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

---

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

---

## Security

If you discover any security-related issues, please email ashiqfardus@hotmail.com instead of using the issue tracker.

---

## Credits

- [Ashiq Fardus](https://github.com/ashiqfardus)
- [All Contributors](../../contributors)

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

