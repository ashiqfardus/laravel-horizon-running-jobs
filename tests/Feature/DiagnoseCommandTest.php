<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class DiagnoseCommandTest extends TestCase
{
    public function test_all_checks_pass_when_state_is_clean(): void
    {
        $this->bindHealthyState();

        $exit = Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('horizon.supervisors', $output);
        $this->assertStringContainsString('jobs.orphaned', $output);
        $this->assertStringContainsString('jobs.zombies', $output);
        $this->assertStringContainsString('jobs.malformed', $output);
        $this->assertStringContainsString('queues.depths', $output);
        $this->assertStringContainsString('Status: PASS', $output);
    }

    public function test_orphan_check_warns_when_orphans_present(): void
    {
        $this->bindHealthyState();
        $this->app->instance(RunningJobsManager::class, new FakeManager(stats: $this->stats(orphans: 2)));

        $exit = Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'Warnings should not flip exit code');
        $this->assertStringContainsString('Status: WARN', $output);
        $this->assertStringContainsString('2 orphan', $output);
    }

    public function test_zombie_check_warns_when_zombies_present(): void
    {
        $this->bindHealthyState();
        $this->app->instance(RunningJobsManager::class, new FakeManager(stats: $this->stats(zombies: 3)));

        Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('Status: WARN', $output);
        $this->assertStringContainsString('3 zombie', $output);
    }

    public function test_stale_supervisor_warns(): void
    {
        $this->bindHealthyState();
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspectorForDiagnose(
            supervisors: [['name' => 'live', 'is_stale' => false], ['name' => 'dead', 'is_stale' => true]]
        ));

        Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('Status: WARN', $output);
        $this->assertStringContainsString('1 stale', $output);
    }

    public function test_no_supervisors_is_a_fail(): void
    {
        $this->bindHealthyState();
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspectorForDiagnose(supervisors: []));

        $exit = Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Status: FAIL', $output);
        $this->assertStringContainsString('No live Horizon supervisor', $output);
    }

    public function test_all_supervisors_stale_is_a_warn_not_fail(): void
    {
        // Heartbeat lag during a healthy Horizon can momentarily flip every
        // entry to stale; FAIL would be over-aggressive. We warn instead so
        // diagnose stays usable in cron-style health checks.
        $this->bindHealthyState();
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspectorForDiagnose(
            supervisors: [['name' => 'sup-1', 'is_stale' => true]]
        ));

        $exit = Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'Expired-but-still-registered supervisor should not flip exit code');
        $this->assertStringContainsString('Status: WARN', $output);
        $this->assertStringContainsString('all stale', $output);
    }

    public function test_json_mode_emits_structured_output(): void
    {
        $this->bindHealthyState();
        $this->app->instance(RunningJobsManager::class, new FakeManager(stats: $this->stats(orphans: 1)));

        Artisan::call('horizon:diagnose', ['--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('overall_status', $decoded);
        $this->assertSame('warn', $decoded['overall_status']);
    }

    public function test_malformed_jobs_warns(): void
    {
        $this->bindHealthyState();
        $this->app->instance(RunningJobsManager::class, new FakeManager(stats: $this->stats(dropped: 5)));

        Artisan::call('horizon:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('Status: WARN', $output);
        $this->assertStringContainsString('5 malformed', $output);
    }

    private function bindHealthyState(): void
    {
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspectorForDiagnose(
            supervisors: [['name' => 'sup-1', 'is_stale' => false]]
        ));
        $this->app->instance(RunningJobsManager::class, new FakeManager(stats: $this->stats()));
        $this->app->instance(QueueDepthInspector::class, new FakeQueueDepthForDiagnose);
    }

    private function stats(int $orphans = 0, int $zombies = 0, int $dropped = 0): array
    {
        return [
            'total_running' => $orphans + $zombies,
            'by_server' => [],
            'by_queue' => [],
            'by_job_class' => [],
            'by_status' => ['running' => 0, 'zombie' => $zombies],
            'by_orphan_status' => ['orphaned' => $orphans, 'healthy' => 0],
            'dropped_count' => $dropped,
            'orphan_count' => $orphans,
            'longest_running' => null,
            'warnings' => [],
        ];
    }
}

class FakeSupervisorInspectorForDiagnose extends SupervisorInspector
{
    public array $supervisorRows;

    public function __construct(array $supervisors = [])
    {
        $this->supervisorRows = $supervisors;
    }

    public function inspect(): array
    {
        return [
            'supervisors' => $this->supervisorRows,
            'masters' => [],
            'inspected_at' => time(),
        ];
    }
}

class FakeManager extends RunningJobsManager
{
    public function __construct(public array $stats = [])
    {
        parent::__construct(['distributed' => true, 'cache' => ['enabled' => false]]);
    }

    public function getStats(?array $queues = null): array
    {
        return $this->stats;
    }

    public function getDefaultQueues(): array
    {
        return ['default'];
    }
}

class FakeQueueDepthForDiagnose extends QueueDepthInspector
{
    public function __construct() {}

    public function inspect(?array $queues = null): array
    {
        return [
            'queues' => [
                ['queue' => 'default', 'pending' => 5, 'reserved' => 0, 'delayed' => 0, 'total' => 5],
            ],
            'totals' => ['pending' => 5, 'reserved' => 0, 'delayed' => 0, 'total' => 5],
            'inspected_at' => time(),
        ];
    }
}
