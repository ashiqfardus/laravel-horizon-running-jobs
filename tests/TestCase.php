<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests;

use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobsServiceProvider;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
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

        // Horizon's service provider isn't booted in TestBench, so its
        // contracts have no bindings. Most package tests don't touch the
        // SupervisorInspector, but anything that resolves controllers (e.g.
        // route-registration tests via gatherMiddleware()) will trip the
        // container while walking constructor deps. Bind no-op repos so
        // resolution succeeds; tests that exercise the inspector swap these
        // for proper mocks.
        $app->bind(SupervisorRepository::class, fn () => \Mockery::mock(SupervisorRepository::class)
            ->shouldIgnoreMissing()
            ->shouldReceive('all')->andReturn([])->getMock());
        $app->bind(MasterSupervisorRepository::class, fn () => \Mockery::mock(MasterSupervisorRepository::class)
            ->shouldIgnoreMissing()
            ->shouldReceive('all')->andReturn([])->getMock());
    }
}
