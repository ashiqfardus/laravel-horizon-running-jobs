# Changelog

All notable changes to `horizon-running-jobs` will be documented in this file.

## [Unreleased]

## [2.1.0] - 2026-04-25

### Added

- **Custom release-confirmation modal** replaces the browser-native `confirm()` dialog. Shows the job's class, queue, UUID, and reason ("orphan" / "zombie" / "orphan + zombie") before the user commits to the action. Closes on Esc, click-outside, or Cancel.
- **Bulk release button** — when the running-jobs panel detects orphans or zombies, a "release all" button appears next to the panel header. Confirms with a modal explaining what will happen, then POSTs to the release endpoint with `{orphaned: true}` or `{zombie: true}`. Equivalent to running `php artisan horizon:release --orphaned --force` from the dashboard.
- **Job payload drill-down** — clicking any row in the running-jobs panel opens a modal with the full job payload: class, UUID, queue, server, status, start time, duration, attempts, timeout, and all tags. Useful for distinguishing between multiple instances of the same job class.
- **Pause / resume auto-refresh** — every panel header now has a pause button. While paused the panel keeps its current state; clicking play resumes polling and triggers an immediate refresh.
- **Status badge icons** — every status badge (pass / warn / fail / orphan / zombie) now has an icon prefix (✓ / ⚠ / ✗ / ◯ / ☠) so the signal isn't conveyed by color alone. Better for color-blind operators.
- **Subtle fade-in transition** on panel and banner refreshes (220ms). Respects `prefers-reduced-motion`. Makes auto-refresh feel less jumpy.

### Changed

- The release HTTP endpoint (`POST /horizon/queue-monitor/release`) now accepts three mutually-exclusive targeting modes: `job_id`, `orphaned: true`, or `zombie: true`. Passing zero or more than one returns 422.

### Fixed

- Distributed-mode filtering now has explicit integration test coverage with three concurrent instances (previously: only two-server scenario tested).

## [2.0.0] - 2026-04-25

### Added

#### CLI commands

- `php artisan horizon:supervisors` — inspect every Horizon supervisor and master process registered in Redis across the whole deployment. Surfaces name, status (running / paused / stale), assigned master, pid, queues, worker process count, expiry, and an `is_stale` flag for entries whose registration expired but have not been reaped. `--masters` adds the master table; `--json` emits the raw payload.
- `php artisan horizon:queues` — show pending, reserved, and delayed counts per queue plus an aggregate total row. `--queue=` repeats to limit to specific queues, `--json` emits the raw payload.
- `php artisan horizon:diagnose` — unified health check across supervisors, jobs, and queue depths. Each subcheck reports pass / warn / fail, the command exits non-zero on any fail (e.g. no live supervisor). `--json` emits a structured payload suitable for monitoring scripts.
- `php artisan horizon:release` — move reserved jobs back to the pending list. Targeting: a single job UUID, `--orphaned` (every orphaned reservation), or `--zombie` (every expired reservation). `--queue=` repeats to scope to specific queues. `--dry-run` previews; without it the command prompts for confirmation unless `--force` is set. Each release is logged via `Log::info` with the job UUID, queue, and reason. Atomic per-job (ZREM + LPUSH in one Redis transaction) so jobs can't be lost.
- `--watch=N` flag on `horizon:running-jobs`, `horizon:queues`, and `horizon:supervisors` — re-renders the table every N seconds (default 3). Press Ctrl-C to exit. Ignored when `--json` is set.
- `--orphaned` flag on `horizon:running-jobs` — shows only orphaned jobs. The HTTP API exposes the same filter via `?orphaned=true`.
- `By Orphan Status` breakdown in `--stats` output.
- CLI `Status` column showing per-row `running` / `⚠ zombie`.
- CLI `--json` output now includes `total_count`, `shown_count`, and a `truncated` boolean so scripts can detect when results were trimmed by `--limit` or `max_jobs`.
- `--stats` now returns `by_status` and surfaces `dropped_count` alongside the existing breakdowns.

#### HTTP API

- `GET /api/horizon/supervisors` — JSON equivalent of the CLI command, with summary counts (`supervisor_count`, `master_count`, `stale_supervisor_count`). Gated by the same `Authorize` middleware as the rest of the API.
- `GET /api/horizon/queues` — JSON equivalent: per-queue breakdown plus aggregate `totals` and `queue_count`. Same `?queues=` filter and validation rules as the running-jobs endpoint.
- `POST /horizon/queue-monitor/release` — CSRF-gated release endpoint backing the dashboard's inline release button. Delegates to the same `JobReleaser` service the CLI uses.
- `?queues=` query-parameter validation. Each name must match `[A-Za-z0-9_:.-]+`, with a maximum of 20 names per request. Invalid input returns 422.

#### Browser dashboard

- Browser dashboard at `/horizon/queue-monitor` — a standalone Blade page showing a live health banner, supervisor table, queue depths, and running jobs with inline release buttons on orphan / zombie rows. Auto-refreshes via small polling intervals. Drops into any Laravel app with no JS framework dependency (Alpine.js loaded from CDN by the standalone page; users embedding individual panels supply their own).
- Composable Blade components for embedding panels into existing dashboards: `<x-horizon-running-jobs::dashboard />`, `<x-horizon-running-jobs::diagnose-banner />`, `<x-horizon-running-jobs::supervisors-panel />`, `<x-horizon-running-jobs::queues-panel />`, `<x-horizon-running-jobs::running-jobs-table />`. Each accepts a `:poll="ms"` prop; pass `0` to disable auto-refresh.
- Inline `release` button on orphan / zombie rows in the dashboard, posting to the CSRF-gated release endpoint.
- `php artisan vendor:publish --tag=horizon-running-jobs-views` — publish the Blade views for forking / theming. `--tag=horizon-running-jobs-css` publishes the scoped stylesheet for serving from your own public directory.

#### Detection and recovery

- Orphan detection. Every job row now carries an `is_orphaned` boolean. A job is orphaned when the Horizon supervisor name embedded in its tags (`server:<name>`) is no longer present in Horizon's live supervisor set — meaning the worker that reserved the job is gone. The response root includes `orphan_count`, and the warnings list is updated when orphans are found.
- `status` field on every job — `running` or `zombie`. A `zombie` is a reserved-set entry whose reservation has already expired (worker died mid-job, or Horizon has not reaped it yet). The warnings list includes a zombie count when any are present.
- `dropped_count` on responses — number of malformed reserved-set entries that could not be parsed. Each drop is logged via `Log::warning` with the queue name and the underlying error.

#### Security and configuration

- `HorizonRunningJobs::auth($callback)` for registering an authorization closure. The callback receives the `Illuminate\Http\Request` and returns a boolean. Smart defaults: `local` and `testing` environments are always allowed; outside those, the callback decides; without a callback the request is denied with a 403 whose body explains how to register one.
- `Http\Middleware\Authorize` is appended to the API route group's middleware unconditionally so the gate runs even when the user customizes the middleware list.
- `throttle:60,1` is included in the default route middleware to cap any caller to 60 requests per minute.
- `supervisor_stale_grace_seconds` config option (default `5`) — grace window before flagging a supervisor as stale. Absorbs normal heartbeat jitter so the dashboard doesn't flap between "running" and "stale" with every poll. The same window applies to orphan detection so a job's worker is only flagged "gone" once its supervisor has been silent for longer than the window.
- `retry_after` config option to override the auto-detected queue `retry_after` window when the heuristic picks the wrong value.

#### Laravel / PHP support

- Laravel 13 support added. PHP floor raised to 8.1 (8.1, 8.2, 8.3, 8.4 all supported).

### Changed

- **Production endpoints now deny by default.** Anywhere outside `local` / `testing` environments, the JSON API and dashboard return 403 unless `HorizonRunningJobs::auth($callback)` is registered. The 403 body includes a copy-paste example of how to register the callback. Breaking change from v1.0, where routes were always open.
- **Running-duration math now accounts for the queue `retry_after` window.** Redis stores reserved-set scores as `reservation_time + retry_after` (the expiry), not the reservation time itself. The manager now subtracts `retry_after` (auto-detected from `config('queue.connections.<horizon.use>.retry_after')`, default 90) to recover the true reservation time. v1.0 returned negative durations.
- **`response().jobs[*].start_time` / `start_timestamp` reflect the actual reservation time** rather than the Redis expiry score. Charts that built duration math on the v1.0 values may need adjustment.
- **`RunningJobsManager::parseJobData()` is now `public` and throws `RuntimeException`** on malformed payloads instead of returning `null`. Callers relying on the null-return need a try/catch.
- `RunningJobsManager::getJobsForQueue()` return shape is now `['jobs' => array, 'dropped' => int]`.
- `RunningJobsManager::getDefaultQueues()` is now `public`. The HTTP controller delegates to it instead of duplicating queue-detection logic.
- HTTP controller now defaults the hostname to `RunningJobsManager::getServerIdentifier()` instead of `gethostname()`. The previous default returned the web tier's hostname, which usually did not match a Horizon worker in distributed deployments.
- The `supervisor_id` fallback now scans the serialized payload with a regex instead of `unserialize()`. No class instantiation, no gadget-chain exposure, and it works when the originating job class is not autoloadable in the reading process.
- Redis connection resolution falls back through `redis_connection` config → `config('horizon.use')` → Laravel default.
- `clearCache()` rewritten using epoch versioning. `Cache::forget($prefix . ':*')` did not support wildcards and silently did nothing; cache keys now embed an epoch counter that `clearCache()` increments, invalidating every previously cached entry.

### Deprecated

- The legacy v1 standalone JS widget (`vendor:publish --tag=horizon-running-jobs-assets`) and Vue component are kept for backward compatibility but only show running jobs — no orphan / zombie / supervisor / queue-depth coverage. The new Blade dashboard is the recommended UI from this release on; the v1 widget will be removed in a future major version.

### Fixed

- Queue auto-detection no longer breaks on hostnames containing dots. The previous code used `config('horizon.defaults.' . gethostname())`, which interprets dots as a path separator and returned nothing for hostnames like `app-server.local`.
- Jobs whose reservation has expired are no longer silently filtered out of the response — they surface as `status: "zombie"`.
- Malformed reserved-set entries are no longer skipped silently. They are now caught, logged via `Log::warning`, and counted in `dropped_count`.

### Removed

- PHP 8.0 from the support matrix. Laravel 10+ requires 8.1+.

## [1.0.0] - 2026-01-07

### Added

- Initial release.
- `horizon:running-jobs` Artisan command.
- HTTP API endpoints for running jobs and statistics.
- `TracksServer` trait for job integration.
- Hybrid server identification (tags + `supervisor_id` fallback).
- Response caching for high-traffic APIs.
- Statistics aggregation by server, queue, and job class.
- Long-running job warnings.
- JSON output mode for the CLI.
- Multi-queue support.
- Configurable route middleware.
- Standalone JavaScript widget for dashboard integration.
- Vue.js component for modern frontends.
- Support for Laravel 9.x – 12.x, PHP 8.0 – 8.4, Horizon 5.x – 6.x.
