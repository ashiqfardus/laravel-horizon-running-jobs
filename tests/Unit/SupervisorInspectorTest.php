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
            supervisorHeartbeats: ['worker-01' => $now - 10],
            masterHeartbeats: ['master-01' => $now - 2]
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
        // Horizon stores the last heartbeat time as the score. 10s of silence
        // is well inside Horizon's own 29s window, so this supervisor is live.
        $this->assertFalse($row['is_stale']);
        $this->assertSame($now - 10, $row['last_heartbeat_at']);
        $this->assertEqualsWithDelta(10, $row['seconds_since_heartbeat'], 1);
        $this->assertSame($now - 10 + SupervisorInspector::SUPERVISOR_ALIVE_SECONDS, $row['expires_at']);
        $this->assertEqualsWithDelta(19, $row['seconds_until_expiry'], 1);
    }

    public function test_supervisor_past_horizons_window_but_inside_grace_is_not_stale(): void
    {
        $now = time();
        $inspector = $this->buildInspector(
            supervisorAll: [],
            supervisorHeartbeats: ['slow-worker' => $now - 31] // Horizon's 29s + 5s grace = 34s
        );

        $row = $inspector->inspect()['supervisors'][0];

        $this->assertFalse($row['is_stale']);
        $this->assertSame(0, $row['seconds_until_expiry']);
    }

    public function test_stale_grace_comes_from_config(): void
    {
        $now = time();
        $heartbeats = ['slow-worker' => $now - 31, 'quiet-worker' => $now - 50];

        config(['horizon-running-jobs.supervisor_stale_grace_seconds' => 0]);
        $rows = collect($this->buildInspector(supervisorHeartbeats: $heartbeats)->inspect()['supervisors'])->keyBy('name');
        $this->assertTrue($rows['slow-worker']['is_stale'], '31s is stale with no grace on top of 29s');

        config(['horizon-running-jobs.supervisor_stale_grace_seconds' => 30]);
        $rows = collect($this->buildInspector(supervisorHeartbeats: $heartbeats)->inspect()['supervisors'])->keyBy('name');
        $this->assertFalse($rows['quiet-worker']['is_stale'], '50s is inside 29s + 30s grace');
    }

    public function test_master_uses_horizons_14_second_window(): void
    {
        $now = time();
        $inspector = $this->buildInspector(
            masterHeartbeats: [
                'live-master' => $now - 10,
                'dead-master' => $now - 25, // 14s + 5s grace = 19s
            ]
        );

        $masters = collect($inspector->inspect()['masters'])->keyBy('name');

        $this->assertFalse($masters['live-master']['is_stale']);
        $this->assertTrue($masters['dead-master']['is_stale']);
        $this->assertSame('stale', $masters['dead-master']['status']);
        $this->assertSame($now - 25, $masters['dead-master']['last_heartbeat_at']);
    }

    public function test_inspect_marks_supervisor_stale_when_heartbeat_is_too_old(): void
    {
        $now = time();
        $inspector = $this->buildInspector(
            supervisorAll: [], // Horizon repo filters expired entries → empty
            supervisorHeartbeats: ['dead-worker' => $now - 40] // silent for 40s
        );

        $result = $inspector->inspect();

        $this->assertCount(1, $result['supervisors']);
        $row = $result['supervisors'][0];
        $this->assertSame('dead-worker', $row['name']);
        $this->assertSame('stale', $row['status']);
        $this->assertTrue($row['is_stale']);
        $this->assertSame(0, $row['seconds_until_expiry']);
        $this->assertEqualsWithDelta(40, $row['seconds_since_heartbeat'], 1);
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
            masterHeartbeats: ['master-01' => $now - 3]
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
        array $supervisorHeartbeats = [],
        array $masterHeartbeats = []
    ): SupervisorInspector {
        $supervisors = \Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn($supervisorAll);

        $masters = \Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn($masterAll);

        $connection = \Mockery::mock();
        $connection->shouldReceive('zrange')
            ->with('supervisors', 0, -1, ['WITHSCORES' => true])
            ->andReturn($supervisorHeartbeats);
        $connection->shouldReceive('zrange')
            ->with('masters', 0, -1, ['WITHSCORES' => true])
            ->andReturn($masterHeartbeats);

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
