<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Integration;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\IntegrationTestCase;

class QueueDepthInspectorIntegrationTest extends IntegrationTestCase
{
    public function test_counts_pending_reserved_and_delayed_for_a_single_queue(): void
    {
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'p-1']));
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'p-2']));

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'r-1']));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'r-2']));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'r-3']));

        $this->redis()->zadd('queues:default:delayed', time() + 60, json_encode(['uuid' => 'd-1']));

        $result = $this->inspector()->inspect(['default']);

        $this->assertCount(1, $result['queues']);
        $this->assertSame('default', $result['queues'][0]['queue']);
        $this->assertSame(2, $result['queues'][0]['pending']);
        $this->assertSame(3, $result['queues'][0]['reserved']);
        $this->assertSame(1, $result['queues'][0]['delayed']);
        $this->assertSame(6, $result['queues'][0]['total']);
    }

    public function test_returns_zero_counts_for_empty_queue(): void
    {
        $result = $this->inspector()->inspect(['empty-queue']);

        $this->assertSame(0, $result['queues'][0]['pending']);
        $this->assertSame(0, $result['queues'][0]['reserved']);
        $this->assertSame(0, $result['queues'][0]['delayed']);
        $this->assertSame(0, $result['queues'][0]['total']);
    }

    public function test_aggregates_totals_across_multiple_queues(): void
    {
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'p-1']));
        $this->seedReservedJob('emails', $this->makePayload(['uuid' => 'r-1']));
        $this->seedReservedJob('emails', $this->makePayload(['uuid' => 'r-2']));
        $this->redis()->zadd('queues:reports:delayed', time() + 60, json_encode(['uuid' => 'd-1']));

        $result = $this->inspector()->inspect(['default', 'emails', 'reports']);

        $this->assertCount(3, $result['queues']);
        $this->assertSame(1, $result['totals']['pending']);
        $this->assertSame(2, $result['totals']['reserved']);
        $this->assertSame(1, $result['totals']['delayed']);
        $this->assertSame(4, $result['totals']['total']);
    }

    public function test_falls_back_to_manager_default_queues_when_argument_omitted(): void
    {
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'p-1']));

        $result = $this->inspector()->inspect();

        // Manager's getDefaultQueues() returns ['default'] when nothing else is configured.
        $this->assertCount(1, $result['queues']);
        $this->assertSame('default', $result['queues'][0]['queue']);
        $this->assertSame(1, $result['queues'][0]['pending']);
    }

    public function test_inspected_at_is_a_unix_timestamp(): void
    {
        $before = time();
        $result = $this->inspector()->inspect(['default']);
        $after = time();

        $this->assertGreaterThanOrEqual($before, $result['inspected_at']);
        $this->assertLessThanOrEqual($after, $result['inspected_at']);
    }

    private function inspector(): QueueDepthInspector
    {
        return new QueueDepthInspector(
            $this->app['redis'],
            new RunningJobsManager([
                'redis_connection' => 'default',
                'queues' => ['default'],
            ])
        );
    }
}
