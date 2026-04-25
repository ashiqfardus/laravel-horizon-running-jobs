<?php

namespace Ashiqfardus\HorizonRunningJobs\Concerns;

use Illuminate\Http\JsonResponse;
use Throwable;

trait HandlesJsonErrors
{
    /**
     * Build a JsonResponse for a thrown error. Reveals the underlying message
     * only in `local` and `testing` environments; production callers get a
     * generic string so internal details don't leak.
     */
    protected function jsonError(Throwable $e, string $userError, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $userError,
            'message' => app()->environment('local', 'testing')
                ? $e->getMessage()
                : 'Internal server error',
        ], $status);
    }
}
