<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\Concerns\HandlesJsonErrors;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class RunningJobsController extends Controller
{
    use HandlesJsonErrors;

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
            $orphanedOnly = filter_var(request()->query('orphaned', false), FILTER_VALIDATE_BOOLEAN);

            $queues = $this->resolveQueues();
            if ($queues instanceof JsonResponse) {
                return $queues;
            }

            $result = $this->manager->getRunningJobs($hostname, $showAll, $queues, $orphanedOnly);

            return response()->json([
                'success' => true,
                'hostname' => $hostname,
                'timestamp' => now()->toIso8601String(),
                'queues_monitored' => $queues,
                'orphaned_only' => $orphanedOnly,
                'running_jobs_count' => count($result['jobs']),
                'total_count' => $result['total_count'],
                'dropped_count' => $result['dropped_count'] ?? 0,
                'orphan_count' => $result['orphan_count'] ?? 0,
                'jobs' => $result['jobs'],
                'warnings' => $result['warnings'],
            ]);

        } catch (\Throwable $e) {
            return $this->jsonError($e, 'Failed to fetch running jobs');
        }
    }

    /**
     * Get statistics about running jobs.
     */
    public function stats(): JsonResponse
    {
        try {
            $queues = $this->resolveQueues();
            if ($queues instanceof JsonResponse) {
                return $queues;
            }

            $stats = $this->manager->getStats($queues);

            return response()->json([
                'success' => true,
                'timestamp' => now()->toIso8601String(),
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e, 'Failed to fetch statistics');
        }
    }

    /**
     * Resolve queues from the ?queues= comma-separated query param, validating
     * each name against a strict allowlist. Returns either an array of queue
     * names or a JsonResponse with status 422 when input is invalid.
     *
     * Empty / missing param falls back to the manager's auto-detected queues
     * so CLI and HTTP stay consistent.
     *
     * @return array<int, string>|JsonResponse
     */
    protected function resolveQueues(): array|JsonResponse
    {
        $queuesParam = request()->query('queues');

        if (! $queuesParam) {
            return $this->manager->getDefaultQueues();
        }

        $names = array_values(array_filter(array_map('trim', explode(',', (string) $queuesParam))));

        if (empty($names)) {
            return $this->manager->getDefaultQueues();
        }

        if (count($names) > 20) {
            return $this->validationError('At most 20 queues may be specified per request.');
        }

        // Conservative allowlist matching common queue-naming patterns.
        // Loosen here only if a real-world need surfaces.
        $pattern = '/^[A-Za-z0-9_:.\-]+$/';

        foreach ($names as $name) {
            if (! preg_match($pattern, $name)) {
                return $this->validationError(
                    "Invalid queue name: \"{$name}\". Allowed: alphanumeric, underscore, colon, dot, dash."
                );
            }
        }

        return $names;
    }

    protected function validationError(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'Invalid queue parameter',
            'message' => $message,
        ], 422);
    }
}

