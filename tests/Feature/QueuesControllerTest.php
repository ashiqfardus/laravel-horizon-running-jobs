<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class QueuesControllerTest extends TestCase
{
    public function test_returns_inspector_payload_with_summary(): void
    {
        $fake = new FakeQueueDepthInspector;
        $fake->payload = [
            'queues' => [
                ['queue' => 'default', 'pending' => 5, 'reserved' => 2, 'delayed' => 1, 'total' => 8],
                ['queue' => 'emails', 'pending' => 3, 'reserved' => 1, 'delayed' => 0, 'total' => 4],
            ],
            'totals' => ['pending' => 8, 'reserved' => 3, 'delayed' => 1, 'total' => 12],
            'inspected_at' => 1745576400,
        ];

        $this->app->instance(QueueDepthInspector::class, $fake);

        $this->getJson('/api/horizon/queues')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('queue_count', 2)
            ->assertJsonPath('totals.pending', 8)
            ->assertJsonPath('totals.total', 12)
            ->assertJsonPath('queues.0.queue', 'default')
            ->assertJsonPath('queues.1.delayed', 0);
    }

    public function test_endpoint_is_gated_by_auth_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->app->instance(QueueDepthInspector::class, new FakeQueueDepthInspector);

        $this->getJson('/api/horizon/queues')->assertStatus(403);
    }

    public function test_response_includes_every_documented_field(): void
    {
        $this->app->instance(QueueDepthInspector::class, new FakeQueueDepthInspector);

        $this->getJson('/api/horizon/queues')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'inspected_at',
                'queue_count',
                'totals' => ['pending', 'reserved', 'delayed', 'total'],
                'queues',
            ]);
    }

    public function test_returns_500_when_inspector_throws(): void
    {
        $fake = new FakeQueueDepthInspector;
        $fake->throwOnInspect = new \RuntimeException('redis is on fire');
        $this->app->instance(QueueDepthInspector::class, $fake);

        $this->getJson('/api/horizon/queues')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Failed to inspect queue depths');
    }

    public function test_validated_queues_param_is_passed_through_to_inspector(): void
    {
        $fake = new FakeQueueDepthInspector;
        $this->app->instance(QueueDepthInspector::class, $fake);

        $this->getJson('/api/horizon/queues?queues=alpha,beta')->assertOk();

        $this->assertSame(['alpha', 'beta'], $fake->capturedQueues);
    }

    public function test_empty_queues_param_falls_back_to_default(): void
    {
        $fake = new FakeQueueDepthInspector;
        $this->app->instance(QueueDepthInspector::class, $fake);

        $this->getJson('/api/horizon/queues')->assertOk();

        // null = inspector should use its own default-queue resolution.
        $this->assertNull($fake->capturedQueues);
    }

    public function test_invalid_queue_name_returns_422(): void
    {
        $this->app->instance(QueueDepthInspector::class, new FakeQueueDepthInspector);

        $this->getJson('/api/horizon/queues?queues=hello,bad name')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Invalid queue parameter');
    }
}

class FakeQueueDepthInspector extends QueueDepthInspector
{
    public array $payload = [
        'queues' => [],
        'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
        'inspected_at' => 0,
    ];
    public ?\Throwable $throwOnInspect = null;
    public ?array $capturedQueues = null;

    public function __construct()
    {
        // Bypass parent __construct — we fake everything below.
    }

    public function inspect(?array $queues = null): array
    {
        if ($this->throwOnInspect) {
            throw $this->throwOnInspect;
        }

        $this->capturedQueues = $queues;

        return $this->payload;
    }
}
