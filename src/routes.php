<?php

use Ashiqfardus\HorizonRunningJobs\Controllers\RunningJobsController;
use Ashiqfardus\HorizonRunningJobs\Controllers\SupervisorsController;
use Ashiqfardus\HorizonRunningJobs\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

$config = config('horizon-running-jobs.routes', []);

// Authorize is appended unconditionally so the gate runs even if the user
// strips middleware in their config. Defense-in-depth.
$middleware = array_merge(
    $config['middleware'] ?? ['api'],
    [Authorize::class]
);

Route::group([
    'prefix' => $config['prefix'] ?? 'api',
    'middleware' => $middleware,
], function () use ($config) {
    $uri = $config['uri'] ?? 'horizon/running-jobs';

    Route::get($uri, [RunningJobsController::class, 'index'])
        ->name('horizon.running-jobs.index');

    Route::get($uri . '/stats', [RunningJobsController::class, 'stats'])
        ->name('horizon.running-jobs.stats');

    Route::get('horizon/supervisors', [SupervisorsController::class, 'index'])
        ->name('horizon.supervisors.index');
});

