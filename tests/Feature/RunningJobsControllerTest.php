<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class RunningJobsControllerTest extends TestCase
{
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
