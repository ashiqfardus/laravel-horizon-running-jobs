<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Illuminate\Console\Command;

class DiagnoseCommand extends Command
{
    protected $signature = 'horizon:diagnose
                            {--json : Output as JSON}';

    protected $description = 'Run a unified health check across supervisors, jobs, and queue depths';

    public function handle(
        SupervisorInspector $supervisors,
        RunningJobsManager $manager,
        QueueDepthInspector $queues
    ): int {
        $stats = $manager->getStats();
        $checks = [
            $this->checkSupervisors($supervisors->inspect()),
            $this->checkOrphanedJobs($stats),
            $this->checkZombieJobs($stats),
            $this->checkMalformedJobs($stats),
            $this->checkQueueDepths($queues->inspect()),
        ];

        $overall = $this->aggregateStatus($checks);
        $exitCode = $overall === 'fail' ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line(json_encode([
                'checks' => $checks,
                'summary' => $this->summarize($checks),
                'overall_status' => $overall,
            ], JSON_PRETTY_PRINT));
            return $exitCode;
        }

        $this->info('🔍 Horizon Health Diagnosis');
        $this->newLine();

        foreach ($checks as $check) {
            $marker = match ($check['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>⚠</>',
                'fail' => '<fg=red>✗</>',
                default => ' ',
            };
            $this->line(sprintf('  %s  %-22s %s', $marker, $check['name'], $check['message']));
        }

        $this->newLine();
        $label = strtoupper($overall);
        $color = match ($overall) {
            'pass' => 'green',
            'warn' => 'yellow',
            'fail' => 'red',
        };
        $this->line("<fg={$color}>Status: {$label}</>");

        return $exitCode;
    }

    /** @return array{name: string, status: string, message: string} */
    protected function checkSupervisors(array $payload): array
    {
        $supervisors = $payload['supervisors'] ?? [];

        // FAIL only when the ZSET has no entries at all — Horizon was never
        // started or has been gone long enough for entries to be reaped.
        // A registered-but-momentarily-stale entry warns rather than fails,
        // since Horizon's heartbeat can lag a second between refreshes on a
        // healthy system.
        if (empty($supervisors)) {
            return [
                'name' => 'horizon.supervisors',
                'status' => 'fail',
                'message' => 'No live Horizon supervisor — Horizon may be down',
            ];
        }

        $live = array_filter($supervisors, fn ($s) => empty($s['is_stale']));
        $stale = array_filter($supervisors, fn ($s) => ! empty($s['is_stale']));

        if (empty($live)) {
            return [
                'name' => 'horizon.supervisors',
                'status' => 'warn',
                'message' => sprintf(
                    '%d supervisor(s) registered but all stale — Horizon master may have died',
                    count($stale)
                ),
            ];
        }

        if (! empty($stale)) {
            return [
                'name' => 'horizon.supervisors',
                'status' => 'warn',
                'message' => sprintf(
                    '%d running, %d stale (registration expired but not reaped)',
                    count($live),
                    count($stale)
                ),
            ];
        }

        return [
            'name' => 'horizon.supervisors',
            'status' => 'pass',
            'message' => sprintf('%d supervisor(s) running', count($live)),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    protected function checkOrphanedJobs(array $stats): array
    {
        $count = $stats['orphan_count'] ?? 0;

        return [
            'name' => 'jobs.orphaned',
            'status' => $count > 0 ? 'warn' : 'pass',
            'message' => $count > 0
                ? "{$count} orphan job(s) — see `horizon:running-jobs --orphaned`"
                : '0 orphan jobs',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    protected function checkZombieJobs(array $stats): array
    {
        $count = $stats['by_status']['zombie'] ?? 0;

        return [
            'name' => 'jobs.zombies',
            'status' => $count > 0 ? 'warn' : 'pass',
            'message' => $count > 0
                ? "{$count} zombie job(s) — reservation expired but still in queue"
                : '0 zombie jobs',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    protected function checkMalformedJobs(array $stats): array
    {
        $count = $stats['dropped_count'] ?? 0;

        return [
            'name' => 'jobs.malformed',
            'status' => $count > 0 ? 'warn' : 'pass',
            'message' => $count > 0
                ? "{$count} malformed reserved-set entries dropped (see Log::warning)"
                : '0 malformed entries',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    protected function checkQueueDepths(array $payload): array
    {
        $queues = $payload['queues'] ?? [];
        if (empty($queues)) {
            return [
                'name' => 'queues.depths',
                'status' => 'pass',
                'message' => 'No queues configured',
            ];
        }

        $highest = array_reduce(
            $queues,
            fn ($carry, $q) => $carry === null || $q['pending'] > $carry['pending'] ? $q : $carry
        );

        return [
            'name' => 'queues.depths',
            'status' => 'pass',
            'message' => sprintf(
                'highest pending: %s (%d), totals: pending=%d reserved=%d delayed=%d',
                $highest['queue'],
                $highest['pending'],
                $payload['totals']['pending'] ?? 0,
                $payload['totals']['reserved'] ?? 0,
                $payload['totals']['delayed'] ?? 0,
            ),
        ];
    }

    protected function aggregateStatus(array $checks): string
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') return 'fail';
        }
        foreach ($checks as $c) {
            if ($c['status'] === 'warn') return 'warn';
        }
        return 'pass';
    }

    protected function summarize(array $checks): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $counts[$c['status']]++;
        }
        return $counts;
    }
}
