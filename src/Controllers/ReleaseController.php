<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReleaseController extends Controller
{
    public function release(Request $request, JobReleaser $releaser): JsonResponse
    {
        $jobId = trim((string) $request->input('job_id', ''));

        if ($jobId === '') {
            return response()->json([
                'success' => false,
                'message' => 'job_id is required',
            ], 422);
        }

        $found = $releaser->findReleasable(['job_id' => $jobId]);

        if (empty($found)) {
            return response()->json([
                'success' => false,
                'message' => "Job ID \"{$jobId}\" not found in any reserved set",
            ], 404);
        }

        $count = $releaser->release($found);

        return response()->json([
            'success' => true,
            'released' => $count,
            'job_id' => $jobId,
        ]);
    }
}
