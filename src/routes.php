<?php

use Illuminate\Support\Facades\Route;
use Ashiqfardus\HorizonRunningJobs\Controllers\RunningJobsController;

$config = config('horizon-running-jobs.routes', []);

Route::group([
    'prefix' => $config['prefix'] ?? 'api',
    'middleware' => $config['middleware'] ?? ['api'],
], function () use ($config) {
    $uri = $config['uri'] ?? 'horizon/running-jobs';

    Route::get($uri, [RunningJobsController::class, 'index'])
        ->name('horizon.running-jobs.index');

    Route::get($uri . '/stats', [RunningJobsController::class, 'stats'])
        ->name('horizon.running-jobs.stats');
});

