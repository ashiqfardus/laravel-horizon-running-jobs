<?php

namespace Ashiqfardus\HorizonRunningJobs\Http\Middleware;

use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Authorize
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (HorizonRunningJobs::check($request)) {
            return $next($request);
        }

        return new JsonResponse([
            'success' => false,
            'error' => 'Access denied',
            'message' => 'horizon-running-jobs is locked down outside local/testing environments by default. '
                . 'Register an auth callback in your AppServiceProvider::boot():'
                . "\n\n"
                . '  \\Ashiqfardus\\HorizonRunningJobs\\HorizonRunningJobs::auth('
                . 'fn ($request) => $request->user()?->is_admin === true);'
                . "\n\n"
                . 'See README → Securing the API.',
        ], 403);
    }
}
