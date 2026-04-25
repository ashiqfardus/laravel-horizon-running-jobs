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

    public function test_index_response_includes_every_documented_field(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeServerId = 'worker-01';
        $spy->fakeResult = [
            'jobs' => [['job_id' => 'abc', 'job_class' => 'X', 'queue' => 'default', 'server' => 'worker-01', 'status' => 'running', 'start_time' => '2026-04-25T10:00:00+00:00', 'start_timestamp' => 1745576400, 'running_for_seconds' => 5, 'running_for_formatted' => '5s', 'attempts' => 1, 'timeout' => 60, 'tags' => []]],
            'warnings' => [],
            'total_count' => 1,
            'dropped_count' => 0,
        ];

        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'hostname',
                'timestamp',
                'queues_monitored',
                'running_jobs_count',
                'total_count',
                'dropped_count',
                'jobs',
                'warnings',
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('hostname', 'worker-01')
            ->assertJsonPath('running_jobs_count', 1);
    }

    public function test_queues_monitored_echoes_validated_user_input(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs?queues=alpha,beta')
            ->assertOk()
            ->assertJsonPath('queues_monitored', ['alpha', 'beta']);
    }

    public function test_index_returns_500_when_manager_throws(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->throwOnGetRunningJobs = new \RuntimeException('redis is on fire');
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Failed to fetch running jobs');
    }

    public function test_stats_endpoint_returns_full_shape(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeStats = [
            'total_running' => 5,
            'by_server' => ['worker-01' => 5],
            'by_queue' => ['default' => 5],
            'by_job_class' => ['App\\Jobs\\Foo' => 5],
            'by_status' => ['running' => 4, 'zombie' => 1],
            'dropped_count' => 0,
            'longest_running' => null,
            'warnings' => [],
        ];
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs/stats')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'timestamp',
                'stats' => ['total_running', 'by_server', 'by_queue', 'by_job_class', 'by_status', 'dropped_count', 'longest_running', 'warnings'],
            ])
            ->assertJsonPath('stats.total_running', 5)
            ->assertJsonPath('stats.by_status.zombie', 1);
    }

    public function test_stats_endpoint_returns_500_when_manager_throws(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->throwOnGetStats = new \RuntimeException('boom');
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs/stats')
            ->assertStatus(500)
            ->assertJsonPath('error', 'Failed to fetch statistics');
    }

    public function test_authorize_middleware_runs_at_request_time_for_running_jobs(): void
    {
        // Auth callback short-circuits before the manager would be touched.
        $this->app['env'] = 'production';
        \Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs::auth(fn () => false);

        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->throwOnGetRunningJobs = new \RuntimeException('manager should not be called');
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')->assertStatus(403);
    }

    public function test_authorize_middleware_runs_at_request_time_for_stats(): void
    {
        $this->app['env'] = 'production';
        \Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs::auth(fn () => false);

        $this->getJson('/api/horizon/running-jobs/stats')->assertStatus(403);
    }

    public function test_orphaned_query_param_passes_through_and_surfaces_count(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $spy->fakeResult = [
            'jobs' => [],
            'warnings' => ['2 orphan job(s) detected (worker process is no longer registered)'],
            'total_count' => 5,
            'dropped_count' => 0,
            'orphan_count' => 2,
        ];
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs?orphaned=true')
            ->assertOk()
            ->assertJsonPath('orphaned_only', true)
            ->assertJsonPath('orphan_count', 2);

        $this->assertTrue($spy->capturedOrphanedOnly);
    }

    public function test_orphaned_defaults_to_false_when_param_absent(): void
    {
        $spy = new SpyRunningJobsManager(['distributed' => true, 'cache' => ['enabled' => false]]);
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->getJson('/api/horizon/running-jobs')
            ->assertOk()
            ->assertJsonPath('orphaned_only', false);

        $this->assertFalse($spy->capturedOrphanedOnly);
    }
}

class SpyRunningJobsManager extends RunningJobsManager
{
    public ?string $capturedServerId = null;
    public string $fakeServerId = 'fake';

    public array $fakeResult = ['jobs' => [], 'warnings' => [], 'total_count' => 0];
    public ?array $fakeStats = null;
    public ?\Throwable $throwOnGetRunningJobs = null;
    public ?\Throwable $throwOnGetStats = null;
    public bool $capturedOrphanedOnly = false;

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        if ($this->throwOnGetRunningJobs) {
            throw $this->throwOnGetRunningJobs;
        }

        $this->capturedServerId = $serverId;
        $this->capturedOrphanedOnly = $orphanedOnly;

        return $this->fakeResult;
    }

    public function getServerIdentifier(): string
    {
        return $this->fakeServerId;
    }

    public function getStats(?array $queues = null): array
    {
        if ($this->throwOnGetStats) {
            throw $this->throwOnGetStats;
        }

        return $this->fakeStats ?? ['total_running' => 0, 'by_server' => [], 'by_queue' => [], 'by_job_class' => [], 'longest_running' => null, 'warnings' => []];
    }
}
