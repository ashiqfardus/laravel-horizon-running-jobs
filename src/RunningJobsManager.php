<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunningJobsManager
{
    /**
     * Where a Horizon worker records which supervisor is running a job,
     * followed by the job's UUID. The `{workers}` hash tag keeps every
     * record on one Redis Cluster slot, so they can be read with one MGET.
     */
    public const WORKER_KEY_PREFIX = 'horizon-running-jobs:{workers}:';

    /** Lifetime of a worker record whose job never reported back (the worker was killed). */
    public const WORKER_RECORD_TTL = 86400;

    /**
     * Default seconds past a job's timeout before release is offered
     * (config `release_margin_seconds`). A Laravel worker kills itself when a
     * job hits its timeout; the margin absorbs clock differences between the
     * worker's host and this one.
     */
    public const RELEASE_MARGIN_SECONDS = 10;

    protected array $config;

    /** @var array<int, string>|null  memoized within a single fetchRunningJobs call; null after a failed lookup */
    protected ?array $liveSupervisorNames = null;

    /** Whether we've already attempted the live-supervisor lookup this request */
    protected bool $liveSupervisorsQueried = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get all running jobs.
     *
     * @param  string|null  $serverId       Filter by server identifier (null = current server)
     * @param  bool         $showAll        Show jobs from all servers
     * @param  array|null   $queues         Specific queues to check
     * @param  bool         $orphanedOnly   Restrict the response to orphaned jobs (workers that have died)
     * @return array
     */
    public function getRunningJobs(
        ?string $serverId = null,
        bool $showAll = false,
        ?array $queues = null,
        bool $orphanedOnly = false
    ): array {
        $serverId = $serverId ?? $this->getServerIdentifier();
        $queues = $queues ?? $this->getDefaultQueues();

        // If not in distributed mode, always show all jobs
        if (!$this->isDistributed()) {
            $showAll = true;
        }

        // Use caching if enabled
        if ($this->config['cache']['enabled'] ?? false) {
            $cacheKey = $this->buildCacheKey($serverId, $showAll, $queues, $orphanedOnly);
            $ttl = $this->config['cache']['ttl'] ?? 10;

            return Cache::remember($cacheKey, $ttl, function () use ($serverId, $showAll, $queues, $orphanedOnly) {
                return $this->fetchRunningJobs($serverId, $showAll, $queues, $orphanedOnly);
            });
        }

        return $this->fetchRunningJobs($serverId, $showAll, $queues, $orphanedOnly);
    }

    /**
     * Run a callback with the response cache bypassed, then restore the
     * configured setting. The cache exists to protect Redis from a busy JSON
     * API; anything a person watches live (dashboard panels, the CLI) reads
     * through this so jobs shorter than cache.ttl still show up.
     *
     *     $manager->withoutCache(fn ($m) => $m->getRunningJobs());
     *
     * @template T
     * @param  callable(static): T  $callback
     * @return T
     */
    public function withoutCache(callable $callback): mixed
    {
        $enabled = $this->config['cache']['enabled'] ?? false;
        $this->config['cache']['enabled'] = false;

        try {
            return $callback($this);
        } finally {
            $this->config['cache']['enabled'] = $enabled;
        }
    }

    /**
     * Check if running in distributed mode.
     */
    public function isDistributed(): bool
    {
        return $this->config['distributed'] ?? false;
    }

    /**
     * Whether Horizon's master process is currently running. Determined by
     * shelling out to `horizon:status` and matching the standard "Horizon
     * is running" output. Override this in a subclass / spy for tests.
     */
    public function isHorizonRunning(): bool
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('horizon:status');
            $output = trim((string) \Illuminate\Support\Facades\Artisan::output());

            return str_contains($output, 'Horizon is running');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the server identifier for this server.
     * Auto-detects from Horizon config.
     */
    public function getServerIdentifier(): string
    {
        // If explicitly configured, use that
        if (!empty($this->config['server_identifier'])) {
            return $this->config['server_identifier'];
        }

        // Get from Horizon config - check current environment first
        $envSupervisors = $this->environmentSupervisorBlock();

        if (!empty($envSupervisors)) {
            // Return the first supervisor key for current environment
            return array_key_first($envSupervisors);
        }

        // Fallback to defaults
        $defaults = config('horizon.defaults', []);
        if (!empty($defaults)) {
            return array_key_first($defaults);
        }

        // Ultimate fallback
        return gethostname();
    }

    /**
     * Fetch running jobs directly from Redis.
     */
    protected function fetchRunningJobs(string $hostname, bool $showAll, array $queues, bool $orphanedOnly = false): array
    {
        $this->liveSupervisorsQueried = false; // force a fresh read for this request
        $this->liveSupervisorNames = null;
        $allJobs = [];
        $warnings = [];
        $droppedCount = 0;
        $totalReserved = 0;
        $currentTimestamp = time();
        $maxJobs = $this->config['max_jobs'] ?? 1000;
        $longRunningThreshold = $this->config['long_running_threshold'] ?? 300;

        foreach ($queues as $queue) {
            try {
                // ZCARD gives the true reservoir size before max_jobs caps the
                // ZRANGE. Without this, total_count would only ever report what
                // we fetched, which would mask the fact that the user is being
                // truncated.
                $totalReserved += (int) $this->getRedisConnection()->zcard("queues:{$queue}:reserved");

                $result = $this->getJobsForQueue($queue, $hostname, $showAll, $currentTimestamp, $maxJobs);
                $allJobs = array_merge($allJobs, $result['jobs']);
                $droppedCount += $result['dropped'];
            } catch (\Exception $e) {
                $warnings[] = "Failed to fetch jobs from queue: {$queue}";
                Log::warning('HorizonRunningJobs: Queue fetch failed', [
                    'queue' => $queue,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Sort by duration (longest first)
        usort($allJobs, fn($a, $b) => $b['running_for_seconds'] <=> $a['running_for_seconds']);

        // Add warnings for long-running jobs
        $longRunning = array_filter($allJobs, fn($job) => $job['running_for_seconds'] > $longRunningThreshold);
        if (!empty($longRunning)) {
            $warnings[] = count($longRunning) . " job(s) running over " . ($longRunningThreshold / 60) . " minutes";
        }

        $zombies = array_filter($allJobs, fn($job) => ($job['status'] ?? 'running') === 'zombie');
        if (!empty($zombies)) {
            $warnings[] = count($zombies) . " zombie job(s) detected (reservation expired but still in queue)";
        }

        $orphans = array_filter($allJobs, fn ($job) => ($job['is_orphaned'] ?? false) === true);
        if (! empty($orphans)) {
            $warnings[] = count($orphans) . ' orphan job(s) detected (worker process is no longer registered)';
        }

        if ($droppedCount > 0) {
            $warnings[] = "{$droppedCount} malformed job(s) skipped (see logs)";
        }

        if ($orphanedOnly) {
            $allJobs = array_values($orphans);
        }

        return [
            'jobs' => array_slice($allJobs, 0, $maxJobs),
            'warnings' => $warnings,
            'total_count' => $totalReserved,
            'dropped_count' => $droppedCount,
            'orphan_count' => count($orphans),
        ];
    }

    /**
     * Get running jobs for a specific queue.
     *
     * @return array{jobs: array<int,array>, dropped: int}
     */
    protected function getJobsForQueue(
        string $queue,
        string $hostname,
        bool $showAll,
        int $currentTimestamp,
        int $maxJobs
    ): array {
        $key = "queues:{$queue}:reserved";
        $redis = $this->getRedisConnection();

        if (!$redis->exists($key)) {
            return ['jobs' => [], 'dropped' => 0];
        }

        $reservedJobs = $redis->zrange($key, 0, $maxJobs - 1, ['WITHSCORES' => true]);
        $workers = $this->workersFor($this->uuidsOf(array_keys($reservedJobs)), $redis);
        $jobs = [];
        $dropped = 0;

        foreach ($reservedJobs as $jobData => $timestamp) {
            try {
                $uuid = json_decode((string) $jobData, true)['uuid'] ?? null;
                $job = $this->parseJobData(
                    $jobData, $timestamp, $queue, $hostname, $currentTimestamp, $showAll,
                    is_string($uuid) ? ($workers[$uuid] ?? null) : null
                );

                if ($job !== null) {
                    $jobs[] = $job;
                }
            } catch (\Throwable $e) {
                $dropped++;
                Log::warning('HorizonRunningJobs: dropped malformed reserved job', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['jobs' => $jobs, 'dropped' => $dropped];
    }

    /**
     * Parse a reserved-set entry into a structured job array.
     *
     * Returns:
     *   - array: a valid job row
     *   - null:  filtered out (server scope mismatch)
     * Throws:
     *   - RuntimeException when the payload is malformed; callers count these
     *     as "dropped" and log them.
     *
     * $worker is the record the Horizon worker running the job wrote when it
     * started it (see workersFor()). It names the exact supervisor, so it
     * wins over the job's tags, which describe the process that dispatched it.
     *
     * @param  array{supervisor: string, started_at: int}|null  $worker
     */
    public function parseJobData(
        string $jobData,
        float $timestamp,
        string $queue,
        string $hostname,
        int $currentTimestamp,
        bool $showAll,
        ?array $worker = null
    ): ?array {
        $jobDetails = json_decode($jobData, true);

        if (!$jobDetails || !isset($jobDetails['data']['command'])) {
            throw new \RuntimeException('malformed reserved-job payload: missing data.command');
        }

        // A record written for an earlier attempt describes a worker that is
        // no longer running this job; the reserved payload's attempt count
        // tells them apart.
        if ($worker !== null && ($worker['attempts'] ?? null) !== (int) ($jobDetails['attempts'] ?? -1)) {
            $worker = null;
        }

        $supervisor = $worker['supervisor'] ?? null;

        // The worker's own record first; then tags, then the supervisor_id property.
        $serverTag = $supervisor !== null
            ? $this->supervisorKey($supervisor)
            : $this->extractServerIdentifier($jobDetails);

        if (!$showAll && $serverTag !== $hostname && $serverTag !== 'unknown') {
            return null;
        }

        $timeout = $jobDetails['timeout'] ?? null;
        $reservedScore = (int) $timestamp;
        $reservationTime = ($worker['started_at'] ?? 0) > 0
            ? $worker['started_at']
            : $reservedScore - $this->resolveRetryAfter();
        $runningFor = max(0, $currentTimestamp - $reservationTime);
        $status = $this->resolveJobStatus($reservedScore, $currentTimestamp);
        $isOrphaned = $this->isJobOrphaned($serverTag, $this->getLiveSupervisorNames(), $supervisor);

        // The timeout the worker enforces, as it recorded it; else worked out
        // from the payload and this process's Horizon config.
        $effectiveTimeout = $worker['timeout'] ?? $this->effectiveTimeout($jobDetails, $supervisor);
        $pastTimeoutAt = $effectiveTimeout > 0
            ? $reservationTime + $effectiveTimeout + $this->releaseMarginSeconds()
            : null;

        // Without a worker record the start time, timeout and orphan status
        // are all inferred, so an orphan alone is not enough: the job must
        // also have expired, which is when Laravel would re-queue it anyway.
        $releasableAt = match (true) {
            $pastTimeoutAt === null => null,
            $worker !== null => $pastTimeoutAt,
            $this->reservationsExpire() => max($pastTimeoutAt, $reservedScore + 1),
            default => null,
        };

        return [
            'job_id' => $jobDetails['uuid'] ?? 'unknown',
            'job_class' => $jobDetails['displayName'] ?? 'Unknown',
            'queue' => $queue,
            'server' => $serverTag,
            'supervisor' => $supervisor,
            'status' => $status,
            'is_orphaned' => $isOrphaned,
            // Offered for release: orphaned (with a worker record) or expired,
            // and past the timeout the worker enforces, plus the margin.
            'is_releasable' => ($status === 'zombie' || ($isOrphaned && $worker !== null))
                && $releasableAt !== null
                && $currentTimestamp >= $releasableAt,
            // When release becomes possible; null when it never will (no
            // timeout, or no worker record and reservations never expire).
            'releasable_at' => $releasableAt,
            'start_time' => date('c', $reservationTime),
            'start_timestamp' => $reservationTime,
            'running_for_seconds' => $runningFor,
            'running_for_formatted' => $this->formatDuration($runningFor),
            'attempts' => $jobDetails['attempts'] ?? 0,
            'timeout' => $timeout,
            'tags' => $jobDetails['tags'] ?? [],
        ];
    }

    /**
     * Extract server identifier using hybrid approach.
     * Tries tags first (Horizon native), falls back to supervisor_id property.
     */
    public function extractServerIdentifier(array $jobDetails): string
    {
        // Method 1: Try Horizon tags (cleaner, native approach)
        if (isset($jobDetails['tags']) && !empty($jobDetails['tags'])) {
            foreach ($jobDetails['tags'] as $tag) {
                if (str_starts_with($tag, 'server:')) {
                    return substr($tag, 7);
                }
            }
        }

        // Method 2: Regex-scan the serialized command for supervisor_id.
        // Safer than unserialize() — no class instantiation, no gadget-chain risk,
        // and works even when the originating job class isn't autoloadable here.
        if (isset($jobDetails['data']['command']) && is_string($jobDetails['data']['command'])) {
            if (preg_match('/s:13:"supervisor_id";s:\d+:"([^"]*)"/', $jobDetails['data']['command'], $matches)) {
                return $matches[1];
            }
        }

        return 'unknown';
    }

    /**
     * Format duration in human-readable format.
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$secs}s";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return "{$hours}h {$mins}m";
    }

    /**
     * Get the default queues to monitor.
     */
    public function getDefaultQueues(): array
    {
        // Check package config first
        if (!empty($this->config['queues'])) {
            return $this->config['queues'];
        }

        // Try to get from Horizon config - check multiple possible structures
        $queues = [];

        // Method 1: Check defaults[gethostname()] (distributed setup).
        // Avoid config() dot-notation because hostnames can contain dots.
        $defaults = config('horizon.defaults', []);
        if (isset($defaults[gethostname()]['queue'])) {
            return (array) $defaults[gethostname()]['queue'];
        }

        // Method 2: Check all supervisors in defaults and collect queues
        foreach ($defaults as $name => $settings) {
            if (!empty($settings['queue'])) {
                $queues = array_merge($queues, (array) $settings['queue']);
            }
        }

        // Method 3: Check environments (production, local, etc.)
        $environments = config('horizon.environments', []);
        foreach ($environments as $env => $supervisors) {
            foreach ($supervisors as $name => $settings) {
                if (!empty($settings['queue'])) {
                    $queues = array_merge($queues, (array) $settings['queue']);
                }
            }
        }

        // Return unique queues or default
        $queues = array_unique($queues);

        return !empty($queues) ? array_values($queues) : ['default'];
    }

    /**
     * Resolve the Redis connection name to query.
     * Prefers explicit package config, falls back to Horizon's own connection,
     * then null (Laravel default).
     */
    public function getRedisConnectionName(): ?string
    {
        return $this->config['redis_connection']
            ?? config('horizon.use')
            ?? null;
    }

    /**
     * Resolve the queue retry_after window (seconds).
     * The reserved sorted-set score is (reservation_time + retry_after);
     * we need retry_after to recover the reservation time.
     *
     * Returns 0 when the queue connection sets retry_after to null: the
     * score is then the reservation time itself. See reservationsExpire().
     */
    public function resolveRetryAfter(): int
    {
        return $this->detectRetryAfter() ?? 0;
    }

    /**
     * Whether reserved jobs ever time out. With `retry_after => null` on the
     * queue connection, Horizon reserves jobs without an expiry and never
     * re-queues them, so no reserved job can be a zombie.
     */
    public function reservationsExpire(): bool
    {
        return $this->detectRetryAfter() !== null;
    }

    /**
     * retry_after lives on a *queue* connection (queue.connections.<name>),
     * while horizon.use names a *Redis* connection (database.redis.<name>),
     * so the two cannot be looked up under the same key. Order:
     *   1. explicit package config,
     *   2. the queue connection Horizon's supervisors run on,
     *   3. any redis-driver queue connection on the Redis connection we read,
     *   4. 90, the value in Laravel's stock config/queue.php.
     *
     * On the connection found in 2 or 3, a missing key means 60 (the default
     * of both Laravel's and Horizon's connectors) and null means disabled.
     *
     * @return int|null  seconds, or null when reservations never expire
     */
    protected function detectRetryAfter(): ?int
    {
        if (isset($this->config['retry_after'])) {
            return (int) $this->config['retry_after'];
        }

        $queueConnections = (array) config('queue.connections', []);
        $redisConnection = $this->getRedisConnectionName() ?? 'default';

        $candidates = collect($this->horizonSupervisors())->pluck('connection')->filter()->values()->all();

        foreach ($queueConnections as $name => $queueConnection) {
            if (is_array($queueConnection)
                && ($queueConnection['driver'] ?? null) === 'redis'
                && ($queueConnection['connection'] ?? 'default') === $redisConnection) {
                $candidates[] = $name;
            }
        }

        foreach (array_unique($candidates) as $name) {
            $queueConnection = $queueConnections[$name] ?? null;

            if (! is_array($queueConnection)) {
                continue;
            }

            if (! array_key_exists('retry_after', $queueConnection)) {
                return 60;
            }

            return $queueConnection['retry_after'] === null ? null : (int) $queueConnection['retry_after'];
        }

        return 90;
    }

    /**
     * The supervisors Horizon provisions in this environment, keyed by
     * supervisor key, with horizon.defaults merged in the way
     * ProvisioningPlan does it. Falls back to the defaults alone when no
     * environment block matches.
     *
     * @return array<string, array<string, mixed>>
     */
    public function horizonSupervisors(): array
    {
        return array_replace_recursive(
            (array) config('horizon.defaults', []),
            $this->environmentSupervisorBlock()
        );
    }

    /**
     * The supervisor block of the first horizon.environments key matching
     * Horizon's environment. Keys may be wildcards (`production*`), and the
     * environment is horizon.env when set, as in `php artisan horizon`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function environmentSupervisorBlock(): array
    {
        $environment = (string) (config('horizon.env') ?? app()->environment());

        foreach ((array) config('horizon.environments', []) as $pattern => $supervisors) {
            if (Str::is((string) $pattern, $environment)) {
                return (array) $supervisors;
            }
        }

        return [];
    }

    /**
     * Compute how long a job has been running given its expiry-score and the current time.
     * Redis stores the score as (reservation_time + retry_after), so we subtract retry_after
     * to recover the actual reservation time.
     */
    public function calculateRunningForSeconds(int $reservedScore, int $currentTimestamp): int
    {
        $reservationTime = $reservedScore - $this->resolveRetryAfter();

        return max(0, $currentTimestamp - $reservationTime);
    }

    /**
     * Classify a reserved job:
     *   - running: reservation is still valid (expiry in the future)
     *   - zombie:  reservation has expired but the job is still in the reserved set,
     *              which means a worker died mid-job or Horizon hasn't reaped it yet.
     *
     * With retry_after disabled the score is the reservation time and never
     * expires, so every reserved job is running.
     */
    public function resolveJobStatus(int $reservedScore, int $currentTimestamp): string
    {
        if (! $this->reservationsExpire()) {
            return 'running';
        }

        return $currentTimestamp > $reservedScore ? 'zombie' : 'running';
    }

    /**
     * Decide whether a reserved job is orphaned: its tagged supervisor is no
     * longer registered as alive. Catches the case where a worker dies while
     * its reservation is still valid (so the zombie heuristic, which only
     * fires after the reservation expires, would miss it).
     *
     * Matching is exact OR suffix on `:<serverTag>` because Horizon stores
     * supervisor names as `<master>:<configKey>` while the package stores
     * the configKey alone in the job's tags.
     *
     * Pass `null` for liveSupervisorNames when the lookup is unavailable;
     * we abstain rather than risk false positives.
     *
     * When the worker recorded its exact supervisor name, that name alone
     * decides: a suffix match would let two hosts sharing a supervisor key
     * vouch for each other.
     *
     * @param  array<int, string>|null  $liveSupervisorNames
     */
    public function isJobOrphaned(string $serverTag, ?array $liveSupervisorNames, ?string $supervisor = null): bool
    {
        if ($liveSupervisorNames === null) {
            return false;
        }

        if ($supervisor !== null) {
            return ! in_array($supervisor, $liveSupervisorNames, true);
        }

        if ($serverTag === 'unknown') {
            return false;
        }

        foreach ($liveSupervisorNames as $name) {
            if ($name === $serverTag || str_ends_with($name, ":{$serverTag}")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Live supervisor names. Horizon scores each member of the `supervisors`
     * ZSET with its last heartbeat time and treats it as alive while that
     * heartbeat is at most 29s old; we use the same window plus the
     * configured grace.
     *
     * Horizon's master, however, deletes a supervisor's entry after only 14s
     * of silence, and the supervisor adds itself back on its next loop. So a
     * supervisor that a live master still lists as managed also counts as
     * live, even while its own entry is missing.
     *
     * Memoized per fetchRunningJobs() call so we don't re-query Redis once
     * per reserved job.
     *
     * Returns null when the underlying Redis call is unavailable (e.g.
     * Horizon's connection isn't bound) — the caller should treat the orphan
     * status as "unknown" in that case.
     *
     * @return array<int, string>|null
     */
    public function getLiveSupervisorNames(): ?array
    {
        if ($this->liveSupervisorsQueried) {
            return $this->liveSupervisorNames;
        }

        $this->liveSupervisorsQueried = true;

        // The grace window is extra margin on top of Horizon's own window,
        // never a replacement for it: flagging a live supervisor's jobs as
        // orphaned invites releasing a job that is still running.
        $grace = (int) ($this->config['supervisor_stale_grace_seconds']
            ?? config('horizon-running-jobs.supervisor_stale_grace_seconds', 5));
        $grace = max(0, $grace);
        $now = time();

        try {
            $redis = $this->getHorizonRedisConnection();

            $names = $redis->zrangebyscore(
                'supervisors',
                $now - SupervisorInspector::SUPERVISOR_ALIVE_SECONDS - $grace,
                '+inf'
            );

            $liveMasters = $redis->zrangebyscore(
                'masters',
                $now - SupervisorInspector::MASTER_ALIVE_SECONDS - $grace,
                '+inf'
            );

            // A non-array reply is a failed lookup, not "nobody is alive".
            if (! is_array($names) || ! is_array($liveMasters)) {
                return $this->liveSupervisorNames = null;
            }

            foreach ($liveMasters as $master) {
                $managed = json_decode((string) $redis->hget("master:{$master}", 'supervisors'), true);

                if (is_array($managed)) {
                    $names = array_merge($names, $managed);
                }
            }

            $this->liveSupervisorNames = array_values(array_unique($names));
        } catch (\Throwable) {
            $this->liveSupervisorNames = null;
        }

        return $this->liveSupervisorNames;
    }

    /**
     * The timeout the worker applies to a job, in seconds: the job's own
     * `timeout`, else the `timeout` option of the supervisor running it
     * (Horizon's default is 60). Without a worker record, the largest timeout
     * of any supervisor, so the answer is never too short. 0 means none.
     *
     * @param  array<string, mixed>  $jobDetails  decoded payload
     */
    public function effectiveTimeout(array $jobDetails, ?string $supervisor = null): int
    {
        if (isset($jobDetails['timeout']) && is_numeric($jobDetails['timeout'])) {
            return max(0, (int) $jobDetails['timeout']);
        }

        $supervisors = $this->horizonSupervisors();
        $key = $supervisor !== null ? $this->supervisorKey($supervisor) : null;

        $options = $key !== null && isset($supervisors[$key])
            ? [$supervisors[$key]]
            : array_values($supervisors);

        $timeouts = array_map(
            fn ($option) => max(0, (int) (is_array($option) ? ($option['timeout'] ?? 60) : 60)),
            $options
        );

        if ($timeouts === []) {
            return 60;
        }

        return in_array(0, $timeouts, true) ? 0 : max($timeouts);
    }

    /**
     * The supervisor key of a Horizon supervisor name. Horizon names
     * supervisors `<master>:<key>` and itself takes everything before the
     * last colon as the master (RedisSupervisorRepository::update()).
     */
    public function supervisorKey(string $supervisor): string
    {
        $colon = strrpos($supervisor, ':');

        return $colon === false ? $supervisor : substr($supervisor, $colon + 1);
    }

    public function tracksWorkers(): bool
    {
        return (bool) ($this->config['track_workers'] ?? true);
    }

    /**
     * JobProcessing listener: inside a Horizon worker, record which
     * supervisor is running the job and since when. Outside one, remove any
     * record left by an earlier attempt, so it cannot describe this one.
     */
    public function recordWorker(mixed $job): void
    {
        if (! $this->tracksWorkers() || ! $job instanceof RedisJob || ! is_string($uuid = $job->uuid()) || $uuid === '') {
            return;
        }

        $supervisor = $this->currentWorkerOption('supervisor');

        try {
            $connection = $job->getRedisQueue()->getConnection();

            if ($supervisor === null) {
                $connection->del(self::WORKER_KEY_PREFIX . $uuid);

                return;
            }

            // The timeout this worker enforces: Worker::timeoutForJob() takes
            // the job's own, else the --timeout Horizon started the worker with.
            $timeout = $job->timeout() ?? $this->currentWorkerOption('timeout');
            $timeout = is_numeric($timeout) ? max(0, (int) $timeout) : null;

            $connection->setex(
                self::WORKER_KEY_PREFIX . $uuid,
                // Outlive the job: it can run until its timeout.
                max(self::WORKER_RECORD_TTL, (int) $timeout + $this->releaseMarginSeconds()),
                json_encode([
                    'supervisor' => $supervisor,
                    'started_at' => time(),
                    'attempts' => $job->attempts(),
                    'timeout' => $timeout,
                ])
            );
        } catch (\Throwable $e) {
            // Monitoring must never fail the job it monitors.
            Log::debug('HorizonRunningJobs: could not record the worker of a job', ['error' => $e->getMessage()]);
        }
    }

    /**
     * JobProcessed / JobFailed / JobReleasedAfterException listener: the job
     * left the reserved set, so its worker record goes too.
     */
    public function forgetWorker(mixed $job): void
    {
        if (! $this->tracksWorkers() || ! $job instanceof RedisJob || ! is_string($uuid = $job->uuid()) || $uuid === '') {
            return;
        }

        try {
            $job->getRedisQueue()->getConnection()->del(self::WORKER_KEY_PREFIX . $uuid);
        } catch (\Throwable $e) {
            Log::debug('HorizonRunningJobs: could not remove the worker record of a job', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Worker records for the given job UUIDs, keyed by UUID. Jobs without a
     * record (not started yet, run outside Horizon, tracking off) are absent.
     * A failed read returns no records: the caller falls back to tags.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, array{supervisor: string, started_at: int, attempts: int|null, timeout: int|null}>
     */
    public function workersFor(array $uuids, mixed $connection = null): array
    {
        $uuids = array_values($uuids);

        if (! $this->tracksWorkers() || $uuids === []) {
            return [];
        }

        try {
            $values = ($connection ?? $this->getRedisConnection())
                ->mget(array_map(fn ($uuid) => self::WORKER_KEY_PREFIX . $uuid, $uuids));
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($uuids as $i => $uuid) {
            $record = is_string($values[$i] ?? null) ? json_decode($values[$i], true) : null;

            if (is_array($record) && is_string($record['supervisor'] ?? null) && $record['supervisor'] !== '') {
                $records[$uuid] = [
                    'supervisor' => $record['supervisor'],
                    'started_at' => (int) ($record['started_at'] ?? 0),
                    'attempts' => isset($record['attempts']) ? (int) $record['attempts'] : null,
                    'timeout' => isset($record['timeout']) ? max(0, (int) $record['timeout']) : null,
                ];
            }
        }

        return $records;
    }

    /**
     * @param  array<int, string>  $payloads
     * @return array<int, string>
     */
    protected function uuidsOf(array $payloads): array
    {
        $uuids = [];
        foreach ($payloads as $payload) {
            $uuid = json_decode((string) $payload, true)['uuid'] ?? null;

            if (is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return array_values(array_unique($uuids));
    }

    /**
     * The supervisor this process works for: Horizon starts every worker as
     * `artisan horizon:work ... --supervisor=<master>:<key>`. Null in any
     * other process.
     */
    protected function currentWorkerSupervisor(): ?string
    {
        return $this->currentWorkerOption('supervisor');
    }

    /**
     * A `--name=value` option of this process, if it is a `horizon:work`
     * worker (QueueCommandString always passes --supervisor and --timeout).
     */
    protected function currentWorkerOption(string $name): ?string
    {
        $argv = (array) ($_SERVER['argv'] ?? []);

        if (! in_array('horizon:work', $argv, true)) {
            return null;
        }

        foreach ($argv as $argument) {
            if (is_string($argument) && str_starts_with($argument, "--{$name}=")) {
                $value = substr($argument, strlen("--{$name}="));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    public function releaseMarginSeconds(): int
    {
        return max(0, (int) ($this->config['release_margin_seconds'] ?? self::RELEASE_MARGIN_SECONDS));
    }

    /**
     * Drop the memoized lookup and read the live supervisors again. Anything
     * that acts on the answer (releasing jobs) must not reuse an earlier one.
     *
     * @return array<int, string>|null
     */
    public function refreshLiveSupervisorNames(): ?array
    {
        $this->liveSupervisorsQueried = false;
        $this->liveSupervisorNames = null;

        return $this->getLiveSupervisorNames();
    }

    protected function getHorizonRedisConnection()
    {
        return app('redis')->connection('horizon');
    }

    /**
     * Get the Redis connection.
     */
    protected function getRedisConnection()
    {
        return Redis::connection($this->getRedisConnectionName());
    }

    /**
     * Build a cache key scoped to the current epoch. Bumping the epoch via
     * clearCache() instantly invalidates every previously-cached entry without
     * needing to enumerate keys or rely on cache tags.
     */
    public function buildCacheKey(string $hostname, bool $showAll, array $queues, bool $orphanedOnly = false): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'horizon_running_jobs';
        $epoch = $this->getCacheEpoch();
        $scope = $showAll ? 'all' : 'local';
        $queueHash = md5(json_encode($queues));
        $orphan = $orphanedOnly ? ':orphan' : '';

        return "{$prefix}:v{$epoch}:{$hostname}:{$scope}:{$queueHash}{$orphan}";
    }

    /**
     * Current cache epoch. Included in every cache key.
     */
    public function getCacheEpoch(): int
    {
        return (int) Cache::get($this->cacheEpochKey(), 0);
    }

    /**
     * Invalidate all cached running-jobs responses by incrementing the epoch.
     */
    public function clearCache(): void
    {
        $next = $this->getCacheEpoch() + 1;
        Cache::forever($this->cacheEpochKey(), $next);
    }

    protected function cacheEpochKey(): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'horizon_running_jobs';

        return "{$prefix}:epoch";
    }

    /**
     * Get statistics about running jobs.
     */
    public function getStats(?array $queues = null): array
    {
        $result = $this->getRunningJobs(null, true, $queues);
        $jobs = $result['jobs'];

        $byServer = [];
        $byQueue = [];
        $byJobClass = [];
        $byStatus = ['running' => 0, 'zombie' => 0];
        $byOrphanStatus = ['orphaned' => 0, 'healthy' => 0];

        foreach ($jobs as $job) {
            $server = $job['server'];
            $queue = $job['queue'];
            $class = $job['job_class'];
            $status = $job['status'] ?? 'running';
            $isOrphaned = ($job['is_orphaned'] ?? false) === true;

            $byServer[$server] = ($byServer[$server] ?? 0) + 1;
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + 1;
            $byJobClass[$class] = ($byJobClass[$class] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $byOrphanStatus[$isOrphaned ? 'orphaned' : 'healthy']++;
        }

        return [
            'total_running' => count($jobs),
            'by_server' => $byServer,
            'by_queue' => $byQueue,
            'by_job_class' => $byJobClass,
            'by_status' => $byStatus,
            'by_orphan_status' => $byOrphanStatus,
            'dropped_count' => $result['dropped_count'] ?? 0,
            'orphan_count' => $result['orphan_count'] ?? 0,
            'longest_running' => $jobs[0] ?? null,
            'warnings' => $result['warnings'],
        ];
    }
}

