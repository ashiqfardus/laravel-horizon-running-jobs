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

    public function test_invalid_queue_name_returns_422(): void
    {
        $this->getJson('/api/horizon/running-jobs?queues=hello,bad name')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Invalid queue parameter');
    }

    public function test_too_many_queues_returns_422(): void
    {
        $queues = implode(',', array_map(fn ($i) => "q{$i}", range(1, 25)));

        $this->getJson('/api/horizon/running-jobs?queues=' . $queues)
            ->assertStatus(422);
    }

    public function test_empty_queues_param_falls_back_to_defaults(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs?queues=,,,')
            ->assertOk();
    }

    public function test_valid_queue_names_pass_through(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs?queues=emails,reports-1,priority_high')
            ->assertOk()
            ->assertJsonPath('queues_monitored', ['emails', 'reports-1', 'priority_high']);
    }

    public function test_default_routes_middleware_includes_throttle(): void
    {
        $defaults = require __DIR__ . '/../../config/horizon-running-jobs.php';

        $this->assertContains('throttle:60,1', $defaults['routes']['middleware']);
    }

    public function test_dropped_count_and_warnings_pass_through_from_manager(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeResult = [
            'jobs' => [],
            'warnings' => ['7 malformed job(s) skipped (see logs)'],
            'total_count' => 0,
            'dropped_count' => 7,
        ];

        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')
            ->assertOk()
            ->assertJsonPath('dropped_count', 7)
            ->assertJsonPath('warnings.0', '7 malformed job(s) skipped (see logs)')
            ->assertJsonPath('total_count', 0);
    }
}

class SpyRunningJobsManager extends RunningJobsManager
{
    public ?string $capturedServerId = null;
    public string $fakeServerId = 'fake';

    public array $fakeResult = ['jobs' => [], 'warnings' => [], 'total_count' => 0];

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null): array
    {
        $this->capturedServerId = $serverId;

        return $this->fakeResult;
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
