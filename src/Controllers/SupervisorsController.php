<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SupervisorsController extends Controller
{
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to inspect supervisors',
                'message' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
