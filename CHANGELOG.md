# Changelog

All notable changes to `horizon-running-jobs` will be documented in this file.

## [Unreleased]

### Fixed

- **Live supervisors are no longer flagged stale, and their running jobs are no longer flagged orphaned.** Horizon stores the time of each supervisor's last heartbeat in Redis. The package read that value as an expiry time, so a supervisor was treated as dead 5 seconds after its last heartbeat instead of the 29 seconds Horizon allows (14 for a master). A short pause in heartbeats marked every job of that supervisor as orphaned and offered it for release while it was still running. The package now uses Horizon's own windows, with `supervisor_stale_grace_seconds` added on top, and a supervisor that a live master still manages also counts as alive. If you raised that setting to 30 as a workaround, you can set it back to 5.
- **Jobs dispatched from one server and run on another are no longer reported as orphaned.** The package matched jobs to servers through their `server:` tag, which Horizon writes at dispatch time, so with web nodes dispatching and worker nodes processing, every job looked orphaned. Workers now record which supervisor runs each job (see `track_workers` under Added).
- **Hosts that share a supervisor key no longer hide each other's dead workers.** With Horizon's stock `supervisor-1` key on every host, a live host kept the jobs of a dead one from being reported as orphaned. Orphan detection now compares the exact supervisor name from the worker record.
- **Release never runs a job twice.**
  - Orphans and expired jobs are offered for release only once they have run past the timeout their worker enforces, plus `release_margin_seconds`. Before, jobs still finishing during `horizon:terminate` were offered for release. An orphan without a worker record is released only once its reservation has also expired.
  - A job that finished or was picked up again between "find" and "release" was pushed back to the pending list anyway. Release is now a single Redis script that pushes the job only if it was still reserved.
  - If the live supervisors could not be read from Redis, orphan release treated every tagged job as orphaned. It now stops and releases nothing.
  - The dashboard's per-row Release button is re-checked on the server, so a stale page cannot release a job that a worker has picked up again.
- **The dashboard's "release all" button released zombies.** It always sent `orphaned=true`, so on a page with only zombies it released nothing. It now releases every orphan and expired job that is past its timeout, and says how many.
- Released jobs now wake workers that wait with `block_for`, the same way Laravel's own scripts do.
- **`retry_after` auto-detection.** The lookup used `horizon.use` (a Redis connection name) as a queue connection name, found nothing on most apps and fell back to 90. With a longer `retry_after`, running jobs showed a duration of 0s and long-running warnings fired late. The value now comes from the queue connection your Horizon supervisors run on, with the environment's options overriding the defaults as in Horizon. A connection without a `retry_after` key now counts as 60, the default Laravel and Horizon use.
- **`retry_after => null`.** With reservations that never expire, every running job was reported as a zombie one second after it started, and `horizon:release --zombie` released them all. No job is reported as a zombie in that setup now.
- Wildcard environment keys in `horizon.php` (`'production*' => [...]`) and `horizon.env` are honoured when detecting the server identifier, `retry_after` and supervisor timeouts.
- **Dashboard panels kept refreshing only once.** Each refresh replaced the panel with a fragment that had no polling component, so after the first refresh every panel froze and its pause button disappeared. Panels now keep polling, keep their paused state, and never refresh under an open confirm dialog.
- `horizon:running-jobs` refused to list anything when Horizon was not running on the current host, which hid the orphans right after a crash and on servers that only dispatch. It now prints a warning and lists the jobs (no warning with `--json`).
- **Short jobs now appear on the dashboard.** The panels poll every 3 to 5 seconds but were served from the 10 second response cache, so a job shorter than the cache TTL could run without ever being shown. The dashboard panels, `horizon:running-jobs` and `horizon:diagnose` now always read live data. The running-jobs API is still cached. If you published `running-jobs-table.blade.php` earlier, its auto-refresh reads live data too; re-publish it to make the first render live as well.
- `TracksServer`: reading `supervisor_id` on a job that never called `initializeServerTracking()` no longer throws. The property is now nullable and the constructor call is optional.
- `horizon:release --orphaned` and the dashboard's bulk release now include orphans identified only through the `supervisor_id` property, matching the orphan count shown.
- A supervisor whose entry Horizon no longer lists, but which is not stale yet, is shown with a warning badge instead of a green one.

### Added

- PHP 8.5 support, and PHPUnit 13 in the development dependencies. The test suite now also runs with the predis client.
- `release_margin_seconds` config option (default `10`): seconds past a job's timeout before it is offered for release.
- `track_workers` config option (default `true`). Horizon workers record which supervisor runs each job and when it started, in a short Redis key removed when the job ends. Restart Horizon (`php artisan horizon:terminate`) after updating so workers start writing them.
- `supervisor`, `is_releasable` and `releasable_at` on every job in `GET /api/horizon/running-jobs` and `horizon:running-jobs --json`.
- `horizon:release --orphaned --zombie`, and `orphaned` + `zombie` together on the dashboard's release endpoint.
- `TracksServer::serverTags()`. A job that defines its own `tags()` replaces the trait's and loses the `server:` tag; merge `serverTags()` into your list to keep it.
- `RunningJobs::withoutCache(fn ($manager) => ...)` for a live read that skips the response cache.
- `server_identifier` in the `GET /api/horizon/running-jobs` response, and a `?server_identifier=` query parameter.
- `last_heartbeat_at` and `seconds_since_heartbeat` on every supervisor and master row (`GET /api/horizon/supervisors`, `horizon:supervisors --json`).

### Changed

- `horizon:release --orphaned` / `--zombie` and the dashboard only release jobs that have run past their timeout. A job with no timeout is no longer released in bulk; release it by UUID. `horizon:release <uuid>` still releases any reserved job and now warns when a worker may still be running it.
- In distributed mode each instance lists the jobs its own workers run. Before, it listed the jobs it had dispatched. This relies on `server_identifier` matching the instance's supervisor key, as the config already requires; if yours differs, local views (without `--all` / `?all=true`) will not show those jobs.
- For subclasses: `RunningJobsManager::parseJobData()` and `isJobOrphaned()` take a new optional last parameter, so overrides must add it; `JobReleaser::matchReason()` has a new signature and `serverTagFromPayload()` was removed.
- `start_time` / `running_for_seconds` come from the worker's record of when the job started, when there is one.
- The supervisors panel and `horizon:supervisors` show a `Heartbeat` column ("3s ago") in place of `Expires`, which always read 0s.
- `expires_at` and `seconds_until_expiry` on supervisor and master rows now describe when Horizon itself stops treating the process as alive (last heartbeat + 29s, or + 14s for a master). Before, `expires_at` was the last heartbeat time and `seconds_until_expiry` was always 0.
- The release endpoint answers `409` when a job given by `job_id` may still be running, `503` when the live supervisors cannot be read, and `422` when `job_id` is combined with `orphaned` / `zombie` (the message changed).
- `TracksServer::$supervisor_id` is now `?string` with a `null` default. Restart Horizon right after deploying, as usual: a worker still running the old code cannot process a job dispatched by the new code if the job uses the trait without `SerializesModels`. If a job class declares its own `$supervisor_id` next to the trait, change the declaration to `public ?string $supervisor_id = null;`.
- `?hostname=` on `GET /api/horizon/running-jobs` is validated like queue names (alphanumeric, underscore, colon, dot, dash; up to 100 characters) and returns 422 otherwise.
- If you published the package views, re-publish them to get the `Heartbeat` column and the new release behaviour. Older copies keep working: their Release buttons are re-checked on the server.

### Deprecated

- `hostname` in the `GET /api/horizon/running-jobs` response, and the `?hostname=` query parameter. The field holds the server identifier (with Horizon's stock config, the supervisor key), not the machine's host name. Use `server_identifier`.

## [2.1.1] - 2026-04-26

### Fixed

- **Dashboard CSS / JS now load on production nginx servers.** The asset route at `/horizon/queue-monitor/assets/{file}` previously generated URLs ending in `.css` / `.js`, which the typical nginx production block (`location ~* \.(css|js|...)$`) intercepts as static-file requests. The static lookup 404s before Laravel ever sees the request, leaving the dashboard unstyled and Alpine uninitialised. The route now serves at `/horizon/queue-monitor/assets/css` and `/horizon/queue-monitor/assets/js` (no extension), so nginx forwards them to PHP normally. The package's own dashboard view was updated to call the new URL — no consumer-side changes needed unless you hardcoded the old URL anywhere. Localhost setups using `php artisan serve` were unaffected because that server routes everything to Laravel.

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
