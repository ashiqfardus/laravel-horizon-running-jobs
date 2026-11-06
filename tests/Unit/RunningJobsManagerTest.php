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

    public function test_resolve_retry_after_defaults_to_90_when_no_queue_connection_matches(): void
    {
        config()->set('queue.connections', ['sync' => ['driver' => 'sync']]);

        $this->assertSame(90, $this->manager()->resolveRetryAfter());
        $this->assertTrue($this->manager()->reservationsExpire());
    }

    public function test_resolve_retry_after_is_60_when_the_matching_connection_has_no_retry_after_key(): void
    {
        // Laravel's and Horizon's Redis connectors both default to 60.
        config()->set('queue.connections', ['redis' => ['driver' => 'redis', 'connection' => 'default']]);

        $this->assertSame(60, $this->manager()->resolveRetryAfter());
    }

    public function test_null_retry_after_means_reservations_never_expire(): void
    {
        // Horizon keeps the null: jobs are reserved with score = reservation
        // time and are never re-queued, so none of them is a zombie.
        config()->set('queue.connections', ['redis' => ['driver' => 'redis', 'connection' => 'default', 'retry_after' => null]]);

        $manager = $this->manager();
        $now = time();

        $this->assertFalse($manager->reservationsExpire());
        $this->assertSame(0, $manager->resolveRetryAfter());
        $this->assertSame('running', $manager->resolveJobStatus($now - 600, $now));
        $this->assertSame(600, $manager->calculateRunningForSeconds($now - 600, $now));
    }

    public function test_resolve_retry_after_matches_wildcard_environment_keys_like_horizon(): void
    {
        $this->app['env'] = 'production-eu';
        config()->set('horizon.use', 'default');
        config()->set('queue.connections.long', ['driver' => 'redis', 'connection' => 'other', 'retry_after' => 3600]);
        config()->set('horizon.environments', ['production*' => ['supervisor-1' => ['connection' => 'long']]]);

        $this->assertSame(3600, $this->manager()->resolveRetryAfter());
    }

    public function test_horizon_env_overrides_the_app_environment(): void
    {
        config()->set('horizon.env', 'staging');
        config()->set('queue.connections.long', ['driver' => 'redis', 'connection' => 'other', 'retry_after' => 1800]);
        config()->set('horizon.environments', [
            'testing' => ['supervisor-1' => ['connection' => 'redis']],
            'staging' => ['staging-box' => ['connection' => 'long']],
        ]);

        $manager = $this->manager();

        $this->assertSame(1800, $manager->resolveRetryAfter());
        $this->assertSame('staging-box', $manager->getServerIdentifier());
    }

    public function test_server_identifier_uses_wildcard_environment_keys(): void
    {
        $this->app['env'] = 'production-eu';
        config()->set('horizon.defaults', ['default-key' => []]);
        config()->set('horizon.environments', ['production*' => ['eu-worker' => []]]);

        $this->assertSame('eu-worker', $this->manager()->getServerIdentifier());
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

    public function test_resolve_retry_after_reads_the_queue_connection_not_the_horizon_redis_connection(): void
    {
        // horizon.use names a Redis connection (database.redis.default);
        // retry_after lives on the queue connection (queue.connections.redis).
        config()->set('horizon.use', 'default');
        config()->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'retry_after' => 1900,
        ]);

        $this->assertSame(1900, $this->manager()->resolveRetryAfter());
    }

    public function test_resolve_retry_after_prefers_the_queue_connection_horizon_supervisors_run_on(): void
    {
        config()->set('horizon.use', 'default');
        config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 90]);
        config()->set('queue.connections.long', ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 3600]);
        config()->set('horizon.defaults', ['supervisor-1' => ['connection' => 'long', 'queue' => ['default']]]);
        // An environment override that omits `connection` must not hide the default's.
        config()->set('horizon.environments.testing', ['supervisor-1' => ['maxProcesses' => 3]]);

        $this->assertSame(3600, $this->manager()->resolveRetryAfter());
    }

    public function test_resolve_retry_after_lets_the_environment_override_the_default_connection(): void
    {
        // Horizon provisions array_replace_recursive(defaults, environment).
        config()->set('horizon.use', 'default');
        config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 90]);
        config()->set('queue.connections.long', ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 3600]);
        config()->set('horizon.defaults', ['supervisor-1' => ['connection' => 'redis']]);
        config()->set('horizon.environments.testing', ['supervisor-1' => ['connection' => 'long']]);

        $this->assertSame(3600, $this->manager()->resolveRetryAfter());
    }

    public function test_effective_timeout_follows_the_worker_rules(): void
    {
        config()->set('horizon.environments.testing', [
            'fast' => ['timeout' => 30],
            'slow' => ['timeout' => 900],
        ]);
        $manager = $this->manager();

        // The job's own timeout wins, including an explicit 0 (no timeout).
        $this->assertSame(120, $manager->effectiveTimeout(['timeout' => 120], 'host-x1:fast'));
        $this->assertSame(0, $manager->effectiveTimeout(['timeout' => 0], 'host-x1:fast'));

        // Else the timeout of the supervisor running it.
        $this->assertSame(30, $manager->effectiveTimeout(['timeout' => null], 'host-x1:fast'));

        // Without a worker record (or an unknown key), the largest one.
        $this->assertSame(900, $manager->effectiveTimeout([], null));
        $this->assertSame(900, $manager->effectiveTimeout([], 'host-x1:retired-key'));
    }

    public function test_effective_timeout_is_zero_when_any_candidate_supervisor_has_none(): void
    {
        config()->set('horizon.environments.testing', ['a' => ['timeout' => 60], 'b' => ['timeout' => 0]]);

        $this->assertSame(0, $this->manager()->effectiveTimeout([], null));
        $this->assertSame(60, $this->manager()->effectiveTimeout([], 'host-x1:a'));
    }

    public function test_effective_timeout_defaults_to_horizons_60(): void
    {
        config()->set('horizon.environments.testing', ['a' => []]);
        $this->assertSame(60, $this->manager()->effectiveTimeout([], 'host-x1:a'));

        config()->set('horizon.environments', []);
        $this->assertSame(60, $this->manager()->effectiveTimeout([], null));
    }

    public function test_parsed_row_says_when_release_becomes_safe(): void
    {
        $now = time();
        $payload = json_encode(['uuid' => 'j', 'displayName' => 'X', 'timeout' => 60, 'data' => ['command' => 'x'], 'tags' => []]);
        $manager = $this->manager(['retry_after' => 90]);

        // Reserved 30s ago, no worker record: releasable once both past its
        // timeout (+70s from start) and expired (score + 1), whichever is later.
        $row = $manager->parseJobData($payload, $now + 60, 'default', 'h', $now, true);
        $this->assertFalse($row['is_releasable']);
        $this->assertSame($now + 61, $row['releasable_at']);

        // With a worker record for this attempt, past timeout is enough.
        $attempt1 = json_encode(['uuid' => 'j', 'timeout' => 60, 'attempts' => 1, 'data' => ['command' => 'x']]);
        $recorded = ['supervisor' => 'gone-x1:h', 'started_at' => $now - 30, 'attempts' => 1, 'timeout' => 60];
        $row = $manager->parseJobData($attempt1, $now + 60, 'default', 'h', $now, true, $recorded);
        $this->assertSame('gone-x1:h', $row['supervisor']);
        $this->assertSame($now - 30 + 70, $row['releasable_at']);

        // Expired 30s ago (started 120s ago): zombie, past timeout + margin.
        $row = $manager->parseJobData($payload, $now - 30, 'default', 'h', $now, true);
        $this->assertSame('zombie', $row['status']);
        $this->assertTrue($row['is_releasable']);

        // Same, but the job has no timeout: never releasable.
        $endless = json_encode(['uuid' => 'j', 'timeout' => 0, 'data' => ['command' => 'x']]);
        $row = $manager->parseJobData($endless, $now - 30, 'default', 'h', $now, true);
        $this->assertFalse($row['is_releasable']);
        $this->assertNull($row['releasable_at']);
    }

    public function test_release_margin_is_configurable(): void
    {
        $now = time();
        $payload = json_encode(['uuid' => 'j', 'timeout' => 60, 'attempts' => 1, 'data' => ['command' => 'x']]);
        $record = ['supervisor' => 'gone-x1:h', 'started_at' => $now - 100, 'attempts' => 1, 'timeout' => 60];

        $row = $this->manager(['release_margin_seconds' => 60])->parseJobData($payload, $now + 1000, 'default', 'h', $now, true, $record);

        $this->assertSame($now - 100 + 60 + 60, $row['releasable_at']);
        $this->assertSame(0, $this->manager(['release_margin_seconds' => -5])->releaseMarginSeconds());
    }

    public function test_live_supervisor_lookup_abstains_on_a_non_array_redis_reply(): void
    {
        // phpredis can answer false instead of throwing. Treating that as
        // "no supervisor is alive" would flag every tagged job as orphaned.
        $horizon = \Mockery::mock();
        $horizon->shouldReceive('zrangebyscore')->andReturn(false);
        $this->app->instance('redis', \Mockery::mock()->shouldReceive('connection')->with('horizon')->andReturn($horizon)->getMock());

        $manager = $this->manager();

        $this->assertNull($manager->getLiveSupervisorNames());
        $this->assertFalse($manager->isJobOrphaned('web-01', $manager->getLiveSupervisorNames()));
    }

    public function test_resolve_retry_after_ignores_queue_connections_on_another_redis_connection(): void
    {
        config()->set('horizon.use', 'default');
        config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'other', 'retry_after' => 600]);

        $this->assertSame(90, $this->manager()->resolveRetryAfter());
    }

    public function test_without_cache_bypasses_the_cache_and_restores_the_setting(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('zcard')->andReturn(0);
        $redis->shouldReceive('exists')->andReturn(false);
        Redis::shouldReceive('connection')->andReturn($redis);

        Cache::shouldReceive('get')->with('hrj_test:epoch', 0)->andReturn(0);
        // Exactly one cached read: the call made after withoutCache() returns.
        Cache::shouldReceive('remember')->once()->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

        $manager = $this->manager(['cache' => ['enabled' => true, 'ttl' => 10, 'prefix' => 'hrj_test']]);

        $fresh = $manager->withoutCache(fn (RunningJobsManager $m) => $m->getRunningJobs(null, true, ['default']));
        $cached = $manager->getRunningJobs(null, true, ['default']);

        $this->assertSame([], $fresh['jobs']);
        $this->assertSame([], $cached['jobs']);
    }

    public function test_without_cache_restores_the_setting_when_the_callback_throws(): void
    {
        $manager = $this->manager(['cache' => ['enabled' => true, 'prefix' => 'hrj_test']]);

        try {
            $manager->withoutCache(fn () => throw new \RuntimeException('boom'));
            $this->fail('exception should propagate');
        } catch (\RuntimeException) {
        }

        Cache::shouldReceive('get')->andReturn(0);
        Cache::shouldReceive('remember')->once()->andReturn(['jobs' => []]);

        $this->assertSame(['jobs' => []], $manager->getRunningJobs(null, true, ['default']));
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

    public function test_is_job_orphaned_returns_false_for_unknown_server_tag(): void
    {
        $this->assertFalse($this->manager()->isJobOrphaned('unknown', []));
    }

    public function test_is_job_orphaned_returns_false_when_tag_matches_live_supervisor(): void
    {
        $live = ['ashiqs-macbook-prolocal-XXX:web-01'];

        $this->assertFalse($this->manager()->isJobOrphaned('web-01', $live));
    }

    public function test_is_job_orphaned_returns_false_for_exact_supervisor_name_match(): void
    {
        $live = ['supervisor-01'];

        $this->assertFalse($this->manager()->isJobOrphaned('supervisor-01', $live));
    }

    public function test_is_job_orphaned_returns_true_when_tag_not_in_live_list(): void
    {
        $live = ['master-A:web-01', 'master-B:web-02'];

        $this->assertTrue($this->manager()->isJobOrphaned('web-03', $live));
    }

    public function test_is_job_orphaned_returns_true_when_live_list_is_empty(): void
    {
        $this->assertTrue($this->manager()->isJobOrphaned('web-01', []));
    }

    public function test_is_job_orphaned_abstains_when_lookup_unavailable(): void
    {
        // null = the live-supervisor lookup couldn't run; treat as unknown,
        // not orphaned, so we don't false-positive every job.
        $this->assertFalse($this->manager()->isJobOrphaned('web-01', null));
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
