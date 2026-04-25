<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class SupervisorsControllerTest extends TestCase
{
    public function test_returns_inspector_payload_with_summary_counts(): void
    {
        $now = time();
        $fake = new FakeSupervisorInspector;
        $fake->payload = [
            'supervisors' => [
                ['name' => 'live-1', 'status' => 'running', 'is_stale' => false, 'master' => 'm1', 'pid' => 1, 'queues' => ['default'], 'process_count' => 1, 'processes' => [], 'expires_at' => $now + 60, 'seconds_until_expiry' => 60],
                ['name' => 'stale-1', 'status' => 'stale', 'is_stale' => true, 'master' => null, 'pid' => null, 'queues' => [], 'process_count' => 0, 'processes' => [], 'expires_at' => $now - 30, 'seconds_until_expiry' => 0],
            ],
            'masters' => [
                ['name' => 'm1', 'status' => 'running', 'is_stale' => false, 'environment' => 'production', 'pid' => 100, 'supervisor_count' => 1, 'expires_at' => $now + 45, 'seconds_until_expiry' => 45],
            ],
            'inspected_at' => $now,
        ];

        $this->app->instance(SupervisorInspector::class, $fake);

        $this->getJson('/api/horizon/supervisors')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('supervisor_count', 2)
            ->assertJsonPath('master_count', 1)
            ->assertJsonPath('stale_supervisor_count', 1)
            ->assertJsonPath('supervisors.0.name', 'live-1')
            ->assertJsonPath('supervisors.1.is_stale', true);
    }

    public function test_endpoint_is_gated_by_auth_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspector);

        $this->getJson('/api/horizon/supervisors')->assertStatus(403);
    }

    public function test_response_includes_every_documented_field(): void
    {
        $this->app->instance(SupervisorInspector::class, new FakeSupervisorInspector);

        $this->getJson('/api/horizon/supervisors')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'inspected_at',
                'supervisor_count',
                'master_count',
                'stale_supervisor_count',
                'supervisors',
                'masters',
            ]);
    }

    public function test_returns_500_when_inspector_throws(): void
    {
        $fake = new FakeSupervisorInspector;
        $fake->throwOnInspect = new \RuntimeException('horizon connection unreachable');

        $this->app->instance(SupervisorInspector::class, $fake);

        $this->getJson('/api/horizon/supervisors')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Failed to inspect supervisors');
    }
}

class FakeSupervisorInspector extends SupervisorInspector
{
    public array $payload = ['supervisors' => [], 'masters' => [], 'inspected_at' => 0];
    public ?\Throwable $throwOnInspect = null;

    public function __construct()
    {
        // Intentionally bypass parent __construct — we don't need real deps.
    }

    public function inspect(): array
    {
        if ($this->throwOnInspect) {
            throw $this->throwOnInspect;
        }

        return $this->payload;
    }
}
