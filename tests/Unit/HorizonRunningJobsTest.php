<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Http\Request;

class HorizonRunningJobsTest extends TestCase
{
    protected function tearDown(): void
    {
        HorizonRunningJobs::auth(null);
        parent::tearDown();
    }

    public function test_check_allows_local_env(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_check_allows_testing_env(): void
    {
        $this->app['env'] = 'testing';

        $this->assertTrue(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_check_denies_in_production_without_callback(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_check_uses_registered_callback_in_production(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(fn ($request) => $request->query('token') === 'secret');

        $this->assertTrue(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs?token=secret')));
        $this->assertFalse(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs?token=wrong')));
    }

    public function test_auth_callback_can_be_cleared(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(fn () => true);
        $this->assertTrue(HorizonRunningJobs::hasAuthCallback());

        HorizonRunningJobs::auth(null);

        $this->assertFalse(HorizonRunningJobs::hasAuthCallback());
        $this->assertFalse(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_truthy_non_boolean_callback_return_allows(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(fn () => 'yes-please');

        $this->assertTrue(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_falsy_non_boolean_callback_return_denies(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(fn () => 0);

        $this->assertFalse(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_null_callback_return_denies(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(fn () => null);

        $this->assertFalse(HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs')));
    }

    public function test_throwing_callback_propagates_so_failures_are_visible(): void
    {
        $this->app['env'] = 'production';
        HorizonRunningJobs::auth(function (): bool {
            throw new \RuntimeException('user_lookup_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('user_lookup_failed');

        HorizonRunningJobs::check(Request::create('/api/horizon/running-jobs'));
    }
}
