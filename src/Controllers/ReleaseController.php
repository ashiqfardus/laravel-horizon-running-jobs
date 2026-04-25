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
        $orphaned = (bool) $request->input('orphaned', false);
        $zombie = (bool) $request->input('zombie', false);

        $modes = (int) ($jobId !== '') + (int) $orphaned + (int) $zombie;

        if ($modes === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Specify a job_id, orphaned=true, or zombie=true.',
            ], 422);
        }

        if ($modes > 1) {
            return response()->json([
                'success' => false,
                'message' => 'job_id, orphaned, and zombie are mutually exclusive — pick one.',
            ], 422);
        }

        if ($jobId !== '') {
            $criteria = ['job_id' => $jobId];
        } elseif ($orphaned) {
            $criteria = ['orphaned' => true];
        } else {
            $criteria = ['zombie' => true];
        }

        $found = $releaser->findReleasable($criteria);

        if (empty($found) && $jobId !== '') {
            return response()->json([
                'success' => false,
                'message' => "Job ID \"{$jobId}\" not found in any reserved set",
            ], 404);
        }

        $count = $releaser->release($found);

        return response()->json([
            'success' => true,
            'released' => $count,
            'mode' => $jobId !== '' ? 'job_id' : ($orphaned ? 'orphaned' : 'zombie'),
        ] + ($jobId !== '' ? ['job_id' => $jobId] : []));
    }
}
