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
    | Auto-detect (null) — resolves, in order, to:
    |
    |   1. the first supervisor key under horizon.environments.<env>, where
    |      <env> is horizon.env, else APP_ENV, and keys may be wildcards
    |      (`production*`) as in Horizon itself,
    |   2. the first supervisor key under horizon.defaults,
    |   3. gethostname(), when horizon.php defines no supervisors.
    |
    | That gives each instance its own identifier when the environment block
    | of horizon.php keys its supervisor by gethostname():
    |
    |     // config/horizon.php
    |     'environments' => [
    |         'production' => [
    |             gethostname() => [...],
    |         ],
    |     ]
    |
    | With Horizon's stock config every instance resolves to "supervisor-1",
    | so instances cannot be told apart: use a static name instead.
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
    | The running-jobs API responses (and RunningJobs::getRunningJobs() /
    | getStats() calls) are cached for `ttl` seconds to keep a busy endpoint from
    | hammering Redis. A job that starts and finishes inside one cached
    | answer never shows up in that answer, so keep `ttl` below the shortest
    | job you care to see, or set `enabled` to false.
    |
    | The browser dashboard panels and the CLI commands always read live
    | data and are not affected by this setting. In your own code, use
    | RunningJobs::withoutCache(fn ($manager) => $manager->getRunningJobs())
    | for a live read.
    |
    */
    'cache' => [
        'enabled' => true,
        'ttl' => 10, // seconds
        'prefix' => 'horizon_running_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Tracking
    |--------------------------------------------------------------------------
    |
    | When a Horizon worker starts a job, the package records which supervisor
    | runs it and when it started: one short Redis key per running job,
    | removed when the job finishes or fails.
    |
    | A job's tags are written when it is dispatched, so on their own they
    | name the instance that dispatched the job. The worker record names the
    | exact supervisor running it, which is what the Server column,
    | distributed filtering and orphan detection need. Jobs without a record
    | (tracking off, or workers not yet restarted on this version) fall back
    | to their `server:` tag.
    |
    | Costs one Redis write when each job starts and one when it ends.
    |
    */
    'track_workers' => true,

    /*
    |--------------------------------------------------------------------------
    | Release Margin (seconds)
    |--------------------------------------------------------------------------
    |
    | Orphaned and expired jobs are offered for release only once they have
    | run past their timeout, plus this many seconds. A Laravel worker stops
    | a job when it hits its timeout, so a healthy worker is done with it by
    | then. The margin absorbs clock differences between the worker's host
    | and the one viewing the dashboard; raise it if those clocks drift.
    |
    | Orphans also need a worker record (see track_workers). Without one, a
    | job is offered only once its reservation has expired as well.
    |
    | Caveat: the timeout is enforced with a signal, which a job blocked in
    | some C extension calls (a socket read without its own timeout) cannot
    | handle until the call returns.
    |
    */
    'release_margin_seconds' => 10,

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
    | Supervisor Stale-Check Grace Window
    |--------------------------------------------------------------------------
    |
    | Each Horizon supervisor writes the time of its last heartbeat to Redis
    | about once a second. Horizon itself treats a supervisor as alive while
    | that heartbeat is at most 29 seconds old (14 seconds for a master).
    |
    | This setting is extra margin, in seconds, added ON TOP of Horizon's
    | window: a supervisor is flagged stale once it has been silent for more
    | than 29 + grace seconds, a master after 14 + grace. The same rule
    | drives orphan detection (RunningJobsManager::getLiveSupervisorNames):
    | a supervisor counts as alive while its heartbeat is that recent, or
    | while a live master still manages it.
    |
    | The margin cannot outlast Horizon's own cleanup. A running master
    | removes any supervisor or master entry after 14 seconds of silence.
    | When a whole host goes quiet that long while another host's master is
    | up, its entries are gone and its jobs are reported as orphaned,
    | whatever this value is.
    |
    | Defaults to 5 seconds.
    |
    */
    'supervisor_stale_grace_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | UI Dashboard
    |--------------------------------------------------------------------------
    |
    | The Blade dashboard at /horizon/queue-monitor (and the related panel-
    | refresh + release endpoints + asset serving). Disable here to ship the
    | API/CLI features without the browser dashboard.
    |
    | The dashboard uses `web` middleware (sessions + CSRF) rather than `api`
    | so the inline Release button can submit a CSRF-protected POST.
    |
    */
    'ui' => [
        'enabled' => true,
        'prefix' => 'horizon/queue-monitor',
        'middleware' => ['web'],
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
    | time. Set to null to auto-detect, in this order:
    |
    |   1. the queue connection your Horizon supervisors run on
    |      (the `connection` key in horizon.defaults / horizon.environments),
    |   2. any redis-driver queue connection that uses the same Redis
    |      connection as Horizon (horizon.use),
    |   3. 90 (the value in Laravel's stock config/queue.php).
    |
    | On the connection found, a missing retry_after key means 60 (the
    | default of Laravel's and Horizon's connectors), and null means
    | reservations never expire: no job is then reported as a zombie.
    |
    | Override only if the auto-detect picks up the wrong value, e.g. when
    | supervisors run on queue connections with different retry_after values.
    | Jobs started by a worker on this version record their real start time,
    | so this setting only affects jobs without that record.
    |
    */
    'retry_after' => null,
];

