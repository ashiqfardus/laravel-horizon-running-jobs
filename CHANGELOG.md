# Changelog

All notable changes to `horizon-running-jobs` will be documented in this file.

## [Unreleased]

### Added

- Laravel 13 support. PHP floor raised to 8.1.
- `HorizonRunningJobs::auth($callback)` for registering an authorization closure. The callback receives the `Illuminate\Http\Request` and returns a boolean. Smart defaults: `local` and `testing` environments are always allowed; outside those, the callback decides; without a callback the request is denied with a 403 whose body explains how to register one.
- `Http\Middleware\Authorize` is appended to the API route group's middleware unconditionally so the gate runs even when the user customizes the middleware list.
- `throttle:60,1` is included in the default route middleware to cap any caller to 60 requests per minute.
- `?queues=` query-parameter validation. Each name must match `[A-Za-z0-9_:.-]+`, with a maximum of 20 names per request. Invalid input returns 422.
- `status` field on every job — `running` or `zombie`. A `zombie` is a reserved-set entry whose reservation has already expired (worker died mid-job, or Horizon has not reaped it yet). The warnings list includes a zombie count when any are present.
- `dropped_count` on responses — number of malformed reserved-set entries that could not be parsed. Each drop is logged via `Log::warning` with the queue name and the underlying error.
- CLI `Status` column showing per-row `running` / `⚠ zombie`.
- CLI `--json` output now includes `total_count`, `shown_count`, and a `truncated` boolean so scripts can detect when results were trimmed by `--limit` or `max_jobs`.
- `--stats` now returns `by_status` and surfaces `dropped_count` alongside the existing breakdowns.
- `retry_after` config option to override the auto-detected queue `retry_after` window when the heuristic picks the wrong value.

### Changed

- Running-duration math now accounts for the queue `retry_after` window. Redis stores reserved-set scores as `reservation_time + retry_after` (the expiry), not the reservation time itself. The manager now subtracts `retry_after` (auto-detected from `config('queue.connections.<horizon.use>.retry_after')`, default 90) to recover the true reservation time. Previous versions returned negative durations.
- `clearCache()` rewritten using epoch versioning. `Cache::forget($prefix . ':*')` did not support wildcards and silently did nothing; cache keys now embed an epoch counter that `clearCache()` increments, invalidating every previously cached entry.
- HTTP controller now defaults the hostname to `RunningJobsManager::getServerIdentifier()` instead of `gethostname()`. The previous default returned the web tier's hostname, which usually does not match a Horizon worker in distributed deployments.
- The `supervisor_id` fallback now scans the serialized payload with a regex instead of `unserialize()`. No class instantiation, no gadget-chain exposure, and it works when the originating job class is not autoloadable in the reading process.
- Redis connection resolution falls back through `redis_connection` config → `config('horizon.use')` → Laravel default.
- `RunningJobsManager::parseJobData()` is now `public` and throws `RuntimeException` on malformed payloads instead of returning `null`.
- `RunningJobsManager::getJobsForQueue()` return shape is now `['jobs' => array, 'dropped' => int]`.
- `RunningJobsManager::getDefaultQueues()` is now `public`. The HTTP controller delegates to it instead of duplicating queue-detection logic.
- `response().jobs[*].start_time` / `start_timestamp` reflect the actual reservation time rather than the Redis expiry score.

### Fixed

- Queue auto-detection no longer breaks on hostnames containing dots. The previous code used `config('horizon.defaults.' . gethostname())`, which interprets dots as a path separator and returned nothing for hostnames like `app-server.local`.
- Jobs whose reservation has expired are no longer silently filtered out of the response.
- Malformed reserved-set entries are no longer skipped silently. They are now caught, logged, and counted.

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
