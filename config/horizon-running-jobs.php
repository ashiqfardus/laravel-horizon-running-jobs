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
    | The identifier for this server. Used in distributed mode to track
    | which server is processing each job.
    |
    | Options:
    | - null: Uses gethostname() automatically
    | - 'server-01': Static string identifier
    | - You can also use env('HORIZON_SERVER_ID', gethostname())
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

