<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\Concerns\HandlesJsonErrors;
use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class QueuesController extends Controller
{
    use HandlesJsonErrors;

    public function __construct(
        protected QueueDepthInspector $inspector
    ) {
    }

    public function index(): JsonResponse
    {
        try {
            $queues = $this->resolveQueues();
            if ($queues instanceof JsonResponse) {
                return $queues;
            }

            $result = $this->inspector->inspect($queues);

            return response()->json([
                'success' => true,
                'inspected_at' => $result['inspected_at'],
                'queue_count' => count($result['queues']),
                'totals' => $result['totals'],
                'queues' => $result['queues'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e, 'Failed to inspect queue depths');
        }
    }

    /**
     * Validate the optional ?queues= comma-separated query param.
     * Returns the parsed list, a JsonResponse on validation failure, or null
     * to signal "no override — let the inspector use its default-queue source".
     *
     * @return array<int, string>|JsonResponse|null
     */
    protected function resolveQueues(): array|JsonResponse|null
    {
        $queuesParam = request()->query('queues');
        if (! $queuesParam) {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', (string) $queuesParam))));
        if (empty($names)) {
            return null;
        }

        if (count($names) > 20) {
            return $this->validationError('At most 20 queues may be specified per request.');
        }

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
