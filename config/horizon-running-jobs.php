<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Distributed Mode
    |--------------------------------------------------------------------------
    |
    | Enable when more than one Laravel application instance shares a single
    | Redis. The discriminator is "shared Redis," not "multiple servers" —
    | this includes any of:
    |
    |   - several apps on the same machine,
    |   - apps spread across multiple hosts,
    |   - containers / pods pointing at the same Redis service.
    |
    | When enabled, the package filters reserved jobs by their originating
    | supervisor identifier so each instance sees only its own work, with an
    | --all flag (CLI) and ?all=true query param (HTTP) to view everything.
    |
    | When disabled, no filtering is applied and the full reserved set is
    | returned. Use this for a single Laravel instance with a private Redis.
    |
    */
    'distributed' => false,

    /*
    |--------------------------------------------------------------------------
    | Server Identifier
    |--------------------------------------------------------------------------
    |
    | How this Laravel instance identifies itself when distributed mode is on.
    | Whatever value resolves here must match the supervisor key Horizon
    | uses for this instance in horizon.php.
    |
    | Auto-detect (null) — works out of the box when horizon.php keys its
    | supervisors by gethostname():
    |
    |     // config/horizon.php
    |     'defaults' => [
    |         gethostname() => [...],
    |     ]
    |
    | Static names — when each instance uses a fixed supervisor name, set
    | 'server_identifier' explicitly on each instance, typically from an env
    | var so the same image can be deployed to multiple targets:
    |
    |     'server_identifier' => env('HORIZON_SUPERVISOR_NAME'),
    |
    | Containers / pods — set the env var via your orchestrator (Kubernetes
    | downward API, Docker Compose service name, etc.). The value must match
    | whatever your horizon.php expects for that instance.
    |
    */
    'server_identifier' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Queues
    |--------------------------------------------------------------------------
    |
    | The queues to monitor when no specific queue is provided. Set to null
    | to automatically detect from Horizon configuration.
    |
    */
    'queues' => null, // null = auto-detect from Horizon config, or ['default', 'emails']

    /*
    |--------------------------------------------------------------------------
    | Maximum Jobs Per Query
    |--------------------------------------------------------------------------
    |
    | The maximum number of jobs to fetch from Redis in a single query.
    | This prevents memory issues when there are thousands of running jobs.
    |
    */
    'max_jobs' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Long Running Job Threshold
    |--------------------------------------------------------------------------
    |
    | Jobs running longer than this threshold (in seconds) will trigger
    | a warning in the CLI output and be flagged in API responses.
    |
    */
    'long_running_threshold' => 300, // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | API responses can be cached to prevent hammering Redis on high-traffic
    | endpoints. Set to 0 to disable caching.
    |
    */
    'cache' => [
        'enabled' => true,
        'ttl' => 10, // seconds
        'prefix' => 'horizon_running_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP API route for accessing running jobs.
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        // 'throttle:60,1' caps any caller to 60 requests/minute. Production
        // access is gated separately by the bundled Authorize middleware
        // (see HorizonRunningJobs::auth() in your AppServiceProvider).
        'middleware' => ['api', 'throttle:60,1'],
        'uri' => 'horizon/running-jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection to use for querying running jobs.
    | Set to null to auto-detect from config('horizon.use').
    |
    */
    'redis_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Queue Retry-After Window (seconds)
    |--------------------------------------------------------------------------
    |
    | Laravel stores reserved jobs in Redis with a score of
    |   reservation_time + retry_after
    | so the package subtracts retry_after to recover the actual reservation
    | time. Set to null to auto-detect from
    |   config('queue.connections.<horizon.use>.retry_after')
    | falling back to 90 (Laravel default).
    |
    | Override only if you use a non-default retry_after and the auto-detect
    | picks up the wrong value (e.g. per-queue differences).
    |
    */
    'retry_after' => null,
];

