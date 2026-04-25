<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class RunningJobsControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs::auth(null);
        parent::tearDown();
    }

    public function test_api_returns_403_in_production_without_auth_callback(): void
    {
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/horizon/running-jobs');

        $response->assertStatus(403);
        $response->assertJson(['success' => false, 'error' => 'Access denied']);
        $this->assertStringContainsString('AppServiceProvider', $response->json('message'));
    }

    public function test_api_works_in_production_when_auth_callback_returns_true(): void
    {
        $this->app['env'] = 'production';
        \Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs::auth(fn () => true);

        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')->assertOk();
    }

    public function test_controller_uses_auto_detected_server_id_when_hostname_param_missing(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeServerId = 'fake-supervisor-01';

        $this->app->instance(RunningJobsManager::class, $spy);

        $response = $this->getJson('/api/horizon/running-jobs');

        $response->assertOk();
        $this->assertSame('fake-supervisor-01', $spy->capturedServerId);
    }

    public function test_controller_respects_hostname_query_param_when_provided(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeServerId = 'fake-supervisor-01';

        $this->app->instance(RunningJobsManager::class, $spy);

        $response = $this->getJson('/api/horizon/running-jobs?hostname=explicit-server');

        $response->assertOk();
        $this->assertSame('explicit-server', $spy->capturedServerId);
    }
}

class SpyRunningJobsManager extends RunningJobsManager
{
    public ?string $capturedServerId = null;
    public string $fakeServerId = 'fake';

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null): array
    {
        $this->capturedServerId = $serverId;

        return ['jobs' => [], 'warnings' => [], 'total_count' => 0];
    }

    public function getServerIdentifier(): string
    {
        return $this->fakeServerId;
    }

    public function getStats(?array $queues = null): array
    {
        return ['total_running' => 0, 'by_server' => [], 'by_queue' => [], 'by_job_class' => [], 'longest_running' => null, 'warnings' => []];
    }
}
