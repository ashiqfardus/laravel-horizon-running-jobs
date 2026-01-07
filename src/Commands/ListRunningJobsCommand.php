<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;

class ListRunningJobsCommand extends Command
{
    protected $signature = 'horizon:running-jobs
                            {--queue=* : Specific queues to monitor (default: from horizon config)}
                            {--limit=100 : Maximum jobs to display}
                            {--all : Show jobs from all servers}
                            {--json : Output as JSON}
                            {--stats : Show statistics instead of job list}';

    protected $description = 'List jobs currently running in Horizon';

    public function __construct(
        protected RunningJobsManager $manager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $hostname = gethostname();
            $showAll = $this->option('all');
            $limit = min((int) $this->option('limit'), config('horizon-running-jobs.max_jobs', 1000));
            $asJson = $this->option('json');
            $showStats = $this->option('stats');
            $isDistributed = $this->manager->isDistributed();

            // In non-distributed mode, always show all
            if (!$isDistributed) {
                $showAll = true;
            }

            // Check if Horizon is running
            if (!$this->isHorizonRunning()) {
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
                $showAll ? null : $hostname,
                $showAll,
                $queues
            );

            // JSON output
            if ($asJson) {
                $this->line(json_encode([
                    'hostname' => $hostname,
                    'distributed' => $isDistributed,
                    'show_all' => $showAll,
                    'queues' => $queues,
                    'running_jobs_count' => count($result['jobs']),
                    'jobs' => array_slice($result['jobs'], 0, $limit),
                    'warnings' => $result['warnings'],
                ], JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            // Display header
            $this->info("🔍 Scanning queues: " . implode(', ', $queues));
            if ($isDistributed) {
                $this->info("📍 Current host: {$hostname}");
                if ($showAll) {
                    $this->info("🌐 Showing jobs from ALL servers");
                }
            }
            $this->newLine();

            $jobs = $result['jobs'];

            if (empty($jobs)) {
                $message = $isDistributed && !$showAll
                    ? "✓ No jobs currently running on {$hostname}"
                    : "✓ No jobs currently running";
                $this->info($message);
                return self::SUCCESS;
            }

            // Sort by start time (oldest first for display)
            usort($jobs, fn($a, $b) => $a['start_timestamp'] <=> $b['start_timestamp']);

            // Limit display
            $displayJobs = array_slice($jobs, 0, $limit);

            // Format for display
            $tableData = array_map(function ($job) {
                return [
                    'ID' => substr($job['job_id'], 0, 8) . '...',
                    'Job' => $this->truncate($job['job_class'], 35),
                    'Queue' => $job['queue'],
                    'Server' => $this->truncate($job['server'], 20),
                    'Started' => date('H:i:s', $job['start_timestamp']),
                    'Duration' => $job['running_for_formatted'],
                    'Attempts' => $job['attempts'],
                ];
            }, $displayJobs);

            $this->table(
                ['ID', 'Job', 'Queue', 'Server', 'Started', 'Duration', 'Attempts'],
                $tableData
            );

            $total = count($jobs);
            $this->info("✓ Found {$total} running job(s)");

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
        $this->newLine();

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
     * Check if Horizon is running.
     */
    protected function isHorizonRunning(): bool
    {
        try {
            Artisan::call('horizon:status');
            $output = trim(Artisan::output());
            return str_contains($output, 'Horizon is running');
        } catch (\Exception $e) {
            return false;
        }
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

