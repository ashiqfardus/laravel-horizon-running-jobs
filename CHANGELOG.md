# Changelog

All notable changes to `horizon-running-jobs` will be documented in this file.

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

