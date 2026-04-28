# Laravel Horizon Running Jobs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![License](https://img.shields.io/packagist/l/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)

**See what's currently running in Laravel Horizon — across one server or many — and act on stuck jobs without leaving the dashboard.**

Horizon's own UI shows pending, completed, and failed jobs but treats "running" as a black box. This package fills that gap with a Blade dashboard, a CLI suite, and an HTTP API. It works on a single application or on any number of instances sharing a Redis. Reserved jobs whose worker died (orphans), reservations that expired without cleanup (zombies), and supervisors that have stopped heartbeating (stale) are all surfaced and recoverable.

---

## Table of contents

- [What you get](#what-you-get)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Concepts: running, zombie, orphan, stale](#concepts-running-zombie-orphan-stale)
- [Browser dashboard](#browser-dashboard)
- [CLI commands](#cli-commands)
- [HTTP API](#http-api)
- [Securing access](#securing-access)
- [Configuration reference](#configuration-reference)
- [Using the facade](#using-the-facade)
- [How it works internally](#how-it-works-internally)
- [Upgrading from v1.0](#upgrading-from-v10)
- [Testing](#testing)
- [Contributing](#contributing)

---

## What you get

| Surface | What it shows / does |
|---|---|
| **`/horizon/queue-monitor`** | Standalone Blade dashboard — health banner, supervisor table, queue depths, running jobs with orphan/zombie badges, inline release buttons. Auto-refreshes. |
| **`<x-horizon-running-jobs::*>`** | Five composable Blade components (`dashboard`, `diagnose-banner`, `supervisors-panel`, `queues-panel`, `running-jobs-table`) you can drop into your own admin pages. |
| **`horizon:running-jobs`** | List currently-running jobs. Filter by queue, server, orphan-only. `--watch` for live refresh. `--stats` for aggregates. |
| **`horizon:queues`** | Per-queue depth: pending / reserved / delayed / total. |
| **`horizon:supervisors`** | Every Horizon supervisor + master process across the deployment, with stale flagging. |
| **`horizon:diagnose`** | One-shot health check across supervisors, jobs, and queue depths. Exits non-zero on failure — drop straight into cron. |
| **`horizon:release`** | Recover stuck reserved jobs by ID, or all orphans / zombies. Atomic. Confirms before applying. |
| **`GET /api/horizon/*`** | JSON endpoints for everything above (auth-gated, throttled). |

---

## Requirements

| Component | Versions |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 |
| Laravel | 9.x, 10.x, 11.x, 12.x, 13.x |
| Horizon | 5.x, 6.x |
| Redis | 6.0+ |

Composer resolves the right Laravel version for your PHP automatically. Laravel 13 requires PHP 8.3+; Laravel 11/12 require PHP 8.2+.

---

## Installation

```bash
composer require ashiqfardus/horizon-running-jobs
```

Publish the config (optional — defaults are fine for most apps):

```bash
php artisan vendor:publish --tag=horizon-running-jobs-config
```

That's it. The dashboard at `/horizon/queue-monitor`, all CLI commands, and the HTTP API are wired up automatically. In a `local` or `testing` environment they're open; in any other environment they require an [auth callback](#securing-access) before responding.

### Optional publishables

```bash
# Fork the Blade views to customize markup / structure
php artisan vendor:publish --tag=horizon-running-jobs-views

# Publish the CSS to serve from your own public directory
php artisan vendor:publish --tag=horizon-running-jobs-css
```

---

## Setup

### Single server (default)

Nothing to configure. The package reads the Redis connection Horizon is using and surfaces everything reserved, regardless of which worker reserved it.

### Distributed (multiple instances sharing one Redis)

Set `distributed => true` in `config/horizon-running-jobs.php`. Each instance will only see jobs reserved by its own Horizon supervisor; pass `--all` (CLI) or `?all=true` (HTTP) to see everything across the cluster.

This applies to any topology where more than one Laravel instance points at the same Redis: multiple machines, containers / pods, even multiple instances on a single host. The discriminator is *shared Redis*, not *multiple servers*.

How the package identifies "this instance" depends on your `config/horizon.php`:

**Auto-detect (works out of the box if your supervisor key is `gethostname()`):**

```php
// config/horizon.php
'defaults' => [
    gethostname() => [
        'connection' => 'redis',
        'queue' => ['default'],
    ],
],
```

**Static names (containers, multi-tenant, anywhere `gethostname()` isn't unique):**

```php
// config/horizon.php
'defaults' => [
    'supervisor-01' => [...],
    'supervisor-02' => [...],
],
```

```php
// config/horizon-running-jobs.php
'server_identifier' => env('HORIZON_SUPERVISOR_NAME'),
```

```bash
# In each instance's .env
HORIZON_SUPERVISOR_NAME=supervisor-01
```

### Optional: `TracksServer` trait

If your jobs don't already tag themselves with `server:<hostname>` via Horizon's `tags()` method, add the trait so the package can match running jobs to the supervisor that reserved them:

```php
use Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TracksServer;

    public function __construct()
    {
        $this->initializeServerTracking();
    }
}
```

The trait is purely additive — it doesn't replace `tags()` if you already have one.

---

## Concepts: running, zombie, orphan, stale

Four states. Read these once and the dashboard makes immediate sense.

### `running` — normal

A job is in `queues:<q>:reserved` (Redis sorted set), its expiry score is in the future, and the supervisor that reserved it is alive and heartbeating. The worker is processing it.

### `zombie` — reservation expired

The reserved-set entry's score is in the past. The reservation timed out without the worker either completing the job or releasing it cleanly. Causes:

- The worker process died (OOM, SIGKILL) before finishing
- The job exceeded the queue's `retry_after` window
- Horizon hasn't reaped the entry yet

A zombie blocks its queue slot until something releases or removes it.

### `orphan` — worker is gone

The job's `server:<name>` tag refers to a supervisor that's not in Horizon's live supervisor set anymore. The worker that started this job is gone. The job is structurally stuck — no one will finish it.

A job can be both zombie *and* orphan (`⚠ orphan + zombie`).

### `stale` — supervisor

Different layer. A *supervisor* (worker process manager) entry exists in Redis but its heartbeat expiry is in the past beyond a small grace window. Could mean the supervisor process died, the master stopped pinging it, or there's general Redis lag. A stale supervisor *causes* orphans on jobs it reserved.

### Relationships

```
                      Healthy ──────────────────────────┐
                         │                              │
                         ▼                              ▼
   Worker dies ── creates ▶ zombie       Supervisor dies ── creates ▶ stale
                                                                        │
                                                            ▼
                                          Jobs reserved by it become ▶ orphan
```

Recovery: zombies and orphans are recovered by **releasing** them — moving them back to the pending list so a healthy worker can pick them up. Use the dashboard's inline release button or the CLI command `horizon:release`.

---

## Browser dashboard

### Standalone page

Visit `/horizon/queue-monitor` in your app. Auth is gated by the same callback as the JSON API ([see below](#securing-access)).

Layout:

- **Top banner** — overall health (PASS / WARN / FAIL) plus per-check findings as a stacked list
- **Supervisors panel** — every supervisor in Horizon's registry, with status, PID, queues, expiry
- **Queue depth panel** — pending / reserved / delayed counts per queue
- **Running jobs table** — orphan and zombie badges (with icon prefixes for color-blind a11y), inline `release` button on rows that need attention, and a "release all" bulk action when ≥1 orphan or zombie is present

Interactivity:

- **Click any row** in running jobs to drill down into the full job payload (class, UUID, queue, server, status, timing, attempts, timeout, tags)
- **Pause auto-refresh** per panel — every panel header has a ⏸/▶ toggle so you can read a row without it disappearing on the next poll
- **Custom confirm modal** for release actions (showing job summary) — no browser-native `confirm()` dialog
- **Toast feedback** on every release with success / failure count

Polling: each panel polls a per-component endpoint on its own interval (banner 5s, supervisors 5s, queues 5s, jobs 3s). No page reload needed; tables re-render in place with a brief fade. Honors `prefers-reduced-motion`.

Disable the route entirely:

```php
// config/horizon-running-jobs.php
'ui' => [
    'enabled' => false,
],
```

### Embedding panels in your own dashboard

Each panel is an anonymous Blade component:

```blade
{{-- Full dashboard --}}
<x-horizon-running-jobs::dashboard />

{{-- Or compose individually --}}
<x-horizon-running-jobs::diagnose-banner />
<x-horizon-running-jobs::supervisors-panel />
<x-horizon-running-jobs::queues-panel />
<x-horizon-running-jobs::running-jobs-table :poll="3000" :allow-release="true" />

{{-- Filtered to orphans only --}}
<x-horizon-running-jobs::running-jobs-table :orphaned-only="true" />
```

Component props:

| Component | Props |
|---|---|
| `dashboard` | `:poll` (default 5000), `:jobs-poll` (default 3000) |
| `diagnose-banner` | `:poll` (default 5000) |
| `supervisors-panel` | `:poll` (default 5000) |
| `queues-panel` | `:poll` (default 5000) |
| `running-jobs-table` | `:poll` (default 3000), `:allow-release` (default true), `:orphaned-only` (default false) |

Pass `:poll="0"` to disable a panel's auto-refresh.

For panels embedded in *your* page (not the standalone dashboard), the host page must also load:

- The package CSS, served at `/horizon/queue-monitor/assets/css`, or published via `vendor:publish --tag=horizon-running-jobs-css`
- Alpine.js (Laravel's default for Blade interactivity)
- A `<meta name="csrf-token" content="{{ csrf_token() }}">` tag if `:allow-release` is enabled — the release POST is CSRF-protected

The factory functions Alpine needs (`hrjPanel`, `hrjReleaseButton`) are inlined in the standalone dashboard. If you're embedding components in your own page and want auto-refresh + release to work, copy the inline `<script>` block from `vendor/ashiqfardus/horizon-running-jobs/resources/views/dashboard.blade.php` into your layout, or include the published JS.

### Theming

The package CSS is fully scoped under `.hrj` — it cannot leak into your styles. All colors are CSS variables — override any of them in your own stylesheet to retheme:

```css
.hrj {
    --hrj-color-pass:   #00b8a9;
    --hrj-color-warn:   #ffae00;
    --hrj-color-fail:   #ff5b5b;
    --hrj-color-orphan: #ff7a45;
    --hrj-color-zombie: #b388ff;
    --hrj-bg:           #ffffff;
    --hrj-text:         #1a1a1a;
    --hrj-border:       #e5e5e5;
    /* ... see resources/css/horizon-running-jobs.css for the full list */
}
```

Dark mode is auto-detected via `prefers-color-scheme`. Force light by adding `class="hrj hrj--light"` on the wrapper.

---

## CLI commands

Every command supports `--json` for scripting and a `-h` help flag.

### `horizon:running-jobs`

List jobs currently in the reserved set.

```bash
# Default — current server's jobs (or all jobs in non-distributed mode)
php artisan horizon:running-jobs

# All servers across the cluster
php artisan horizon:running-jobs --all

# Specific queues
php artisan horizon:running-jobs --queue=emails --queue=reports

# Limit display
php artisan horizon:running-jobs --limit=50

# Only orphans (worker process is gone)
php artisan horizon:running-jobs --orphaned

# Live-refresh (Ctrl-C to exit)
php artisan horizon:running-jobs --watch
php artisan horizon:running-jobs --watch=5    # custom interval (seconds)

# Aggregate stats
php artisan horizon:running-jobs --stats

# JSON for scripting
php artisan horizon:running-jobs --json
```

Sample output:

```
🔍 Scanning queues: default, emails, reports
📍 Current server: app-server-01

+----------+--------------------+----------+----------------+----------+----------+----------+----------+
| ID       | Job                | Queue    | Server         | Status   | Started  | Duration | Attempts |
+----------+--------------------+----------+----------------+----------+----------+----------+----------+
| 4b5ecc82 | App\Jobs\Process…  | default  | app-server-01  | running  | 14:30:15 | 2m 34s   | 1        |
| 8a2b3c4d | App\Jobs\StuckJob  | reports  | app-server-01  | ⚠ orphan | 14:31:42 | 12m 08s  | 1        |
+----------+--------------------+----------+----------------+----------+----------+----------+----------+
✓ Found 2 running job(s)
⚠️  1 orphan job(s) detected (worker process is no longer registered)
```

### `horizon:queues`

Per-queue depth:

```bash
php artisan horizon:queues
php artisan horizon:queues --queue=emails --queue=reports
php artisan horizon:queues --json
php artisan horizon:queues --watch
```

```
+---------+---------+----------+---------+-------+
| Queue   | Pending | Reserved | Delayed | Total |
+---------+---------+----------+---------+-------+
| default | 12      | 3        | 0       | 15    |
| emails  | 4       | 1        | 2       | 7     |
| reports | 0       | 0        | 0       | 0     |
+---------+---------+----------+---------+-------+
| TOTAL   | 16      | 4        | 2       | 22    |
+---------+---------+----------+---------+-------+
```

Columns:

- **Pending** — jobs in `queues:<name>` (Redis list), waiting to be picked up
- **Reserved** — jobs in `queues:<name>:reserved` (sorted set), currently being processed (or stuck)
- **Delayed** — jobs in `queues:<name>:delayed` (sorted set), scheduled to fire later
- **Total** — sum of the three

### `horizon:supervisors`

Every supervisor and master process Horizon has registered in Redis, across the whole deployment:

```bash
php artisan horizon:supervisors
php artisan horizon:supervisors --masters    # include master table
php artisan horizon:supervisors --json
php artisan horizon:supervisors --watch
```

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

The `Expires` column counts down between Horizon's heartbeats. A supervisor flagged `⚠ stale` has been silent for longer than the grace window (default 5s, see [config](#configuration-reference)), suggesting the master process or the supervisor itself has died.

### `horizon:diagnose`

Unified health check. Exits 0 on pass-or-warn, exits non-zero on hard failure (e.g. no live supervisor at all). Drop straight into cron:

```bash
php artisan horizon:diagnose
php artisan horizon:diagnose --json
```

```
🔍 Horizon Health Diagnosis

  ✓  horizon.supervisors    2 supervisor(s) running
  ⚠  jobs.orphaned          1 orphan job(s) — see `horizon:running-jobs --orphaned`
  ✓  jobs.zombies           0 zombie jobs
  ✓  jobs.malformed         0 malformed entries
  ✓  queues.depths          highest pending: emails (47), totals: pending=58 reserved=4 delayed=2

Status: WARN
```

Checks:

| Name | Pass | Warn | Fail |
|---|---|---|---|
| `horizon.supervisors` | At least 1 live, none stale | Some stale, OR all stale (Horizon master may have died) | ZSET empty (Horizon never started or all entries reaped) |
| `jobs.orphaned` | 0 orphans | ≥1 orphan | — |
| `jobs.zombies` | 0 zombies | ≥1 zombie | — |
| `jobs.malformed` | 0 dropped | ≥1 dropped (see logs) | — |
| `queues.depths` | always pass (informational) | — | — |

### `horizon:release`

Move stuck reserved jobs back to the pending list. The only mutating command in the suite. Atomic per-job (ZREM from reserved + LPUSH to pending in one Redis transaction).

```bash
# Release a single job by UUID
php artisan horizon:release abc-123-def-456

# Release every orphaned reservation
php artisan horizon:release --orphaned

# Release every zombie (expired) reservation, scoped to a specific queue
php artisan horizon:release --zombie --queue=reports

# Preview without modifying Redis
php artisan horizon:release --orphaned --dry-run

# Skip the confirmation prompt (for cron / scripts)
php artisan horizon:release --orphaned --force
```

Behavior:

- Released jobs go to the **front** of the pending list (LPUSH) so a worker picks them up promptly
- Each release is logged via `Log::info` with the job UUID, queue, and reason — audit trail for ops
- `--orphaned`, `--zombie`, and a positional UUID are mutually exclusive — pick one targeting mode
- The interactive confirm shows a full table of jobs that will be released before applying
- `--queue=` repeats to scope to specific queues

### `--watch` mode

The list-style commands (`horizon:running-jobs`, `horizon:queues`, `horizon:supervisors`) accept a `--watch[=seconds]` flag that re-renders on a loop, like `top`:

```bash
php artisan horizon:running-jobs --watch       # 3s default
php artisan horizon:queues --watch=10          # 10s interval
```

Press `Ctrl-C` to exit. Ignored when combined with `--json`.

### `--json` mode

Every command emits machine-readable JSON with `--json`:

```bash
php artisan horizon:queues --json | jq '.totals.pending'
php artisan horizon:diagnose --json | jq '.overall_status'
```

`horizon:diagnose --json` is particularly useful for monitoring/alerting:

```bash
if [ "$(php artisan horizon:diagnose --json | jq -r .overall_status)" = "fail" ]; then
    page-oncall "Horizon is down"
fi
```

---

## HTTP API

Auth-gated by the same callback as the dashboard. Throttled to 60 requests/minute per caller by default.

### `GET /api/horizon/running-jobs`

```bash
GET /api/horizon/running-jobs
GET /api/horizon/running-jobs?all=true
GET /api/horizon/running-jobs?queues=emails,reports
GET /api/horizon/running-jobs?orphaned=true
```

Sample response:

```json
{
  "success": true,
  "hostname": "app-server-01",
  "timestamp": "2026-04-25T10:30:00+00:00",
  "queues_monitored": ["default", "emails", "reports"],
  "running_jobs_count": 2,
  "total_count": 2,
  "dropped_count": 0,
  "orphan_count": 1,
  "orphaned_only": false,
  "jobs": [
    {
      "job_id": "4b5ecc82-07a7-40db-97db-bfab5ac5c500",
      "job_class": "App\\Jobs\\ProcessOrder",
      "queue": "default",
      "server": "app-server-01",
      "status": "running",
      "is_orphaned": false,
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

Response field reference:

| Field | Meaning |
|---|---|
| `running_jobs_count` | jobs returned in this payload (may be limited by `max_jobs`) |
| `total_count` | total reserved-set entries found before truncation |
| `dropped_count` | malformed reserved-set entries skipped; each is logged via `Log::warning` |
| `orphan_count` | jobs whose tagged supervisor is no longer in Horizon's live set |
| `orphaned_only` | echoes whether `?orphaned=true` was active for this request |
| `jobs[].status` | `"running"` (reservation valid) or `"zombie"` (reservation expired) |
| `jobs[].is_orphaned` | `true` when the worker that reserved the job is no longer registered |
| `jobs[].start_time` / `start_timestamp` | actual reservation time (not the Redis expiry score) |
| `warnings[]` | human-readable summary lines — long-running, zombie count, orphan count, dropped count |

### `GET /api/horizon/running-jobs/stats`

Aggregate stats:

```json
{
  "success": true,
  "timestamp": "2026-04-25T10:30:00+00:00",
  "stats": {
    "total_running": 5,
    "by_server": {"app-01": 3, "app-02": 2},
    "by_queue": {"default": 4, "reports": 1},
    "by_job_class": {"App\\Jobs\\ProcessOrder": 5},
    "by_status": {"running": 4, "zombie": 1},
    "by_orphan_status": {"healthy": 4, "orphaned": 1},
    "dropped_count": 0,
    "orphan_count": 1,
    "longest_running": { /* job object */ },
    "warnings": []
  }
}
```

### `GET /api/horizon/queues`

```bash
GET /api/horizon/queues
GET /api/horizon/queues?queues=emails,reports
```

```json
{
  "success": true,
  "inspected_at": 1745576846,
  "queue_count": 3,
  "totals": {"pending": 16, "reserved": 4, "delayed": 2, "total": 22},
  "queues": [
    {"queue": "default", "pending": 12, "reserved": 3, "delayed": 0, "total": 15},
    {"queue": "emails",  "pending": 4,  "reserved": 1, "delayed": 2, "total": 7},
    {"queue": "reports", "pending": 0,  "reserved": 0, "delayed": 0, "total": 0}
  ]
}
```

### `GET /api/horizon/supervisors`

```json
{
  "success": true,
  "inspected_at": 1745576846,
  "supervisor_count": 2,
  "master_count": 1,
  "stale_supervisor_count": 0,
  "supervisors": [
    {
      "name": "supervisor-01:app-01.example.com",
      "status": "running",
      "master": "supervisor-01",
      "pid": 8298,
      "queues": ["default", "emails", "reports"],
      "process_count": 3,
      "processes": {"redis:default": 1, "redis:emails": 1, "redis:reports": 1},
      "expires_at": 1745576906,
      "seconds_until_expiry": 60,
      "is_stale": false
    }
  ],
  "masters": [
    {
      "name": "supervisor-01",
      "status": "running",
      "environment": "production",
      "pid": 8283,
      "supervisor_count": 1,
      "expires_at": 1745576901,
      "seconds_until_expiry": 55,
      "is_stale": false
    }
  ]
}
```

### Validation

Endpoints accepting `?queues=` enforce:

- Each name matches `[A-Za-z0-9_:.-]+`
- At most 20 names per request
- Invalid input → `422 Unprocessable Entity`

---

## Securing access

The package is **safe by default**:

- In `local` and `testing` environments — open. Zero-friction development.
- Anywhere else — denied with a 403 unless you register an auth callback.
- Throttled to 60 requests/minute per caller out of the box.

### Production: register an auth callback

In your `AppServiceProvider::boot()`:

```php
use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs;

public function boot(): void
{
    HorizonRunningJobs::auth(function ($request) {
        return $request->user()?->is_admin === true;
    });
}
```

The closure receives the incoming `Illuminate\Http\Request`. Return `true` to allow, `false` to deny. Works with whatever auth scheme you have — Sanctum, Passport, sessions, custom.

If you forget to register the callback in production, the 403 response includes a copy-paste example showing exactly how to fix it.

### Layering with auth middleware (optional)

Add additional middleware to defend in depth:

```php
// config/horizon-running-jobs.php
'routes' => [
    'middleware' => ['api', 'throttle:60,1', 'auth:sanctum'],
],
```

The bundled `Authorize` middleware runs *after* whatever you configure here, so you get both — middleware AND callback must pass.

### Disable the routes entirely

If you'd rather wire your own controllers / Gate-based authorization:

```php
'routes' => [
    'enabled' => false,
],
'ui' => [
    'enabled' => false,
],
```

---

## Configuration reference

Every config key, with default and meaning. From `config/horizon-running-jobs.php`:

| Key | Default | Meaning |
|---|---|---|
| `distributed` | `false` | Enable when more than one Laravel instance shares one Redis. Each instance only sees its own jobs unless `--all` / `?all=true` is passed. |
| `server_identifier` | `null` | How this instance identifies itself in distributed mode. `null` = auto-detect from `gethostname()`. Override for static names, containers, etc. |
| `queues` | `null` | Default queues to monitor. `null` = auto-detect from `config('horizon.defaults.*.queue')`. Pass `['default', 'emails']` to pin. |
| `max_jobs` | `1000` | Hard cap on jobs returned in a single query. Prevents memory blowups on very deep reserved sets. |
| `long_running_threshold` | `300` | Seconds before a job's row is flagged as "long-running" in warnings. |
| `cache.enabled` | `true` | Cache HTTP API responses for `cache.ttl` seconds. |
| `cache.ttl` | `10` | Cache duration in seconds. |
| `cache.prefix` | `'horizon_running_jobs'` | Cache key prefix. |
| `routes.enabled` | `true` | Whether the JSON API routes are registered. |
| `routes.prefix` | `'api'` | URL prefix for API routes. |
| `routes.middleware` | `['api', 'throttle:60,1']` | Middleware stack for API routes. The `Authorize` middleware is appended unconditionally. |
| `routes.uri` | `'horizon/running-jobs'` | Path segment for the running-jobs endpoint. |
| `ui.enabled` | `true` | Whether the Blade dashboard route is registered. |
| `ui.prefix` | `'horizon/queue-monitor'` | URL prefix for the dashboard. |
| `ui.middleware` | `['web']` | Middleware stack for the dashboard. The `Authorize` middleware is appended unconditionally. `web` is required for sessions + CSRF on the release POST. |
| `redis_connection` | `null` | Redis connection name. `null` = auto-detect from `config('horizon.use')`. |
| `retry_after` | `null` | Override Horizon's `retry_after` window for duration math. `null` = auto-detect from `config('queue.connections.<horizon.use>.retry_after')`, falling back to 90. |
| `supervisor_stale_grace_seconds` | `5` | Grace window before flagging a supervisor stale. Absorbs heartbeat jitter. Lower = more responsive but flappier; higher = stabler but slower outage detection. |

---

## Using the facade

```php
use Ashiqfardus\HorizonRunningJobs\Facades\RunningJobs;

// Current server only
$result = RunningJobs::getRunningJobs();

// All servers
$result = RunningJobs::getRunningJobs(null, true);

// Specific queues
$result = RunningJobs::getRunningJobs(null, false, ['emails', 'reports']);

// Filter to orphans only
$result = RunningJobs::getRunningJobs(null, true, null, $orphanedOnly = true);

// Aggregate stats
$stats = RunningJobs::getStats();
```

For releasing jobs programmatically:

```php
use Ashiqfardus\HorizonRunningJobs\JobReleaser;

$releaser = app(JobReleaser::class);

// Find what's releasable (read-only)
$found = $releaser->findReleasable(['orphaned' => true, 'queues' => ['reports']]);

// Release them (atomic per-job)
$count = $releaser->release($found);
```

---

## How it works internally

Laravel's Redis queue stores jobs in three keys per queue:

| Key | Type | Contains |
|---|---|---|
| `queues:{q}` | LIST | Pending jobs (workers `LPOP` from here) |
| `queues:{q}:reserved` | ZSET | Currently-reserved jobs (score = expiry timestamp) |
| `queues:{q}:delayed` | ZSET | Scheduled / delayed jobs (score = release timestamp) |

This package reads all three. For supervisors / health, it also reads Horizon's own keys on the `horizon` Redis connection:

| Key | Type | Contains |
|---|---|---|
| `supervisors` | ZSET | Live supervisor names (score = expiry) |
| `masters` | ZSET | Live master process names (score = expiry) |
| `supervisor:{name}` | HASH | Per-supervisor metadata (pid, queues, process counts) |
| `master:{name}` | HASH | Per-master metadata |

### Identifying which job belongs to which server

Two paths, in order:

1. **Tags** — Horizon stores tags as part of the job payload. The package looks for `server:<name>` and matches against the supervisor name.
2. **`supervisor_id` property** — fallback if no tag is set. The package extracts via regex (no `unserialize`).

This is why the `TracksServer` trait is a quality-of-life affordance — it adds the tag automatically. If you have your own `tags()` returning `server:gethostname()`, the package picks that up too.

### Distributed mode

```
                    ┌─────────────────┐
                    │  Redis Server   │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
         ┌────▼────┐    ┌────▼────┐   ┌────▼────┐
         │ App A   │    │ App B   │   │ App C   │
         │ 5 jobs  │    │ 3 jobs  │   │ 7 jobs  │
         └─────────┘    └─────────┘   └─────────┘
```

Each instance can see its own jobs (default) or all jobs in the cluster (with `--all` / `?all=true`).

### Cache invalidation

API responses cache for `cache.ttl` seconds (default 10) to prevent hammering Redis. Cache keys embed an epoch counter; calling `RunningJobs::clearCache()` increments the epoch, invalidating every previously-cached response without needing wildcard deletes.

---

## Upgrading from v1.0

v2.0 introduces several breaking changes plus a substantial feature set. Before upgrading:

### Breaking changes

1. **Production endpoints now deny by default.** v1.0 left the routes wide open in any environment. v2.0 returns `403` from `local`/`testing`-other environments unless you register an auth callback. The 403 body includes a copy-paste example. See [Securing access](#securing-access).

2. **`RunningJobsManager::parseJobData()` throws `RuntimeException`** on malformed payloads (was: returned `null`). Calling code that relied on the null return needs to wrap in try/catch or call from inside the manager which already handles it.

3. **`response().jobs[*].start_time` / `start_timestamp` reflect the actual reservation time** rather than the Redis expiry score. Charts that built duration math on the v1 values may need adjustment.

4. **Default route middleware adds `throttle:60,1`.** Callers exceeding 60 requests/minute now receive 429.

5. **PHP 8.0 dropped.** Minimum is PHP 8.1.

### Additive (no action needed)

- New CLI commands: `horizon:queues`, `horizon:supervisors`, `horizon:diagnose`, `horizon:release`
- New HTTP endpoints: `/api/horizon/queues`, `/api/horizon/supervisors`
- New Blade dashboard at `/horizon/queue-monitor` + composable components
- New job fields: `is_orphaned`, `status` (`"running"` | `"zombie"`)
- New response fields: `orphan_count`, `dropped_count`, `orphaned_only`
- `--watch` flag on list-style commands

### Deprecated (still works, will be removed in v3.0)

- The standalone JS widget (`vendor:publish --tag=horizon-running-jobs-assets`) and the bundled Vue component. Both only show running jobs and lack any of the v2 features. Migrate to the Blade dashboard.

---

## Testing

```bash
composer test
```

Runs the full PHPUnit suite. No Redis required for unit + feature tests; integration tests skip themselves when Redis isn't reachable on `127.0.0.1:6379`.

For end-to-end testing against a real Laravel app, the [demo at `../laravel-horizon-running-jobs-demo`](../laravel-horizon-running-jobs-demo) is a fresh Laravel 13 install consuming this package via Composer path symlink.

```bash
cd ../laravel-horizon-running-jobs-demo
php artisan horizon                           # terminal 1
php artisan demo:dispatch-workload            # terminal 2 — varied jobs across queues
php artisan demo:simulate-orphan --count=2    # terminal 2 — flip to broken state
php artisan horizon:running-jobs --orphaned   # observe the orphans
php artisan horizon:release --orphaned        # release them back to pending
```

The demo also serves the Blade dashboard at `/horizon/queue-monitor`.

---

## Contributing

PRs welcome. Run `composer test` before submitting. Integration tests require Redis on `127.0.0.1:6379`.

## Security

Found a security issue? Email **ashiqfardus@hotmail.com** instead of using the public issue tracker.

## Credits

- [Ashiq Fardus](https://github.com/ashiqfardus)
- [All contributors](../../contributors)

## License

MIT — see [LICENSE.md](LICENSE.md).
