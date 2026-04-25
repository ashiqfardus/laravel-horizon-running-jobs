<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;

class QueueDepthInspectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_uses_redis_connection_resolved_by_running_jobs_manager(): void
    {
        $manager = new RunningJobsManager([
            'redis_connection' => 'queue-cluster-east',
            'queues' => ['default'],
        ]);

        $connection = Mockery::mock();
        $connection->shouldReceive('llen')->andReturn(0);
        $connection->shouldReceive('zcard')->andReturn(0);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->with('queue-cluster-east')
            ->atLeast()->once()
            ->andReturn($connection);

        $inspector = new QueueDepthInspector($factory, $manager);
        $result = $inspector->inspect(['default']);

        // Mockery's `with('queue-cluster-east')` is the load-bearing assertion.
        // This sanity check just keeps PHPUnit from flagging the test as risky.
        $this->assertSame('default', $result['queues'][0]['queue']);
    }

    public function test_falls_back_to_manager_default_queues_when_no_argument(): void
    {
        $manager = new RunningJobsManager([
            'redis_connection' => 'default',
            'queues' => ['emails', 'reports'],
        ]);

        $connection = Mockery::mock();
        $connection->shouldReceive('llen')->andReturn(0);
        $connection->shouldReceive('zcard')->andReturn(0);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->andReturn($connection);

        $inspector = new QueueDepthInspector($factory, $manager);
        $result = $inspector->inspect();

        $this->assertCount(2, $result['queues']);
        $this->assertSame('emails', $result['queues'][0]['queue']);
        $this->assertSame('reports', $result['queues'][1]['queue']);
    }

    public function test_uses_correct_redis_keys_per_queue(): void
    {
        $manager = new RunningJobsManager([
            'redis_connection' => 'default',
            'queues' => ['default'],
        ]);

        $connection = Mockery::mock();
        $connection->shouldReceive('llen')
            ->with('queues:emails')
            ->once()
            ->andReturn(7);
        $connection->shouldReceive('zcard')
            ->with('queues:emails:reserved')
            ->once()
            ->andReturn(2);
        $connection->shouldReceive('zcard')
            ->with('queues:emails:delayed')
            ->once()
            ->andReturn(3);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->andReturn($connection);

        $inspector = new QueueDepthInspector($factory, $manager);
        $result = $inspector->inspect(['emails']);

        $this->assertSame(7, $result['queues'][0]['pending']);
        $this->assertSame(2, $result['queues'][0]['reserved']);
        $this->assertSame(3, $result['queues'][0]['delayed']);
        $this->assertSame(12, $result['queues'][0]['total']);
    }
}
