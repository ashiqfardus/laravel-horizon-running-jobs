<?php

namespace Ashiqfardus\HorizonRunningJobs;

/**
 * Pure diagnostic logic shared between the CLI `horizon:diagnose` command
 * and the Blade `<x-horizon-running-jobs::diagnose-banner />` component.
 *
 * Each check returns an array with `name`, `status` (pass | warn | fail),
 * and `message`. The aggregate status follows the worst individual check.
 */
class HealthDiagnoser
{
    public function __construct(
        protected SupervisorInspector $supervisors,
        protected RunningJobsManager $manager,
        protected QueueDepthInspector $queues
    ) {
    }

    /**
     * @return array{
     *     checks: array<int, array{name: string, status: string, message: string}>,
     *     overall_status: string,
     *     summary: array{pass: int, warn: int, fail: int}
     * }
     */
    public function diagnose(): array
    {
        $stats = $this->manager->getStats();
        $checks = [
            $this->checkSupervisors($this->supervisors->inspect()),
            $this->checkOrphanedJobs($stats),
            $this->checkZombieJobs($stats),
            $this->checkMalformedJobs($stats),
            $this->checkQueueDepths($this->queues->inspect()),
        ];

        return [
            'checks' => $checks,
            'overall_status' => $this->aggregateStatus($checks),
            'summary' => $this->summarize($checks),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    public function checkSupervisors(array $payload): array
    {
        $supervisors = $payload['supervisors'] ?? [];

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
    public function checkOrphanedJobs(array $stats): array
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
    public function checkZombieJobs(array $stats): array
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
    public function checkMalformedJobs(array $stats): array
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
    public function checkQueueDepths(array $payload): array
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

    public function aggregateStatus(array $checks): string
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') return 'fail';
        }
        foreach ($checks as $c) {
            if ($c['status'] === 'warn') return 'warn';
        }
        return 'pass';
    }

    /** @return array{pass: int, warn: int, fail: int} */
    public function summarize(array $checks): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $counts[$c['status']]++;
        }
        return $counts;
    }
}
