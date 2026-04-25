<?php

use Ashiqfardus\HorizonRunningJobs\Controllers\AssetController;
use Ashiqfardus\HorizonRunningJobs\Controllers\DashboardController;
use Ashiqfardus\HorizonRunningJobs\Controllers\PanelController;
use Ashiqfardus\HorizonRunningJobs\Controllers\QueuesController;
use Ashiqfardus\HorizonRunningJobs\Controllers\ReleaseController;
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

    Route::get('horizon/queues', [QueuesController::class, 'index'])
        ->name('horizon.queues.index');
});

// UI routes — standalone dashboard, panel-fragment refreshes, release action,
// and asset serving. Uses `web` middleware (not `api`) so sessions / CSRF
// are available for the release POST. Authorize still runs.
$uiConfig = config('horizon-running-jobs.ui', []);

if ($uiConfig['enabled'] ?? true) {
    $uiMiddleware = array_merge(
        $uiConfig['middleware'] ?? ['web'],
        [Authorize::class]
    );

    Route::group([
        'prefix' => $uiConfig['prefix'] ?? 'horizon/queue-monitor',
        'middleware' => $uiMiddleware,
    ], function () {
        Route::get('/', [DashboardController::class, 'show'])
            ->name('horizon-running-jobs.dashboard');

        Route::get('/panels/{panel}', [PanelController::class, 'show'])
            ->name('horizon-running-jobs.panel');

        Route::post('/release', [ReleaseController::class, 'release'])
            ->name('horizon-running-jobs.release');

        Route::get('/assets/{file}', [AssetController::class, 'show'])
            ->name('horizon-running-jobs.assets')
            ->where('file', '[A-Za-z0-9._-]+');
    });
}
