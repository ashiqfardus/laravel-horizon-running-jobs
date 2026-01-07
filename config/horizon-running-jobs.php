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
    | When set to null (default), the package automatically reads the
    | supervisor key from your horizon.php config:
    |
    | - First checks: horizon.environments.{current_env}
    | - Then checks: horizon.defaults
    |
    | Examples from your horizon.php that will be auto-detected:
    |
    | 'defaults' => [
    |     'supervisor-1' => [...],      // Auto-detected as 'supervisor-1'
    |     gethostname() => [...],       // Auto-detected as the hostname
    | ],
    |
    | Only set this manually if you need to override the Horizon config.
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
    | Set to null to use the default connection.
    |
    */
    'redis_connection' => null,
];

