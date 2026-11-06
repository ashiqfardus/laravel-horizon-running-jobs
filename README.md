# Laravel Horizon Running Jobs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![License](https://img.shields.io/packagist/l/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/horizon-running-jobs.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/horizon-running-jobs)
[![Laravel Version](https://img.shields.io/badge/Laravel-9%20|%2010%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

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
| **`horizon:release`** | Recover stuck reserved jobs by ID, or all orphans / zombies that have run past their timeout. Atomic. Confirms before applying. |
| **`GET /api/horizon/*`** | JSON endpoints for everything above (auth-gated, throttled). |

---

## Requirements

| Component | Versions |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 |
| Laravel | 9.x, 10.x, 11.x, 12.x, 13.x |
| Horizon | 5.x |
| Redis | 6.0+, with the phpredis extension or predis |

Composer resolves the right Laravel version for your PHP automatically. Laravel 13 requires PHP 8.3+; Laravel 11/12 require PHP 8.2+.

The test suite runs against the oldest and newest of each: PHP 8.1 with Laravel 9.0 and Horizon 5.8, up to PHP 8.5 with Laravel 13 and Horizon 5.50, on both Redis clients. Horizon 6 has not been released yet.

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

Set `distributed => true` in `config/horizon-running-jobs.php`. Each instance will only see the jobs its own Horizon workers are running (see [below](#how-a-job-is-matched-to-its-server)); pass `--all` (CLI) or `?all=true` (HTTP) to see everything across the cluster.

This applies to any topology where more than one Laravel instance points at the same Redis: multiple machines, containers / pods, even multiple instances on a single host. The discriminator is *shared Redis*, not *multiple servers*.

How the package identifies "this instance" depends on your `config/horizon.php`:

**Auto-detect (works when this environment's first supervisor key is `gethostname()`):**

```php
// config/horizon.php
'environments' => [
    'production' => [
        gethostname() => [
            'connection' => 'redis',
            'queue' => ['default'],
        ],
    ],
],
```

The identifier is the first supervisor key under `environments.<APP_ENV>`. `defaults` is only consulted when that block is empty, and `gethostname()` itself only when there are no supervisors at all. Horizon's stock config defines `supervisor-1` under each environment, which resolves to the same identifier on every instance.

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

### How a job is matched to its server

When a Horizon worker starts a job, the package records which supervisor runs it and when it started: one short Redis key per running job, removed when the job finishes or fails (see [`track_workers`](#configuration-reference)). The Server column, distributed filtering and orphan detection all use that record, so they are right however your jobs are dispatched: from a web node, a scheduler, another app, or the worker itself. Orphan detection compares the exact supervisor name, so hosts that share a supervisor key (Horizon's stock `supervisor-1`) cannot vouch for each other.

Workers start writing these records once they run this version, after `php artisan horizon:terminate`. Jobs without a record (started before that, or with `track_workers` off) fall back to a `server:<identifier>` tag on the job.

### Optional: tag your jobs with their server (`TracksServer` trait)

The tag is the fallback for jobs without a worker record. Horizon computes tags when a job is dispatched, so the tag names **the instance that dispatched the job**. That is only the right server when the dispatching instance also runs the job, or when all instances share one identifier. Without a record and without a tag, the Server column shows `unknown` and the job is never reported as orphaned.

To add the tag, use the trait:

```php
use Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TracksServer;
}
```

The trait's `tags()` returns `server:<identifier>`, `environment:<env>` and `type:<class>`. Once a job returns any tags of its own, Horizon no longer adds its automatic model tags (`App\Models\Order:42`); add those to your own `tags()` if you search by them.

**Jobs that define their own `tags()`.** A method on the class replaces the trait's method, so the server tag is not added for you. Merge `serverTags()` into your list:

```php
public function tags(): array
{
    return ['order:' . $this->order->id, ...$this->serverTags()];
}
```

**Second fallback.** Calling `$this->initializeServerTracking()` in the constructor also stores the identifier on the job as a `supervisor_id` property, read when a job has neither a worker record nor a server tag.

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

A zombie blocks its queue slot until something releases or removes it. With `retry_after => null` on the queue connection, reservations never expire, so no job is ever a zombie.

### `orphan` — worker is gone

The supervisor running the job (from its [worker record](#how-a-job-is-matched-to-its-server), else its `server:` tag) is neither heartbeating nor managed by a live Horizon master any more. Usually the worker that was running the job is gone and no one will finish it.

Not always, though. During `horizon:terminate` (every deploy), supervisors deregister first and their workers then finish the job in hand, so those jobs show as orphans until they complete. That is why an orphan is only offered for release once it has run past its timeout (see [Recovery](#relationships)).

A job can be both zombie *and* orphan (`⚠ orphan + zombie`).

### `stale` — supervisor

Different layer. A *supervisor* (worker process manager) entry exists in Redis but its last heartbeat is older than Horizon's own 29-second alive window plus a small grace window (14 seconds plus grace for a master). A running Horizon master removes entries after 14 seconds of silence, so a stale row means no master is running on this Redis any more: Horizon was killed, the host died, or it lost its connection to Redis. A stale supervisor *causes* orphans on jobs it reserved.

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

Releasing a job a worker is still running makes it run twice, so release is only offered once the job has **run past its timeout** plus a margin (`release_margin_seconds`, default 10). A Laravel worker stops a job when it hits its timeout, so by then a healthy worker is done with it. On top of that:

- an **orphan** must have a [worker record](#how-a-job-is-matched-to-its-server), so its start time, timeout and supervisor are known rather than inferred;
- an orphan **without** a record is released only once its reservation has also expired, which is when Laravel would re-queue it anyway;
- a **zombie** (expired reservation) is released once past its timeout.

The timeout is the one the worker enforces: the job's own `$timeout`, else the `--timeout` its Horizon supervisor started it with (Horizon's default is 60). Until release is possible the dashboard shows when it will be. A job with no timeout (`0`) is never released in bulk; release it by UUID if you are sure it is stuck.

The timeout is enforced with a signal, which a job blocked inside some C extension calls (a socket read with no timeout of its own) only handles once the call returns. Give such calls their own timeouts.

---

## Browser dashboard

### Standalone page

Visit `/horizon/queue-monitor` in your app. Auth is gated by the same callback as the JSON API ([see below](#securing-access)).

Layout:

- **Top banner** — overall health (PASS / WARN / FAIL) plus per-check findings as a stacked list
- **Supervisors panel** — every supervisor in Horizon's registry, with status, PID, queues, time since last heartbeat
- **Queue depth panel** — pending / reserved / delayed counts per queue
- **Running jobs table** — orphan and zombie badges (with icon prefixes for color-blind a11y), an inline `release` button on orphan / zombie rows that have run past their timeout (the others show when release becomes available), and a "release N stuck" bulk action covering exactly those rows

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
+---------------------------+---------+------+------------------------+-------+-----------+
| Name                      | Status  | PID  | Queues                 | Procs | Heartbeat |
+---------------------------+---------+------+------------------------+-------+-----------+
| app-01-x7Kp:supervisor-01 | running | 8298 | default,emails,reports | 3     | 0s ago    |
| app-02-Qm3d:supervisor-01 | running | 4521 | default,emails,reports | 3     | 1s ago    |
+---------------------------+---------+------+------------------------+-------+-----------+
```

Names are Horizon's own: `<master name>:<supervisor key>`. The `Heartbeat` column is the time since the supervisor last reported to Redis, normally a second or two. A row is flagged `⚠ stale` once it has been silent for more than 29 seconds plus the grace window (default 5s, see [config](#configuration-reference)). A running Horizon master removes silent entries after 14 seconds, so you only see stale rows when no master is running on this Redis any more; the command then warns that supervisors stopped heartbeating.

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

Move stuck reserved jobs back to the pending list. The only mutating command in the suite. Atomic per job: one Redis script removes the job from the reserved set and pushes it to pending only if it was still reserved, so a job that finished in the meantime is not run again.

```bash
# Release a single job by UUID
php artisan horizon:release abc-123-def-456

# Release every orphaned job that has run past its timeout
php artisan horizon:release --orphaned

# Release every zombie (expired) job past its timeout, scoped to a specific queue
php artisan horizon:release --zombie --queue=reports

# Both at once
php artisan horizon:release --orphaned --zombie

# Preview without modifying Redis
php artisan horizon:release --orphaned --dry-run

# Skip the confirmation prompt (for cron / scripts)
php artisan horizon:release --orphaned --force
```

Behavior:

- Released jobs go to the **front** of the pending list (LPUSH) so a worker picks them up promptly
- Each release is logged via `Log::info` with the job UUID, queue, and reason — audit trail for ops
- `--orphaned` and `--zombie` only pick jobs that have run past their timeout (see [Recovery](#relationships)); they can be combined
- A positional UUID releases that job whatever its state, and warns when a worker may still be running it. It cannot be combined with `--orphaned` / `--zombie`
- `--orphaned` stops with an error, releasing nothing, if the live supervisors cannot be read from Redis
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
GET /api/horizon/running-jobs?server_identifier=supervisor-02   # another instance's jobs (distributed mode)
```

Sample response:

```json
{
  "success": true,
  "server_identifier": "app-server-01",
  "hostname": "app-server-01",
  "timestamp": "2026-04-25T10:30:00+00:00",
  "queues_monitored": ["default", "emails", "reports"],
  "running_jobs_count": 1,
  "total_count": 1,
  "dropped_count": 0,
  "orphan_count": 0,
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
      "start_timestamp": 1777112846,
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
| `server_identifier` | the identifier the request was scoped to: the `?server_identifier=` value if given (`?hostname=` is the older name and still works), otherwise this instance's [server identifier](#configuration-reference). With Horizon's stock config that is the supervisor key (`supervisor-1`), not the machine's host name |
| `hostname` | deprecated alias of `server_identifier`, kept for existing clients |
| `running_jobs_count` | jobs returned in this payload (may be limited by `max_jobs`) |
| `total_count` | total reserved-set entries found before truncation |
| `dropped_count` | malformed reserved-set entries skipped; each is logged via `Log::warning` |
| `orphan_count` | jobs whose supervisor is no longer alive |
| `orphaned_only` | echoes whether `?orphaned=true` was active for this request |
| `jobs[].status` | `"running"` (reservation valid) or `"zombie"` (reservation expired) |
| `jobs[].server` | the server identifier (supervisor key) running the job, from its worker record, else its `server:` tag; `unknown` when neither exists |
| `jobs[].supervisor` | the exact Horizon supervisor running the job (`<master>:<key>`), or `null` without a worker record |
| `jobs[].is_orphaned` | `true` when that supervisor is no longer alive |
| `jobs[].is_releasable` | `true` when the job is offered for release: an orphan with a worker record, or an expired job, that has run past its timeout plus `release_margin_seconds` (see [Recovery](#relationships)) |
| `jobs[].releasable_at` | Unix time from which the job can be released; `null` when it never can (no timeout, or no worker record and reservations never expire) |
| `jobs[].start_time` / `start_timestamp` | when the job started: from its worker record, else its reservation time (not the Redis expiry score) |
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
  "inspected_at": 1777112846,
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
  "inspected_at": 1777112846,
  "supervisor_count": 2,
  "master_count": 1,
  "stale_supervisor_count": 0,
  "supervisors": [
    {
      "name": "app-01-x7Kp:supervisor-01",
      "status": "running",
      "master": "app-01-x7Kp",
      "pid": 8298,
      "queues": ["default", "emails", "reports"],
      "process_count": 3,
      "processes": {"redis:default": 1, "redis:emails": 1, "redis:reports": 1},
      "last_heartbeat_at": 1777112845,
      "seconds_since_heartbeat": 1,
      "expires_at": 1777112874,
      "seconds_until_expiry": 28,
      "is_stale": false
    }
  ],
  "masters": [
    {
      "name": "app-01-x7Kp",
      "status": "running",
      "environment": "production",
      "pid": 8283,
      "supervisor_count": 1,
      "last_heartbeat_at": 1777112846,
      "seconds_since_heartbeat": 0,
      "expires_at": 1777112860,
      "seconds_until_expiry": 14,
      "is_stale": false
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `last_heartbeat_at` / `seconds_since_heartbeat` | when the process last reported to Redis |
| `expires_at` / `seconds_until_expiry` | when Horizon itself stops treating the process as alive: last heartbeat + 29s for a supervisor, + 14s for a master |
| `is_stale` | `true` once the process has been silent past `expires_at` plus `supervisor_stale_grace_seconds` |

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

`$request->user()` is only populated when the route group runs middleware that authenticates the request. The dashboard's default `web` group does that for session logins. The JSON API's default `['api', 'throttle:60,1']` does not, so a callback that relies on `$request->user()` returns 403 there until you add your guard (for example `auth:sanctum`, see [Layering](#layering-with-auth-middleware-optional)).

If you forget to register the callback in production, the 403 response includes a copy-paste example showing exactly how to fix it.

### Apps that authenticate with tokens (JWT, Sanctum personal access tokens)

`$request->user()` is only set when the request carries your app's credentials. A browser opening `/horizon/queue-monitor` sends no bearer token, so in a token-only app the callback above sees no user and the dashboard returns 403 for everyone.

One pattern that works is HTTP Basic auth in front of the package routes, with the callback accepting only requests that middleware has passed:

```php
// config/services.php
'queue_monitor' => [
    'user' => env('QUEUE_MONITOR_USER'),
    'password' => env('QUEUE_MONITOR_PASSWORD'),
],
```

```php
// app/Http/Middleware/QueueMonitorBasicAuth.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class QueueMonitorBasicAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = (string) config('services.queue_monitor.user');
        $password = (string) config('services.queue_monitor.password');
        $attempts = 'queue-monitor-auth:' . $request->ip();

        if (RateLimiter::tooManyAttempts($attempts, 5)) {
            return response('Too many attempts', 429);
        }

        if ($password === ''
            || ! hash_equals($user, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            // Count wrong credentials only, not the browser's first
            // credential-less request.
            if ($request->getUser() !== null) {
                RateLimiter::hit($attempts, 60);
            }

            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Queue monitor"']);
        }

        $request->attributes->set('queue_monitor_authenticated', true);

        return $next($request);
    }
}
```

```php
// config/horizon-running-jobs.php
'ui' => [
    'middleware' => ['web', \App\Http\Middleware\QueueMonitorBasicAuth::class],
],
'routes' => [
    'middleware' => ['api', 'throttle:60,1', \App\Http\Middleware\QueueMonitorBasicAuth::class],
],
```

```php
// AppServiceProvider::boot()
HorizonRunningJobs::auth(
    fn ($request) => $request->attributes->get('queue_monitor_authenticated') === true
);
```

The middleware runs in every environment, `local` included, and locks everyone out while the password is unset. Serve the dashboard over HTTPS only: Basic auth sends the password with every request.

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
| `server_identifier` | `null` | How this instance identifies itself in distributed mode. `null` = auto-detect: the first supervisor key of the Horizon environment (`horizon.env`, else `APP_ENV`; wildcard keys such as `production*` match the way Horizon matches them), then the first under `horizon.defaults`, then `gethostname()`. Set it explicitly for static names, containers, etc. |
| `queues` | `null` | Default queues to monitor. `null` = auto-detect from `config('horizon.defaults.*.queue')`. Pass `['default', 'emails']` to pin. |
| `max_jobs` | `1000` | Hard cap on jobs returned in a single query. Prevents memory blowups on very deep reserved sets. |
| `long_running_threshold` | `300` | Seconds before a job's row is flagged as "long-running" in warnings. |
| `cache.enabled` | `true` | Cache the running-jobs API responses (`/running-jobs`, `/running-jobs/stats`) and facade calls for `cache.ttl` seconds. The dashboard panels and the CLI always read live data. |
| `cache.ttl` | `10` | Cache duration in seconds. A job shorter than this can start and finish without appearing in an API response. |
| `cache.prefix` | `'horizon_running_jobs'` | Cache key prefix. |
| `routes.enabled` | `true` | Whether the JSON API routes are registered. |
| `routes.prefix` | `'api'` | URL prefix for API routes. |
| `routes.middleware` | `['api', 'throttle:60,1']` | Middleware stack for API routes. The `Authorize` middleware is appended unconditionally. |
| `routes.uri` | `'horizon/running-jobs'` | Path segment for the running-jobs endpoint. |
| `ui.enabled` | `true` | Whether the Blade dashboard route is registered. |
| `ui.prefix` | `'horizon/queue-monitor'` | URL prefix for the dashboard. |
| `ui.middleware` | `['web']` | Middleware stack for the dashboard. The `Authorize` middleware is appended unconditionally. `web` is required for sessions + CSRF on the release POST. |
| `redis_connection` | `null` | Redis connection name. `null` = auto-detect from `config('horizon.use')`. |
| `retry_after` | `null` | The queue's `retry_after` window, used for duration math. `null` = auto-detect: the queue connection your Horizon supervisors run on (`connection` in `horizon.defaults` / `horizon.environments`), then any `redis`-driver queue connection on Horizon's Redis connection, then 90 (the value in Laravel's stock `config/queue.php`). On the connection found, a missing key means 60 (the connectors' default) and `null` means reservations never expire, so no job is reported as a zombie. |
| `supervisor_stale_grace_seconds` | `5` | Extra margin on top of Horizon's own alive window (29s for a supervisor, 14s for a master) before a process is flagged stale and its jobs orphaned. It cannot extend past Horizon's own cleanup: a running master removes an entry after 14s of silence. |
| `track_workers` | `true` | Have Horizon workers record which supervisor runs each job (one Redis write when a job starts, one when it ends). Used for the Server column, distributed filtering, orphan detection and start times. `false` falls back to dispatch-time tags. |
| `release_margin_seconds` | `10` | Seconds past a job's timeout before it is offered for release. Absorbs clock differences between worker hosts and the one viewing the dashboard. |

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

// Skip the response cache for a live read
$result = RunningJobs::withoutCache(fn ($manager) => $manager->getRunningJobs(null, true));
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
| `supervisors` | ZSET | Supervisor names (score = time of last heartbeat; Horizon treats one as alive for 29s after it) |
| `masters` | ZSET | Master process names (score = time of last heartbeat; alive for 14s after it) |
| `supervisor:{name}` | HASH | Per-supervisor metadata (pid, queues, process counts) |
| `master:{name}` | HASH | Per-master metadata, including the supervisors it manages |

And it writes one key of its own, on the queue's Redis connection, while a job runs:

| Key | Type | Contains |
|---|---|---|
| `horizon-running-jobs:{workers}:<uuid>` | STRING | JSON `{"supervisor": "<master>:<key>", "started_at": <unix>}`, written by the worker when the job starts, deleted when it finishes or fails; expires after a day if the worker is killed. The `{workers}` hash tag keeps all of them on one Redis Cluster slot. |

### Identifying which job belongs to which server

Three paths, in order:

1. **Worker record** — the key above. Names the exact supervisor running the job; orphan detection matches it exactly.
2. **Tags** — Horizon stores tags as part of the job payload, computed at dispatch. The package looks for `server:<name>` and matches it against the *key* part of live supervisor names.
3. **`supervisor_id` property** — fallback if no tag is set. The package extracts via regex (no `unserialize`).

The `TracksServer` trait adds the tag for you. If you have your own `tags()`, merge the trait's `serverTags()` into it, or return a `server:<identifier>` tag yourself.

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

The running-jobs API responses (`/running-jobs`, `/running-jobs/stats`) and facade calls cache for `cache.ttl` seconds (default 10) to prevent hammering Redis. The supervisors and queues endpoints are never cached. The dashboard panels and the CLI commands bypass the cache, so they show jobs shorter than the TTL; use `RunningJobs::withoutCache()` for the same in your own code. Cache keys embed an epoch counter; calling `RunningJobs::clearCache()` increments the epoch, invalidating every previously-cached response without needing wildcard deletes.

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

For end-to-end testing against a real Laravel app, there's a companion demo project at **[github.com/ashiqfardus/laravel-horizon-running-jobs-demo](https://github.com/ashiqfardus/laravel-horizon-running-jobs-demo)** — a fresh Laravel 13 install with Horizon configured and a handful of dummy jobs (fast / medium / slow / flaky / memory-heavy / stuck) already wired up. Clone it as a sibling of this package directory and it'll resolve via Composer's path symlink:

```bash
git clone https://github.com/ashiqfardus/laravel-horizon-running-jobs-demo.git
cd laravel-horizon-running-jobs-demo
composer install
cp .env.example .env && php artisan key:generate

php artisan horizon                           # terminal 1
php artisan demo:dispatch-workload            # terminal 2 — varied jobs across queues
php artisan demo:simulate-orphan --count=2    # terminal 2 — flip to broken state
php artisan horizon:running-jobs --orphaned   # observe the orphans (flagged ~15s after the kill)
php artisan horizon:release --orphaned        # release them once past their timeout (~40s after dispatch)
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
