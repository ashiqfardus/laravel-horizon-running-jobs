<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;

class RunningJobsController extends Controller
{
    public function __construct(
        protected RunningJobsManager $manager
    ) {}

    /**
     * List running jobs.
     */
    public function index(): JsonResponse
    {
        try {
            $hostname = request()->query('hostname') ?: $this->manager->getServerIdentifier();
            $showAll = filter_var(request()->query('all', false), FILTER_VALIDATE_BOOLEAN);
            $queues = $this->getQueues();

            $result = $this->manager->getRunningJobs($hostname, $showAll, $queues);

            return response()->json([
                'success' => true,
                'hostname' => $hostname,
                'timestamp' => now()->toIso8601String(),
                'queues_monitored' => $queues,
                'running_jobs_count' => count($result['jobs']),
                'total_count' => $result['total_count'],
                'dropped_count' => $result['dropped_count'] ?? 0,
                'jobs' => $result['jobs'],
                'warnings' => $result['warnings'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch running jobs',
                'message' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get statistics about running jobs.
     */
    public function stats(): JsonResponse
    {
        try {
            $queues = $this->getQueues();
            $stats = $this->manager->getStats($queues);

            return response()->json([
                'success' => true,
                'timestamp' => now()->toIso8601String(),
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch statistics',
                'message' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get queues from request (comma-separated) or fall back to the manager's
     * auto-detection so CLI and HTTP stay consistent.
     */
    protected function getQueues(): array
    {
        $queuesParam = request()->query('queues');

        if ($queuesParam) {
            return array_values(array_filter(array_map('trim', explode(',', $queuesParam))));
        }

        return $this->manager->getDefaultQueues();
    }
}

