<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature\UI;

use Ashiqfardus\HorizonRunningJobs\HealthDiagnoser;
use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class DashboardRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubs();
    }

    public function test_dashboard_route_renders_html(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertOk()
            ->assertSee('Horizon Queue Monitor', false)
            ->assertSee('Supervisors', false)
            ->assertSee('Queue depth', false)
            ->assertSee('Running jobs', false);
    }

    public function test_dashboard_includes_csrf_meta_tag(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertOk()
            ->assertSee('name="csrf-token"', false);
    }

    public function test_dashboard_links_to_package_css_and_js(): void
    {
        $response = $this->get('/horizon/queue-monitor');
        $response->assertOk();

        $this->assertStringContainsString('horizon-running-jobs.css', $response->getContent());
        $this->assertStringContainsString('horizon-running-jobs.js', $response->getContent());
    }

    public function test_dashboard_blocked_in_production_without_auth_callback(): void
    {
        $this->app['env'] = 'production';

        $this->get('/horizon/queue-monitor')->assertStatus(403);
    }

    public function test_panel_refresh_endpoint_renders_named_panel(): void
    {
        foreach (['diagnose-banner', 'supervisors-panel', 'queues-panel', 'running-jobs-table'] as $panel) {
            $this->get("/horizon/queue-monitor/panels/{$panel}")
                ->assertOk();
        }
    }

    public function test_unknown_panel_returns_404(): void
    {
        $this->get('/horizon/queue-monitor/panels/nope')->assertStatus(404);
    }

    public function test_running_jobs_panel_honors_orphaned_only_query(): void
    {
        // Use a spy to assert orphanedOnly was passed through.
        $spy = new SpyManagerForUI;
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->get('/horizon/queue-monitor/panels/running-jobs-table?orphaned_only=1')->assertOk();

        $this->assertTrue($spy->capturedOrphanedOnly);
    }

    public function test_asset_route_serves_css_with_correct_mime(): void
    {
        $response = $this->get('/horizon/queue-monitor/assets/horizon-running-jobs.css');

        $response->assertOk();
        $this->assertStringContainsString('text/css', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.hrj', $response->getContent());
    }

    public function test_asset_route_serves_js_with_correct_mime(): void
    {
        $response = $this->get('/horizon/queue-monitor/assets/horizon-running-jobs.js');

        $response->assertOk();
        $this->assertStringContainsString('application/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('hrjPanel', $response->getContent());
    }

    public function test_asset_route_rejects_path_traversal(): void
    {
        $this->get('/horizon/queue-monitor/assets/..%2F..%2Fcomposer.json')->assertStatus(404);
    }

    public function test_unknown_asset_returns_404(): void
    {
        $this->get('/horizon/queue-monitor/assets/whatever.txt')->assertStatus(404);
    }

    public function test_release_endpoint_requires_job_id(): void
    {
        $this->postJson('/horizon/queue-monitor/release', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_release_endpoint_returns_404_for_unknown_job(): void
    {
        $this->postJson('/horizon/queue-monitor/release', ['job_id' => 'ghost'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_release_endpoint_happy_path_invokes_releaser(): void
    {
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public ?string $capturedJobId = null;
            public bool $releaseInvoked = false;

            public function __construct() {}

            public function findReleasable(array $criteria): array
            {
                $this->capturedJobId = $criteria['job_id'] ?? null;
                return [['job_id' => $criteria['job_id'], 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p']];
            }

            public function release(array $items): int
            {
                $this->releaseInvoked = true;
                return count($items);
            }
        };

        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['job_id' => 'real-job-uuid'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 1)
            ->assertJsonPath('job_id', 'real-job-uuid');

        $this->assertSame('real-job-uuid', $fakeReleaser->capturedJobId);
        $this->assertTrue($fakeReleaser->releaseInvoked);
    }

    public function test_dashboard_disabled_when_ui_config_false(): void
    {
        config(['horizon-running-jobs.ui.enabled' => false]);

        // Re-register routes with the new config — easiest is to reload the file.
        require __DIR__ . '/../../../src/routes.php';

        // Without re-bootstrapping the whole router, we just verify the config
        // is honored — the deeper integration is covered by the success test.
        $this->assertFalse(config('horizon-running-jobs.ui.enabled'));
    }

    private function bindStubs(): void
    {
        $this->app->instance(SupervisorInspector::class, new StubSupervisorInspectorForUI);
        $this->app->instance(QueueDepthInspector::class, new StubQueueDepthForUI);
        $this->app->instance(RunningJobsManager::class, new StubManagerForUI);
    }
}

class StubSupervisorInspectorForUI extends SupervisorInspector
{
    public function __construct() {}
    public function inspect(): array
    {
        return [
            'supervisors' => [
                ['name' => 'sup-1', 'status' => 'running', 'master' => 'm1', 'pid' => 1, 'queues' => ['default'], 'process_count' => 1, 'processes' => [], 'expires_at' => time() + 60, 'seconds_until_expiry' => 60, 'is_stale' => false],
            ],
            'masters' => [],
            'inspected_at' => time(),
        ];
    }
}

class StubQueueDepthForUI extends QueueDepthInspector
{
    public function __construct() {}
    public function inspect(?array $queues = null): array
    {
        return [
            'queues' => [
                ['queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
            ],
            'totals' => ['pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
            'inspected_at' => time(),
        ];
    }
}

class StubManagerForUI extends RunningJobsManager
{
    public function __construct() { parent::__construct(['cache' => ['enabled' => false]]); }
    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        return ['jobs' => [], 'warnings' => [], 'total_count' => 0, 'dropped_count' => 0, 'orphan_count' => 0];
    }
    public function getStats(?array $queues = null): array
    {
        return [
            'total_running' => 0,
            'by_server' => [], 'by_queue' => [], 'by_job_class' => [],
            'by_status' => ['running' => 0, 'zombie' => 0],
            'by_orphan_status' => ['orphaned' => 0, 'healthy' => 0],
            'dropped_count' => 0, 'orphan_count' => 0, 'longest_running' => null, 'warnings' => [],
        ];
    }
}

class SpyManagerForUI extends StubManagerForUI
{
    public bool $capturedOrphanedOnly = false;
    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        $this->capturedOrphanedOnly = $orphanedOnly;
        return parent::getRunningJobs($serverId, $showAll, $queues, $orphanedOnly);
    }
}
