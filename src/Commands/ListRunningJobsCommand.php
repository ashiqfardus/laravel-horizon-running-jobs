<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\Concerns\IsWatchable;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Illuminate\Console\Command;

class ListRunningJobsCommand extends Command
{
    use IsWatchable;

    protected $signature = 'horizon:running-jobs
                            {--queue=* : Specific queues to monitor (default: from horizon config)}
                            {--limit=100 : Maximum jobs to display}
                            {--all : Show jobs from all servers}
                            {--orphaned : Restrict to orphaned jobs (worker process is no longer registered)}
                            {--json : Output as JSON}
                            {--stats : Show statistics instead of job list}
                            {--watch= : Re-render every N seconds (default 3, Ctrl-C to exit). Ignored with --json.}';

    protected $description = 'List jobs currently running in Horizon';

    public function __construct(
        protected RunningJobsManager $manager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->isWatchMode() && ! $this->option('json')) {
            return $this->runInWatchMode(fn () => $this->renderOnce());
        }

        return $this->renderOnce();
    }

    protected function renderOnce(): int
    {
        try {
            $serverId = $this->manager->getServerIdentifier();
            $showAll = $this->option('all');
            $limit = min((int) $this->option('limit'), config('horizon-running-jobs.max_jobs', 1000));
            $asJson = $this->option('json');
            $showStats = $this->option('stats');
            $orphanedOnly = (bool) $this->option('orphaned');
            $isDistributed = $this->manager->isDistributed();

            // In non-distributed mode, always show all
            if (!$isDistributed) {
                $showAll = true;
            }

            // Check if Horizon is running
            if (!$this->manager->isHorizonRunning()) {
                $this->warn('⚠️  Horizon is not running. No jobs will be listed.');
                return self::FAILURE;
            }

            // Get queues
            $queues = $this->getQueuesToMonitor();

            // Show stats mode
            if ($showStats) {
                return $this->showStats($queues, $asJson);
            }

            // Get running jobs
            $result = $this->manager->getRunningJobs(
                $showAll ? null : $serverId,
                $showAll,
                $queues,
                $orphanedOnly
            );

            $totalCount = $result['total_count'] ?? count($result['jobs']);
            $displayedJobs = array_slice($result['jobs'], 0, $limit);

            // JSON output
            if ($asJson) {
                $this->line(json_encode([
                    'server_id' => $serverId,
                    'distributed' => $isDistributed,
                    'show_all' => $showAll,
                    'orphaned_only' => $orphanedOnly,
                    'queues' => $queues,
                    'shown_count' => count($displayedJobs),
                    'total_count' => $totalCount,
                    'orphan_count' => $result['orphan_count'] ?? 0,
                    'truncated' => count($displayedJobs) < $totalCount,
                    'jobs' => $displayedJobs,
                    'warnings' => $result['warnings'],
                ], JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            // Display header
            $this->info("🔍 Scanning queues: " . implode(', ', $queues));
            if ($isDistributed) {
                $this->info("📍 Current server: {$serverId}");
                if ($showAll) {
                    $this->info("🌐 Showing jobs from ALL servers");
                }
            }
            $this->newLine();

            $jobs = $result['jobs'];

            if (empty($jobs)) {
                $message = $isDistributed && !$showAll
                    ? "✓ No jobs currently running on {$serverId}"
                    : "✓ No jobs currently running";
                $this->info($message);
                return self::SUCCESS;
            }

            // Sort by start time (oldest first for display)
            usort($jobs, fn($a, $b) => $a['start_timestamp'] <=> $b['start_timestamp']);

            // Limit display
            $displayJobs = array_slice($jobs, 0, $limit);
            $shownCount = count($displayJobs);

            // Format for display
            $tableData = array_map(function ($job) {
                $status = $job['status'] ?? 'running';
                $orphaned = ($job['is_orphaned'] ?? false) === true;

                $marker = match (true) {
                    $orphaned && $status === 'zombie' => '⚠ orphan+zombie',
                    $orphaned => '⚠ orphan',
                    $status === 'zombie' => '⚠ zombie',
                    default => 'running',
                };

                return [
                    'ID' => substr($job['job_id'], 0, 8) . '...',
                    'Job' => $this->truncate($job['job_class'], 35),
                    'Queue' => $job['queue'],
                    'Server' => $this->truncate($job['server'], 20),
                    'Status' => $marker,
                    'Started' => date('H:i:s', $job['start_timestamp']),
                    'Duration' => $job['running_for_formatted'],
                    'Attempts' => $job['attempts'],
                ];
            }, $displayJobs);

            $this->table(
                ['ID', 'Job', 'Queue', 'Server', 'Status', 'Started', 'Duration', 'Attempts'],
                $tableData
            );

            if ($shownCount < $totalCount) {
                $this->info("✓ Showing {$shownCount} of {$totalCount} running job(s)");
            } else {
                $this->info("✓ Found {$totalCount} running job(s)");
            }

            // Display warnings
            foreach ($result['warnings'] as $warning) {
                $this->warn("⚠️  {$warning}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Show statistics about running jobs.
     */
    protected function showStats(array $queues, bool $asJson): int
    {
        $stats = $this->manager->getStats($queues);

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("📊 Running Jobs Statistics");
        $this->newLine();

        $this->info("Total Running: {$stats['total_running']}");
        if (($stats['orphan_count'] ?? 0) > 0) {
            $this->warn("Orphaned (worker dead): {$stats['orphan_count']}");
        }
        if (($stats['dropped_count'] ?? 0) > 0) {
            $this->warn("Dropped (malformed): {$stats['dropped_count']}");
        }
        $this->newLine();

        if (!empty($stats['by_status'])) {
            $this->info("By Status:");
            foreach ($stats['by_status'] as $status => $count) {
                $marker = $status === 'zombie' && $count > 0 ? ' ⚠' : '';
                $this->line("  • {$status}: {$count}{$marker}");
            }
            $this->newLine();
        }

        if (!empty($stats['by_orphan_status'])) {
            $this->info("By Orphan Status:");
            foreach ($stats['by_orphan_status'] as $status => $count) {
                $marker = $status === 'orphaned' && $count > 0 ? ' ⚠' : '';
                $this->line("  • {$status}: {$count}{$marker}");
            }
            $this->newLine();
        }

        if (!empty($stats['by_server'])) {
            $this->info("By Server:");
            foreach ($stats['by_server'] as $server => $count) {
                $this->line("  • {$server}: {$count}");
            }
            $this->newLine();
        }

        if (!empty($stats['by_queue'])) {
            $this->info("By Queue:");
            foreach ($stats['by_queue'] as $queue => $count) {
                $this->line("  • {$queue}: {$count}");
            }
            $this->newLine();
        }

        if (!empty($stats['by_job_class'])) {
            $this->info("By Job Class:");
            foreach ($stats['by_job_class'] as $class => $count) {
                $this->line("  • {$class}: {$count}");
            }
            $this->newLine();
        }

        if ($stats['longest_running']) {
            $longest = $stats['longest_running'];
            $this->info("Longest Running:");
            $this->line("  • {$longest['job_class']} on {$longest['server']}");
            $this->line("  • Duration: {$longest['running_for_formatted']}");
        }

        foreach ($stats['warnings'] as $warning) {
            $this->warn("⚠️  {$warning}");
        }

        return self::SUCCESS;
    }

    /**
     * Get queues to monitor from options or config.
     */
    protected function getQueuesToMonitor(): array
    {
        $optionQueues = $this->option('queue');

        if (!empty($optionQueues)) {
            return $optionQueues;
        }

        // Get from package config
        $configQueues = config('horizon-running-jobs.queues');
        if (!empty($configQueues)) {
            return $configQueues;
        }

        // Try to get from Horizon config - check multiple possible structures
        $queues = [];

        // Method 1: Check defaults.{hostname} (distributed setup)
        $supervisor = config('horizon.defaults.' . gethostname(), []);
        if (!empty($supervisor['queue'])) {
            return (array) $supervisor['queue'];
        }

        // Method 2: Check all supervisors in defaults
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
     * Truncate text to specified length.
     */
    protected function truncate(string $text, int $length): string
    {
        return strlen($text) > $length
            ? substr($text, 0, $length - 3) . '...'
            : $text;
    }
}

