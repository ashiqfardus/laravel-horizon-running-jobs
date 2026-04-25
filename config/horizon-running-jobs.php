<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Distributed Mode
    |--------------------------------------------------------------------------
    |
    | Set to true if you have multiple application servers connected to a
    | shared Redis instance. When false, server filtering is disabled and
    | all running jobs are shown regardless of which server processes them.
    |
    | - true: Filter jobs by server identifier (distributed setup)
    | - false: Show all jobs without server filtering (single server setup)
    |
    */
    'distributed' => false,

    /*
    |--------------------------------------------------------------------------
    | Server Identifier
    |--------------------------------------------------------------------------
    |
    | The identifier for this server in distributed mode.
    |
    | Auto-detection (null): Works when your horizon.php uses gethostname():
    |   'defaults' => [
    |       gethostname() => [...],  // Each server has unique hostname
    |   ]
    |
    | Manual configuration: Required when using static supervisor names:
    |   'defaults' => [
    |       'supervisor-01' => [...],  // Server 1
    |       'supervisor-02' => [...],  // Server 2
    |   ]
    |
    |   In this case, set on each server:
    |   - Server 1: 'server_identifier' => 'supervisor-01'
    |   - Server 2: 'server_identifier' => 'supervisor-02'
    |
    |   Or use environment variable:
    |   'server_identifier' => env('HORIZON_SUPERVISOR_NAME')
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
        'middleware' => ['api'], // Add 'auth:sanctum' for authentication
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

