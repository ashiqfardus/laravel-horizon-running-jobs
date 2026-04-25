<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunningJobsManager
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get all running jobs.
     *
     * @param string|null $serverId Filter by server identifier (null = current server)
     * @param bool $showAll Show jobs from all servers
     * @param array|null $queues Specific queues to check
     * @return array
     */
    public function getRunningJobs(
        ?string $serverId = null,
        bool $showAll = false,
        ?array $queues = null
    ): array {
        $serverId = $serverId ?? $this->getServerIdentifier();
        $queues = $queues ?? $this->getDefaultQueues();

        // If not in distributed mode, always show all jobs
        if (!$this->isDistributed()) {
            $showAll = true;
        }

        // Use caching if enabled
        if ($this->config['cache']['enabled'] ?? false) {
            $cacheKey = $this->buildCacheKey($serverId, $showAll, $queues);
            $ttl = $this->config['cache']['ttl'] ?? 10;

            return Cache::remember($cacheKey, $ttl, function () use ($serverId, $showAll, $queues) {
                return $this->fetchRunningJobs($serverId, $showAll, $queues);
            });
        }

        return $this->fetchRunningJobs($serverId, $showAll, $queues);
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
        $currentEnv = app()->environment();
        $envSupervisors = config("horizon.environments.{$currentEnv}", []);

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
    protected function fetchRunningJobs(string $hostname, bool $showAll, array $queues): array
    {
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

        if ($droppedCount > 0) {
            $warnings[] = "{$droppedCount} malformed job(s) skipped (see logs)";
        }

        return [
            'jobs' => array_slice($allJobs, 0, $maxJobs),
            'warnings' => $warnings,
            'total_count' => $totalReserved,
            'dropped_count' => $droppedCount,
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
        $jobs = [];
        $dropped = 0;

        foreach ($reservedJobs as $jobData => $timestamp) {
            try {
                $job = $this->parseJobData($jobData, $timestamp, $queue, $hostname, $currentTimestamp, $showAll);

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
     */
    public function parseJobData(
        string $jobData,
        float $timestamp,
        string $queue,
        string $hostname,
        int $currentTimestamp,
        bool $showAll
    ): ?array {
        $jobDetails = json_decode($jobData, true);

        if (!$jobDetails || !isset($jobDetails['data']['command'])) {
            throw new \RuntimeException('malformed reserved-job payload: missing data.command');
        }

        // HYBRID APPROACH: Try tags first, fall back to supervisor_id
        $serverTag = $this->extractServerIdentifier($jobDetails);

        if (!$showAll && $serverTag !== $hostname && $serverTag !== 'unknown') {
            return null;
        }

        $timeout = $jobDetails['timeout'] ?? null;
        $reservedScore = (int) $timestamp;
        $reservationTime = $reservedScore - $this->resolveRetryAfter();
        $runningFor = $this->calculateRunningForSeconds($reservedScore, $currentTimestamp);

        return [
            'job_id' => $jobDetails['uuid'] ?? 'unknown',
            'job_class' => $jobDetails['displayName'] ?? 'Unknown',
            'queue' => $queue,
            'server' => $serverTag,
            'status' => $this->resolveJobStatus($reservedScore, $currentTimestamp),
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
     */
    public function resolveRetryAfter(): int
    {
        if (isset($this->config['retry_after']) && $this->config['retry_after'] !== null) {
            return (int) $this->config['retry_after'];
        }

        $queueConnection = config('horizon.use') ?? 'redis';
        $retryAfter = config("queue.connections.{$queueConnection}.retry_after");

        return $retryAfter !== null ? (int) $retryAfter : 90;
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
     */
    public function resolveJobStatus(int $reservedScore, int $currentTimestamp): string
    {
        return $currentTimestamp > $reservedScore ? 'zombie' : 'running';
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
    public function buildCacheKey(string $hostname, bool $showAll, array $queues): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'horizon_running_jobs';
        $epoch = $this->getCacheEpoch();
        $scope = $showAll ? 'all' : 'local';
        $queueHash = md5(json_encode($queues));

        return "{$prefix}:v{$epoch}:{$hostname}:{$scope}:{$queueHash}";
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

        foreach ($jobs as $job) {
            $server = $job['server'];
            $queue = $job['queue'];
            $class = $job['job_class'];
            $status = $job['status'] ?? 'running';

            $byServer[$server] = ($byServer[$server] ?? 0) + 1;
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + 1;
            $byJobClass[$class] = ($byJobClass[$class] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'total_running' => count($jobs),
            'by_server' => $byServer,
            'by_queue' => $byQueue,
            'by_job_class' => $byJobClass,
            'by_status' => $byStatus,
            'dropped_count' => $result['dropped_count'] ?? 0,
            'longest_running' => $jobs[0] ?? null,
            'warnings' => $result['warnings'],
        ];
    }
}

