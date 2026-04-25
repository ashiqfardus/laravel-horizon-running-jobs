<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class RunningJobsManagerErrorPathsTest extends TestCase
{
    public function test_redis_throw_on_one_queue_warns_but_does_not_abort_others(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->with('queues:broken:reserved')->andThrow(new RuntimeException('Connection lost'));
        $redis->shouldReceive('zcard')->with('queues:healthy:reserved')->andReturn(0);
        $redis->shouldReceive('exists')->with('queues:healthy:reserved')->andReturn(false);
        Redis::shouldReceive('connection')->andReturn($redis);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'HorizonRunningJobs: Queue fetch failed',
                \Mockery::on(fn ($ctx) => $ctx['queue'] === 'broken' && str_contains($ctx['error'], 'Connection lost'))
            );

        $manager = new RunningJobsManager(['distributed' => false, 'cache' => ['enabled' => false]]);
        $result = $manager->getRunningJobs(null, true, ['broken', 'healthy']);

        $this->assertContains('Failed to fetch jobs from queue: broken', $result['warnings']);
        $this->assertSame(0, $result['total_count']);
        $this->assertSame([], $result['jobs']);
    }

    public function test_partial_failure_collects_per_queue_warnings(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->with('queues:a:reserved')->andThrow(new RuntimeException('a-down'));
        $redis->shouldReceive('zcard')->with('queues:b:reserved')->andThrow(new RuntimeException('b-down'));
        $redis->shouldReceive('zcard')->with('queues:c:reserved')->andReturn(0);
        $redis->shouldReceive('exists')->with('queues:c:reserved')->andReturn(false);
        Redis::shouldReceive('connection')->andReturn($redis);

        Log::shouldReceive('warning')->twice();

        $manager = new RunningJobsManager(['distributed' => false, 'cache' => ['enabled' => false]]);
        $result = $manager->getRunningJobs(null, true, ['a', 'b', 'c']);

        $this->assertCount(2, $result['warnings']);
        $this->assertContains('Failed to fetch jobs from queue: a', $result['warnings']);
        $this->assertContains('Failed to fetch jobs from queue: b', $result['warnings']);
    }

    public function test_one_malformed_payload_does_not_poison_subsequent_payloads(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->with('queues:default:reserved')->andReturn(2);
        $redis->shouldReceive('exists')->with('queues:default:reserved')->andReturn(true);
        $redis->shouldReceive('zrange')->andReturn([
            'not-valid-json' => (float) (time() + 90),
            json_encode([
                'uuid' => 'good-1',
                'displayName' => 'App\\Jobs\\Good',
                'tags' => ['server:web-01'],
                'data' => ['command' => ''],
                'attempts' => 1,
                'timeout' => 60,
            ]) => (float) (time() + 90),
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);

        Log::shouldReceive('warning')->once();

        $manager = new RunningJobsManager(['distributed' => false, 'cache' => ['enabled' => false]]);
        $result = $manager->getRunningJobs(null, true, ['default']);

        $this->assertSame(1, count($result['jobs']));
        $this->assertSame('good-1', $result['jobs'][0]['job_id']);
        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame(2, $result['total_count']);
    }

    public function test_empty_queue_set_returns_clean_response(): void
    {
        $redis = \Mockery::mock();
        Redis::shouldReceive('connection')->andReturn($redis);

        $manager = new RunningJobsManager(['distributed' => false, 'cache' => ['enabled' => false]]);
        $result = $manager->getRunningJobs(null, true, []);

        $this->assertSame([], $result['jobs']);
        $this->assertSame(0, $result['total_count']);
        $this->assertSame(0, $result['dropped_count']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_long_running_warning_aggregates_across_queues(): void
    {
        $now = time();
        $payload = fn (string $id) => json_encode([
            'uuid' => $id,
            'displayName' => 'App\\Jobs\\Slow',
            'tags' => ['server:web-01'],
            'data' => ['command' => ''],
            'attempts' => 1,
            'timeout' => 600,
        ]);

        // retry_after = 90, scores set so reservation_time = now - 400 (long-running)
        $expiry = $now + 90 - 400;

        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->with('queues:default:reserved')->andReturn(2);
        $redis->shouldReceive('exists')->with('queues:default:reserved')->andReturn(true);
        $redis->shouldReceive('zrange')->andReturn([
            $payload('long-1') => (float) $expiry,
            $payload('long-2') => (float) $expiry,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);

        $manager = new RunningJobsManager([
            'distributed' => false,
            'cache' => ['enabled' => false],
            'long_running_threshold' => 300,
        ]);
        $result = $manager->getRunningJobs(null, true, ['default']);

        $this->assertContains('2 job(s) running over 5 minutes', $result['warnings']);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
