# Changelog

All notable changes to `horizon-running-jobs` will be documented in this file.

## [Unreleased]

> Phases 1, 2, and 3 will ship together in the next tagged release. This section
> currently tracks Phase 1 (critical bug fixes + Laravel 13 support + demo app).
> Phase 2 (security defaults + dedup) and Phase 3 (supervisor health inspection)
> will be appended here before the version is tagged.

### Added
- **Laravel 13 support.** PHP floor raised to 8.1 (Laravel 13 itself requires PHP 8.3+ — Composer will resolve the right framework version for your PHP).
- **`status` field on every job** — `"running"` or `"zombie"`. A `zombie` job is one whose reservation has expired but still sits in the reserved set (worker died mid-job or Horizon hasn't reaped it). Warnings summary includes a zombie count.
- **`dropped_count` on responses** — number of malformed reserved-set entries skipped. Each drop is logged via `Log::warning` with the queue name and error.
- **`total_count`, `shown_count`, `truncated` in CLI `--json`** — lets scripts detect when `--limit`/`max_jobs` truncated the result.
- **CLI Status column** — table now shows per-row `running` / `⚠ zombie`.
- **Stats endpoint expanded** — now returns `by_status` and `dropped_count`.
- **`retry_after` config option** — override the auto-detected queue retry_after when the heuristic picks the wrong value.
- **Widget / Vue assets** remain publishable via `--tag=horizon-running-jobs-assets` (documented).

### Fixed
- **Running-duration math (#8).** Redis stores reserved-set scores as `reservation_time + retry_after` (expiry), not reservation time. Previous versions showed negative durations. Manager now subtracts `retry_after` to recover the true reservation time. Package auto-detects `retry_after` from `config('queue.connections.<horizon.use>.retry_after')` (default 90).
- **`clearCache()` was a no-op (#1).** `Cache::forget($prefix . ':*')` does not support wildcards. Rewritten using epoch versioning: the cache key includes a version number, and `clearCache()` increments that version so all previously cached entries become unreachable instantly.
- **HTTP controller defaulted to web-tier hostname (#2).** `gethostname()` returns the web server's name, which rarely matches a Horizon worker. Controller now uses `$manager->getServerIdentifier()` to stay consistent with the CLI.
- **Zombies were silently dropped (#3).** Jobs past their reservation expiry were filtered out; they are now surfaced with `status: "zombie"`.
- **Malformed jobs silently skipped (#5).** Now logged and counted.
- **`unserialize` of untrusted Redis payload (#4).** Replaced with a regex scan of the serialized string — no class instantiation, no gadget-chain risk, and works even when the originating job class isn't autoloadable.
- **Redis connection did not fall back to Horizon's config (#6).** Now resolves `redis_connection` → `config('horizon.use')` → Laravel default.
- **Queue auto-detection broke on hostnames containing dots.** `config('horizon.defaults.' . gethostname())` treats dots as path separators, so hostnames like `server.local` returned nothing. Manager now reads the defaults array and indexes by hostname directly.
- **Controller and CLI duplicated queue-detection logic.** Controller now delegates to `RunningJobsManager::getDefaultQueues()` (which is now public).

### Changed (potentially breaking)
- `RunningJobsManager::parseJobData()` is now **`public`** and **throws `RuntimeException`** on malformed payloads (previously returned `null`). Callers extending the manager should re-read the signature.
- `RunningJobsManager::getJobsForQueue()` return shape changed from `array<int, array>` to `['jobs' => array, 'dropped' => int]`. Only relevant to subclasses.
- `RunningJobsManager::getDefaultQueues()` elevated from `protected` to `public`.
- Removed the old `if ($currentTimestamp > $timestamp + $timeout) return null` filter — jobs that look like zombies now surface with `status: "zombie"` rather than being hidden.
- `response().jobs[*].start_time` / `start_timestamp` now reflect the *actual reservation time*, not the expiry score.

### Removed
- PHP 8.0 dropped from the support matrix (Laravel 10+ requires 8.1+).

## [1.0.0] - 2026-01-07

### Added
- Initial release
- `horizon:running-jobs` Artisan command
- HTTP API endpoints for running jobs and statistics
- `TracksServer` trait for easy job integration
- Hybrid server identification (tags + supervisor_id fallback)
- Response caching for high-traffic APIs
- Statistics aggregation by server, queue, and job class
- Long-running job warnings
- JSON output mode for CLI
- Multi-queue support
- Configurable route middleware
- Standalone JavaScript widget for dashboard integration
- Vue.js component for modern frontends
- Support for Laravel 9.x, 10.x, 11.x, and 12.x
- Support for PHP 8.0, 8.1, 8.2, 8.3, and 8.4
- Support for Horizon 5.x and 6.x

