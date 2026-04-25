<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RunningJobsManagerTest extends TestCase
{
    private function manager(array $overrides = []): RunningJobsManager
    {
        return new RunningJobsManager(array_merge([
            'distributed' => false,
            'cache' => ['enabled' => false],
        ], $overrides));
    }

    public function test_format_duration_under_a_minute(): void
    {
        $this->assertSame('42s', $this->manager()->formatDuration(42));
    }

    public function test_format_duration_minutes_and_seconds(): void
    {
        $this->assertSame('2m 30s', $this->manager()->formatDuration(150));
    }

    public function test_format_duration_hours_and_minutes(): void
    {
        $this->assertSame('1h 5m', $this->manager()->formatDuration(3900));
    }

    public function test_extract_server_identifier_from_tag(): void
    {
        $jobDetails = [
            'tags' => ['server:web-01', 'environment:production'],
            'data' => ['command' => ''],
        ];

        $this->assertSame('web-01', $this->manager()->extractServerIdentifier($jobDetails));
    }

    public function test_extract_server_identifier_returns_unknown_when_no_tag_and_no_command(): void
    {
        $this->assertSame('unknown', $this->manager()->extractServerIdentifier([
            'tags' => [],
            'data' => [],
        ]));
    }

    public function test_extract_server_identifier_reads_supervisor_id_even_when_class_not_loaded(): void
    {
        $serialized = 'O:20:"App\\Jobs\\NonExistent":1:{s:13:"supervisor_id";s:10:"web-01-dev";}';

        $this->assertSame('web-01-dev', $this->manager()->extractServerIdentifier([
            'tags' => [],
            'data' => ['command' => $serialized],
        ]));
    }

    public function test_extract_server_identifier_does_not_throw_on_malformed_command(): void
    {
        $this->assertSame('unknown', $this->manager()->extractServerIdentifier([
            'tags' => [],
            'data' => ['command' => 'not-serialized-garbage!!!'],
        ]));
    }

    public function test_redis_connection_name_prefers_explicit_package_config(): void
    {
        config()->set('horizon.use', 'horizon-conn');

        $manager = $this->manager(['redis_connection' => 'explicit-conn']);

        $this->assertSame('explicit-conn', $manager->getRedisConnectionName());
    }

    public function test_redis_connection_name_falls_back_to_horizon_use(): void
    {
        config()->set('horizon.use', 'horizon-conn');

        $manager = $this->manager(['redis_connection' => null]);

        $this->assertSame('horizon-conn', $manager->getRedisConnectionName());
    }

    public function test_redis_connection_name_returns_null_when_nothing_configured(): void
    {
        config()->set('horizon.use', null);

        $manager = $this->manager(['redis_connection' => null]);

        $this->assertNull($manager->getRedisConnectionName());
    }

    public function test_resolve_retry_after_defaults_to_90(): void
    {
        config()->set('queue.connections.redis.retry_after', null);

        $this->assertSame(90, $this->manager()->resolveRetryAfter());
    }

    public function test_resolve_retry_after_reads_queue_connection_config(): void
    {
        config()->set('queue.connections.redis.retry_after', 180);

        $this->assertSame(180, $this->manager()->resolveRetryAfter());
    }

    public function test_resolve_retry_after_package_config_wins_over_queue_config(): void
    {
        config()->set('queue.connections.redis.retry_after', 90);

        $manager = $this->manager(['retry_after' => 60]);

        $this->assertSame(60, $manager->resolveRetryAfter());
    }

    public function test_calculate_running_for_adjusts_for_retry_after(): void
    {
        // retry_after=90, reserved 10s ago → stored score = now + 80 (expiry)
        // running_for should be 10
        config()->set('queue.connections.redis.retry_after', 90);

        $now = 1_000_000;
        $reservedScore = $now + 80;

        $this->assertSame(10, $this->manager()->calculateRunningForSeconds($reservedScore, $now));
    }

    public function test_calculate_running_for_clamps_negative_to_zero(): void
    {
        // Edge case: clock skew or released-with-delay — guard against negatives
        config()->set('queue.connections.redis.retry_after', 60);

        $now = 1_000_000;
        $reservedScore = $now + 120; // expiry further than retry_after ahead

        $this->assertSame(0, $this->manager()->calculateRunningForSeconds($reservedScore, $now));
    }

    public function test_cache_epoch_starts_at_zero(): void
    {
        $manager = $this->manager(['cache' => ['enabled' => true, 'prefix' => 'hrj_test']]);

        $this->assertSame(0, $manager->getCacheEpoch());
    }

    public function test_clear_cache_increments_epoch(): void
    {
        $manager = $this->manager(['cache' => ['enabled' => true, 'prefix' => 'hrj_test']]);

        $manager->clearCache();
        $manager->clearCache();

        $this->assertSame(2, $manager->getCacheEpoch());
    }

    public function test_cache_key_includes_epoch_and_changes_when_cleared(): void
    {
        $manager = $this->manager(['cache' => ['enabled' => true, 'prefix' => 'hrj_test']]);

        $before = $manager->buildCacheKey('host-1', false, ['default']);
        $manager->clearCache();
        $after = $manager->buildCacheKey('host-1', false, ['default']);

        $this->assertNotSame($before, $after);
        $this->assertStringContainsString(':v0:', $before);
        $this->assertStringContainsString(':v1:', $after);
    }

    public function test_resolve_job_status_returns_running_for_fresh_reservation(): void
    {
        $now = 1_000_000;
        $reservedScore = $now + 60; // expiry in the future — normal

        $this->assertSame('running', $this->manager()->resolveJobStatus($reservedScore, $now));
    }

    public function test_resolve_job_status_returns_zombie_when_reservation_expired(): void
    {
        $now = 1_000_000;
        $reservedScore = $now - 10; // expiry already passed

        $this->assertSame('zombie', $this->manager()->resolveJobStatus($reservedScore, $now));
    }

    public function test_parse_job_data_throws_on_malformed_json(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed/i');

        $this->manager()->parseJobData('not-json', 100, 'default', 'host', time(), true);
    }

    public function test_parse_job_data_throws_when_data_command_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager()->parseJobData('{"uuid":"abc"}', 100, 'default', 'host', time(), true);
    }

    public function test_parse_job_data_returns_null_when_filtered_by_server(): void
    {
        $payload = json_encode([
            'uuid' => 'abc',
            'displayName' => 'App\\Jobs\\Test',
            'tags' => ['server:other-host'],
            'data' => ['command' => ''],
        ]);

        $result = $this->manager()->parseJobData($payload, time() + 90, 'default', 'this-host', time(), false);

        $this->assertNull($result);
    }

    public function test_parse_job_data_succeeds_for_valid_payload(): void
    {
        $payload = json_encode([
            'uuid' => 'abc-123',
            'displayName' => 'App\\Jobs\\Test',
            'tags' => ['server:this-host'],
            'data' => ['command' => ''],
            'attempts' => 1,
            'timeout' => 60,
        ]);

        $result = $this->manager()->parseJobData($payload, time() + 90, 'default', 'this-host', time(), false);

        $this->assertIsArray($result);
        $this->assertSame('abc-123', $result['job_id']);
        $this->assertSame('running', $result['status']);
    }

    public function test_malformed_reserved_jobs_are_logged_and_counted_as_dropped(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->with('queues:default:reserved')->andReturn(2);
        $redis->shouldReceive('exists')->with('queues:default:reserved')->andReturn(true);
        $redis->shouldReceive('zrange')->andReturn([
            'not-valid-json' => (float) (time() + 90),
            '{"only":"partial","no":"data"}' => (float) (time() + 90),
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);

        Log::shouldReceive('warning')
            ->twice()
            ->with(
                'HorizonRunningJobs: dropped malformed reserved job',
                \Mockery::on(fn ($context) =>
                    ($context['queue'] ?? null) === 'default'
                    && !empty($context['error'])
                )
            );

        $result = $this->manager(['queues' => ['default']])->getRunningJobs(null, true, ['default']);

        $this->assertSame(2, $result['dropped_count']);
        $this->assertSame([], $result['jobs']);
        $this->assertSame(2, $result['total_count']);
    }

    public function test_get_default_queues_finds_hostname_keyed_supervisor(): void
    {
        // Regression for the dot-in-hostname config-path bug:
        // gethostname() can return strings like "Foo.local" which break
        // config('horizon.defaults.' . gethostname()) via dot-notation.
        $hostname = gethostname();
        config()->set('horizon.defaults', [
            $hostname => ['queue' => ['emails', 'reports']],
        ]);
        config()->set('horizon-running-jobs.queues', null);

        $queues = $this->manager(['queues' => null])->getDefaultQueues();

        $this->assertEqualsCanonicalizing(['emails', 'reports'], $queues);
    }

    public function test_clear_cache_persists_incremented_epoch(): void
    {
        Cache::shouldReceive('get')->with('hrj_test:epoch', 0)->andReturn(0, 1, 2);
        Cache::shouldReceive('forever')->with('hrj_test:epoch', 1)->once();
        Cache::shouldReceive('forever')->with('hrj_test:epoch', 2)->once();

        $manager = $this->manager(['cache' => ['enabled' => true, 'prefix' => 'hrj_test']]);

        $manager->clearCache();
        $manager->clearCache();

        // Mockery expectations above are asserted via Mockery::close() in tearDown()
        $this->addToAssertionCount(3);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
