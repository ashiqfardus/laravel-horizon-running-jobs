<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use stdClass;

class SupervisorInspectorTest extends TestCase
{
    public function test_parse_queues_handles_comma_separated_string(): void
    {
        $inspector = $this->buildInspector();

        $this->assertSame(['default', 'emails', 'reports'], $inspector->parseQueues('default,emails,reports'));
        $this->assertSame(['default'], $inspector->parseQueues(' default '));
        $this->assertSame([], $inspector->parseQueues(null));
        $this->assertSame([], $inspector->parseQueues(''));
    }

    public function test_inspect_returns_supervisors_with_queues_processes_and_freshness(): void
    {
        $now = time();
        $supervisor = $this->makeSupervisor('worker-01', 'master-01', 1234, [
            'redis:default' => 1,
            'redis:emails' => 2,
        ]);

        $inspector = $this->buildInspector(
            supervisorAll: [$supervisor],
            masterAll: [$this->makeMaster('master-01', 'production', 9999, ['worker-01'])],
            supervisorExpiries: ['worker-01' => $now + 60],
            masterExpiries: ['master-01' => $now + 60]
        );

        $result = $inspector->inspect();

        $this->assertCount(1, $result['supervisors']);
        $row = $result['supervisors'][0];
        $this->assertSame('worker-01', $row['name']);
        $this->assertSame('running', $row['status']);
        $this->assertSame('master-01', $row['master']);
        $this->assertSame(1234, $row['pid']);
        $this->assertSame(['default', 'emails'], $row['queues']);
        $this->assertSame(3, $row['process_count']);
        $this->assertFalse($row['is_stale']);
        $this->assertGreaterThan(0, $row['seconds_until_expiry']);
    }

    public function test_inspect_marks_supervisor_stale_when_expiry_in_past(): void
    {
        $now = time();
        $inspector = $this->buildInspector(
            supervisorAll: [], // Horizon repo filters expired entries → empty
            supervisorExpiries: ['dead-worker' => $now - 30] // expired 30s ago
        );

        $result = $inspector->inspect();

        $this->assertCount(1, $result['supervisors']);
        $row = $result['supervisors'][0];
        $this->assertSame('dead-worker', $row['name']);
        $this->assertSame('stale', $row['status']);
        $this->assertTrue($row['is_stale']);
        $this->assertSame(0, $row['seconds_until_expiry']);
        $this->assertNull($row['pid']);
        $this->assertSame([], $row['queues']);
        $this->assertSame(0, $row['process_count']);
    }

    public function test_inspect_returns_masters_with_supervisor_count(): void
    {
        $now = time();
        $master = $this->makeMaster('master-01', 'staging', 5555, ['s1', 's2']);

        $inspector = $this->buildInspector(
            masterAll: [$master],
            masterExpiries: ['master-01' => $now + 45]
        );

        $result = $inspector->inspect();

        $this->assertCount(1, $result['masters']);
        $row = $result['masters'][0];
        $this->assertSame('master-01', $row['name']);
        $this->assertSame('running', $row['status']);
        $this->assertSame('staging', $row['environment']);
        $this->assertSame(5555, $row['pid']);
        $this->assertSame(2, $row['supervisor_count']);
        $this->assertFalse($row['is_stale']);
    }

    public function test_inspect_returns_inspected_at_timestamp(): void
    {
        $inspector = $this->buildInspector();

        $before = time();
        $result = $inspector->inspect();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $result['inspected_at']);
        $this->assertLessThanOrEqual($after, $result['inspected_at']);
    }

    private function buildInspector(
        array $supervisorAll = [],
        array $masterAll = [],
        array $supervisorExpiries = [],
        array $masterExpiries = []
    ): SupervisorInspector {
        $supervisors = \Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn($supervisorAll);

        $masters = \Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn($masterAll);

        $connection = \Mockery::mock();
        $connection->shouldReceive('zrange')
            ->with('supervisors', 0, -1, ['WITHSCORES' => true])
            ->andReturn($supervisorExpiries);
        $connection->shouldReceive('zrange')
            ->with('masters', 0, -1, ['WITHSCORES' => true])
            ->andReturn($masterExpiries);

        $redis = \Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->with('horizon')->andReturn($connection);

        return new SupervisorInspector($supervisors, $masters, $redis);
    }

    private function makeSupervisor(string $name, string $master, int $pid, array $processes): stdClass
    {
        return (object) [
            'name' => $name,
            'master' => $master,
            'pid' => (string) $pid,
            'status' => 'running',
            'processes' => $processes,
            'options' => ['queue' => implode(',', array_map(
                fn ($k) => str_replace('redis:', '', $k),
                array_keys($processes)
            ))],
        ];
    }

    private function makeMaster(string $name, string $env, int $pid, array $supervisors): stdClass
    {
        return (object) [
            'name' => $name,
            'environment' => $env,
            'pid' => (string) $pid,
            'status' => 'running',
            'supervisors' => $supervisors,
        ];
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
