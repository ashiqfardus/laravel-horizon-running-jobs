<?php

// Copied verbatim from README.md, "Apps that authenticate with tokens".
// TokenAuthAppsTest::test_readme_example_matches_this_file keeps the two identical.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class QueueMonitorBasicAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = (string) config('services.queue_monitor.user');
        $password = (string) config('services.queue_monitor.password');
        $attempts = 'queue-monitor-auth:' . $request->ip();

        if (RateLimiter::tooManyAttempts($attempts, 5)) {
            return response('Too many attempts', 429);
        }

        if ($password === ''
            || ! hash_equals($user, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            // Count wrong credentials only, not the browser's first
            // credential-less request.
            if ($request->getUser() !== null) {
                RateLimiter::hit($attempts, 60);
            }

            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Queue monitor"']);
        }

        $request->attributes->set('queue_monitor_authenticated', true);

        return $next($request);
    }
}
