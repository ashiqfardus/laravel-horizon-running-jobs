<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests;

use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            HorizonRunningJobsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('horizon-running-jobs.distributed', false);
        $app['config']->set('horizon-running-jobs.cache.enabled', false);
    }
}
