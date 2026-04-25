<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\Concerns\HandlesJsonErrors;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SupervisorsController extends Controller
{
    use HandlesJsonErrors;

    public function __construct(
        protected SupervisorInspector $inspector
    ) {
    }

    public function index(): JsonResponse
    {
        try {
            $result = $this->inspector->inspect();

            return response()->json([
                'success' => true,
                'inspected_at' => $result['inspected_at'],
                'supervisor_count' => count($result['supervisors']),
                'master_count' => count($result['masters']),
                'stale_supervisor_count' => count(array_filter(
                    $result['supervisors'],
                    fn ($s) => $s['is_stale']
                )),
                'supervisors' => $result['supervisors'],
                'masters' => $result['masters'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e, 'Failed to inspect supervisors');
        }
    }
}
