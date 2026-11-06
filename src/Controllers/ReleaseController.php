<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable;
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

        if ($jobId === '' && ! $orphaned && ! $zombie) {
            return response()->json([
                'success' => false,
                'message' => 'Specify a job_id, orphaned=true, or zombie=true.',
            ], 422);
        }

        if ($jobId !== '' && ($orphaned || $zombie)) {
            return response()->json([
                'success' => false,
                'message' => 'job_id cannot be combined with orphaned or zombie.',
            ], 422);
        }

        $criteria = $jobId !== ''
            ? ['job_id' => $jobId]
            : array_filter(['orphaned' => $orphaned, 'zombie' => $zombie]);

        try {
            $found = $releaser->findReleasable($criteria);
        } catch (LiveSupervisorsUnavailable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }

        if (empty($found) && $jobId !== '') {
            return response()->json([
                'success' => false,
                'message' => "Job ID \"{$jobId}\" not found in any reserved set",
            ], 404);
        }

        // The dashboard offers a row's button only while releasing it is safe,
        // but the page may be stale: a worker may have picked the job up again.
        if ($jobId !== '' && ! ($found[0]['safe'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'This job cannot be released from the dashboard: it is not orphaned or expired '
                    . 'and past its timeout, so a worker may still be running it.',
            ], 409);
        }

        $count = $releaser->release($found);

        return response()->json([
            'success' => true,
            'released' => $count,
            'mode' => $jobId !== '' ? 'job_id' : implode('+', array_keys($criteria)),
        ] + ($jobId !== '' ? ['job_id' => $jobId] : []));
    }
}
