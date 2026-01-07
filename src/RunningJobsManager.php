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
            $cacheKey = $this->getCacheKey($serverId, $showAll, $queues);
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
     * Get the server identifier for this server.
     * Auto-detects from Horizon config if not explicitly set.
     */
    public function getServerIdentifier(): string
    {
        // If explicitly configured, use that
        if (!empty($this->config['server_identifier'])) {
            return $this->config['server_identifier'];
        }

        // Try to auto-detect from Horizon config
        return $this->detectServerIdentifierFromHorizon();
    }

    /**
     * Detect server identifier from Horizon configuration.
     * Checks if current hostname matches any supervisor key in Horizon config.
     */
    protected function detectServerIdentifierFromHorizon(): string
    {
        $hostname = gethostname();

        // Check horizon.defaults for matching key
        $defaults = config('horizon.defaults', []);
        if (array_key_exists($hostname, $defaults)) {
            return $hostname;
        }

        // Check horizon.environments.{current_env} for matching key
        $currentEnv = app()->environment();
        $envConfig = config("horizon.environments.{$currentEnv}", []);
        if (array_key_exists($hostname, $envConfig)) {
            return $hostname;
        }

        // Check all environments for matching key
        $environments = config('horizon.environments', []);
        foreach ($environments as $env => $supervisors) {
            if (array_key_exists($hostname, $supervisors)) {
                return $hostname;
            }
        }

        // Fallback to hostname
        return $hostname;
    }

    /**
     * Fetch running jobs directly from Redis.
     */
    protected function fetchRunningJobs(string $hostname, bool $showAll, array $queues): array
    {
        $allJobs = [];
        $warnings = [];
        $currentTimestamp = time();
        $maxJobs = $this->config['max_jobs'] ?? 1000;
        $longRunningThreshold = $this->config['long_running_threshold'] ?? 300;

        foreach ($queues as $queue) {
            try {
                $jobs = $this->getJobsForQueue($queue, $hostname, $showAll, $currentTimestamp, $maxJobs);
                $allJobs = array_merge($allJobs, $jobs);
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

        return [
            'jobs' => array_slice($allJobs, 0, $maxJobs),
            'warnings' => $warnings,
            'total_count' => count($allJobs),
        ];
    }

    /**
     * Get running jobs for a specific queue.
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
            return [];
        }

        $reservedJobs = $redis->zrange($key, 0, $maxJobs - 1, ['WITHSCORES' => true]);
        $jobs = [];

        foreach ($reservedJobs as $jobData => $timestamp) {
            try {
                $job = $this->parseJobData($jobData, $timestamp, $queue, $hostname, $currentTimestamp, $showAll);

                if ($job !== null) {
                    $jobs[] = $job;
                }
            } catch (\Exception $e) {
                // Skip malformed jobs
                continue;
            }
        }

        return $jobs;
    }

    /**
     * Parse job data from Redis.
     */
    protected function parseJobData(
        string $jobData,
        float $timestamp,
        string $queue,
        string $hostname,
        int $currentTimestamp,
        bool $showAll
    ): ?array {
        $jobDetails = json_decode($jobData, true);

        if (!$jobDetails || !isset($jobDetails['data']['command'])) {
            return null;
        }

        // HYBRID APPROACH: Try tags first, fall back to supervisor_id
        $serverTag = $this->extractServerIdentifier($jobDetails);

        if (!$showAll && $serverTag !== $hostname && $serverTag !== 'unknown') {
            return null;
        }

        // Validate timeout
        $timeout = $jobDetails['timeout'] ?? null;
        if ($timeout !== null && $currentTimestamp > ($timestamp + $timeout)) {
            return null;
        }

        $runningFor = $currentTimestamp - (int) $timestamp;

        return [
            'job_id' => $jobDetails['uuid'] ?? 'unknown',
            'job_class' => $jobDetails['displayName'] ?? 'Unknown',
            'queue' => $queue,
            'server' => $serverTag,
            'start_time' => date('c', (int) $timestamp),
            'start_timestamp' => (int) $timestamp,
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

        // Method 2: Fallback to supervisor_id property (guaranteed to work)
        if (isset($jobDetails['data']['command'])) {
            try {
                $unserialized = @unserialize($jobDetails['data']['command']);

                if ($unserialized && isset($unserialized->supervisor_id)) {
                    return $unserialized->supervisor_id;
                }
            } catch (\Exception $e) {
                // Unserialize failed
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
    protected function getDefaultQueues(): array
    {
        // Check package config first
        if (!empty($this->config['queues'])) {
            return $this->config['queues'];
        }

        // Try to get from Horizon config - check multiple possible structures
        $queues = [];

        // Method 1: Check defaults.{hostname} (distributed setup)
        $supervisor = config('horizon.defaults.' . gethostname(), []);
        if (!empty($supervisor['queue'])) {
            return (array) $supervisor['queue'];
        }

        // Method 2: Check all supervisors in defaults and collect queues
        $defaults = config('horizon.defaults', []);
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
     * Get the Redis connection.
     */
    protected function getRedisConnection()
    {
        $connection = $this->config['redis_connection'] ?? null;
        return Redis::connection($connection);
    }

    /**
     * Generate cache key.
     */
    protected function getCacheKey(string $hostname, bool $showAll, array $queues): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'horizon_running_jobs';
        $scope = $showAll ? 'all' : 'local';
        $queueHash = md5(json_encode($queues));

        return "{$prefix}:{$hostname}:{$scope}:{$queueHash}";
    }

    /**
     * Clear the running jobs cache.
     */
    public function clearCache(): void
    {
        $prefix = $this->config['cache']['prefix'] ?? 'horizon_running_jobs';
        // Note: This requires cache driver that supports tags or manual key management
        Cache::forget($prefix . ':*');
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

        foreach ($jobs as $job) {
            $server = $job['server'];
            $queue = $job['queue'];
            $class = $job['job_class'];

            $byServer[$server] = ($byServer[$server] ?? 0) + 1;
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + 1;
            $byJobClass[$class] = ($byJobClass[$class] ?? 0) + 1;
        }

        return [
            'total_running' => count($jobs),
            'by_server' => $byServer,
            'by_queue' => $byQueue,
            'by_job_class' => $byJobClass,
            'longest_running' => $jobs[0] ?? null,
            'warnings' => $result['warnings'],
        ];
    }
}

