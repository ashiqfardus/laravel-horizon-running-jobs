<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Integration;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\IntegrationTestCase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;

/**
 * Worker records: a Horizon worker writes which supervisor runs a job when it
 * starts it, so the package does not depend on tags written at dispatch time.
 */
class WorkerTrackingIntegrationTest extends IntegrationTestCase
{
    private array $originalArgv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalArgv = $_SERVER['argv'] ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;
        parent::tearDown();
    }

    // ---- writing ---------------------------------------------------------

    public function test_horizon_worker_records_its_supervisor_when_it_starts_a_job(): void
    {
        $this->runAsHorizonWorker('host-b-x1:worker-node');

        event(new JobProcessing('redis', $this->redisJob('job-1')));

        $record = json_decode((string) $this->redis()->get($this->key('job-1')), true);
        $this->assertSame('host-b-x1:worker-node', $record['supervisor']);
        $this->assertEqualsWithDelta(time(), $record['started_at'], 2);

        $ttl = $this->redis()->ttl($this->key('job-1'));
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(RunningJobsManager::WORKER_RECORD_TTL, $ttl);
    }

    public function test_record_is_removed_when_the_job_finishes_fails_or_is_released(): void
    {
        $this->runAsHorizonWorker('host-b-x1:worker-node');

        $finished = [
            'processed' => fn ($job) => new JobProcessed('redis', $job),
            'failed' => fn ($job) => new JobFailed('redis', $job, new \RuntimeException('boom')),
        ];

        // The event arrived in Laravel 9.21; the package supports 9.0.
        if (class_exists(JobReleasedAfterException::class)) {
            $finished['released'] = fn ($job) => new JobReleasedAfterException('redis', $job);
        }

        foreach ($finished as $name => $makeEvent) {
            $job = $this->redisJob("job-{$name}");
            event(new JobProcessing('redis', $job));
            $this->assertSame(1, $this->redis()->exists($this->key("job-{$name}")), $name);

            event($makeEvent($job));
            $this->assertSame(0, $this->redis()->exists($this->key("job-{$name}")), $name);
        }
    }

    public function test_worker_outside_horizon_removes_a_record_left_by_an_earlier_attempt(): void
    {
        $this->redis()->set($this->key('job-1'), json_encode(['supervisor' => 'dead-x1:worker-node', 'started_at' => time() - 600]));

        $_SERVER['argv'] = ['artisan', 'queue:work', 'redis'];
        event(new JobProcessing('redis', $this->redisJob('job-1')));

        $this->assertSame(0, $this->redis()->exists($this->key('job-1')));
    }

    public function test_nothing_is_recorded_when_tracking_is_off(): void
    {
        config()->set('horizon-running-jobs.track_workers', false);
        $this->app->forgetInstance(RunningJobsManager::class);
        $this->runAsHorizonWorker('host-b-x1:worker-node');

        event(new JobProcessing('redis', $this->redisJob('job-1')));

        $this->assertSame(0, $this->redis()->exists($this->key('job-1')));
    }

    public function test_a_redis_error_while_recording_never_reaches_the_job(): void
    {
        $this->runAsHorizonWorker('host-b-x1:worker-node');

        $brokenQueue = new class($this->app['redis'], 'default', 'default') extends RedisQueue {
            public function getConnection()
            {
                throw new \RuntimeException('connection reset');
            }
        };
        $job = new RedisJob($this->app, $brokenQueue, $this->payloadJson('job-1'), $this->payloadJson('job-1'), 'redis', 'default');

        event(new JobProcessing('redis', $job));
        event(new JobProcessed('redis', $job));

        $this->addToAssertionCount(1); // reaching this line is the assertion
    }

    public function test_supervisor_option_is_only_read_inside_horizon_work(): void
    {
        $manager = new class(['redis_connection' => 'default']) extends RunningJobsManager {
            public function supervisorFromArgv(): ?string
            {
                return $this->currentWorkerSupervisor();
            }
        };

        $_SERVER['argv'] = ['artisan', 'horizon:work', 'redis', '--name=default', '--supervisor=m-x1:key'];
        $this->assertSame('m-x1:key', $manager->supervisorFromArgv());

        $_SERVER['argv'] = ['artisan', 'horizon:work', 'redis', '--supervisor='];
        $this->assertNull($manager->supervisorFromArgv());

        $_SERVER['argv'] = ['artisan', 'queue:work', '--supervisor=m-x1:key'];
        $this->assertNull($manager->supervisorFromArgv());

        $_SERVER['argv'] = ['artisan', 'horizon'];
        $this->assertNull($manager->supervisorFromArgv());
    }

    // ---- reading ---------------------------------------------------------

    public function test_job_dispatched_elsewhere_is_attributed_to_the_worker_that_runs_it(): void
    {
        // Dispatched on a web node (no Horizon there), run by host B.
        $this->redis()->zadd('supervisors', time(), 'host-b-x1:worker-node');
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'tags' => ['server:web-node']]));
        $this->record('job-1', 'host-b-x1:worker-node');

        $job = $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0];

        $this->assertSame('worker-node', $job['server']);
        $this->assertSame('host-b-x1:worker-node', $job['supervisor']);
        $this->assertFalse($job['is_orphaned'], 'the tag names the dispatcher; the job is running fine');
    }

    public function test_hosts_sharing_a_supervisor_key_no_longer_vouch_for_each_other(): void
    {
        // Stock config: every host names its supervisor "supervisor-1".
        // Host A is alive, host B died while running job-b.
        $this->redis()->zadd('supervisors', time(), 'host-a-x1:supervisor-1');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-b', 'tags' => ['server:supervisor-1']]));
        $this->record('job-b', 'host-b-x1:supervisor-1');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-untracked', 'tags' => ['server:supervisor-1']]));

        $jobs = collect($this->manager()->getRunningJobs(null, true, ['default'])['jobs'])->keyBy('job_id');

        $this->assertTrue($jobs['job-b']['is_orphaned'], 'host B is gone even though host A shares its key');
        // Without a worker record the tag's suffix match still applies.
        $this->assertFalse($jobs['job-untracked']['is_orphaned']);
        $this->assertNull($jobs['job-untracked']['supervisor']);
    }

    public function test_reaped_supervisor_still_listed_by_its_live_master_is_live(): void
    {
        $this->redis()->zadd('masters', time(), 'host-b-x1');
        $this->redis()->hset('master:host-b-x1', 'supervisors', json_encode(['host-b-x1:worker-node']));

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'tags' => ['server:web-node']]));
        $this->record('job-1', 'host-b-x1:worker-node');

        $this->assertFalse($this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0]['is_orphaned']);
    }

    public function test_start_time_comes_from_the_worker_record_not_from_retry_after(): void
    {
        // A retry_after the package cannot know about must not skew the duration.
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1']), expiresAt: time() + 3000);
        $this->record('job-1', 'host-b-x1:worker-node', startedAt: time() - 125);

        $job = $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0];

        $this->assertEqualsWithDelta(125, $job['running_for_seconds'], 2);
        $this->assertEqualsWithDelta(time() - 125, $job['start_timestamp'], 2);
    }

    public function test_distributed_mode_shows_each_instance_the_jobs_its_workers_run(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'run-here', 'tags' => ['server:web-node']]));
        $this->record('run-here', 'host-b-x1:worker-node');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'dispatched-here', 'tags' => ['server:worker-node']]));
        $this->record('dispatched-here', 'host-c-x1:other-node');

        $result = $this->manager(['distributed' => true, 'server_identifier' => 'worker-node'])
            ->getRunningJobs('worker-node', false, ['default']);

        $this->assertSame(['run-here'], array_column($result['jobs'], 'job_id'));
    }

    public function test_record_from_an_earlier_attempt_is_ignored(): void
    {
        // Attempt 1's worker was killed; attempt 2 has just been reserved and
        // its own record is not written yet. The old record must not speak for it.
        $this->redis()->zadd('supervisors', time(), 'host-a-x1:web-node');
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'attempts' => 2, 'tags' => ['server:web-node']]));
        $this->record('job-1', 'dead-x1:web-node', startedAt: time() - 3600, attempts: 1);

        $job = $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0];

        $this->assertNull($job['supervisor']);
        $this->assertFalse($job['is_orphaned']);
        $this->assertFalse($job['is_releasable']);
    }

    public function test_worker_records_the_attempt_and_the_timeout_it_enforces(): void
    {
        // No $timeout on the job: the worker enforces its --timeout option.
        $_SERVER['argv'] = ['artisan', 'horizon:work', 'redis', '--name=default', '--supervisor=host-b-x1:worker-node', '--timeout=3600'];

        event(new JobProcessing('redis', $this->redisJob('job-1', timeout: null)));

        $record = json_decode((string) $this->redis()->get($this->key('job-1')), true);
        $this->assertSame(1, $record['attempts']);
        $this->assertSame(3600, $record['timeout']);
        // The record outlives the job's timeout.
        $this->assertGreaterThanOrEqual(3600, $this->redis()->ttl($this->key('job-1')));

        // The job's own $timeout wins, as in Worker::timeoutForJob().
        event(new JobProcessing('redis', $this->redisJob('job-2', timeout: 45)));
        $this->assertSame(45, json_decode((string) $this->redis()->get($this->key('job-2')), true)['timeout']);
    }

    public function test_record_ttl_covers_jobs_longer_than_a_day(): void
    {
        $this->runAsHorizonWorker('host-b-x1:worker-node');

        event(new JobProcessing('redis', $this->redisJob('marathon', timeout: 172800)));

        $this->assertGreaterThan(RunningJobsManager::WORKER_RECORD_TTL, $this->redis()->ttl($this->key('marathon')));
    }

    public function test_recorded_timeout_wins_over_this_processs_config(): void
    {
        // A deploy lowered the supervisor timeout to 300, but the draining
        // worker still enforces the 3600 it started with.
        config()->set('horizon.defaults', ['worker-node' => ['timeout' => 300]]);
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'timeout' => null]), expiresAt: time() + 3000);
        $this->record('job-1', 'host-b-x1:worker-node', startedAt: time() - 400, timeout: 3600);

        $job = $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0];

        $this->assertTrue($job['is_orphaned']);
        $this->assertFalse($job['is_releasable'], 'the worker may run it until +3600');
        $this->assertEqualsWithDelta(time() - 400 + 3600 + 10, $job['releasable_at'], 2);
    }

    public function test_records_are_ignored_when_tracking_is_off(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'tags' => ['server:web-node']]));
        $this->record('job-1', 'host-b-x1:worker-node');

        $job = $this->manager(['track_workers' => false])->getRunningJobs(null, true, ['default'])['jobs'][0];

        $this->assertSame('web-node', $job['server']);
        $this->assertNull($job['supervisor']);
    }

    public function test_malformed_record_falls_back_to_tags(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'tags' => ['server:web-node']]));
        $this->redis()->set($this->key('job-1'), 'not json');

        $this->assertSame('web-node', $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0]['server']);
    }

    public function test_round_trip_through_real_queue_events_and_the_manager(): void
    {
        // What a Horizon worker does: pop (reserve), JobProcessing, then the
        // dashboard reads the reserved set while the job runs.
        $this->runAsHorizonWorker('host-b-x1:worker-node');
        $this->redis()->zadd('supervisors', time(), 'host-b-x1:worker-node');

        $queue = new RedisQueue($this->app['redis'], 'default', 'default', 90);
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');
        $this->redis()->rpush('queues:default', $this->payloadJson('job-1', ['server:web-node']));

        $job = $queue->pop('default');
        $this->assertInstanceOf(RedisJob::class, $job);
        event(new JobProcessing('redis', $job));

        $running = $this->manager()->getRunningJobs(null, true, ['default'])['jobs'][0];
        $this->assertSame('job-1', $running['job_id']);
        $this->assertSame('host-b-x1:worker-node', $running['supervisor']);
        $this->assertFalse($running['is_orphaned']);

        $job->delete();
        event(new JobProcessed('redis', $job));
        $this->assertSame(0, $this->redis()->exists($this->key('job-1')));
    }

    // ---- helpers ---------------------------------------------------------

    private function runAsHorizonWorker(string $supervisor): void
    {
        $_SERVER['argv'] = ['artisan', 'horizon:work', 'redis', '--name=default', "--supervisor={$supervisor}", '--timeout=60'];
    }

    private function key(string $uuid): string
    {
        return RunningJobsManager::WORKER_KEY_PREFIX . $uuid;
    }

    private function record(string $uuid, string $supervisor, ?int $startedAt = null, int $attempts = 1, ?int $timeout = null): void
    {
        $this->redis()->set($this->key($uuid), json_encode([
            'supervisor' => $supervisor,
            'started_at' => $startedAt ?? time(),
            'attempts' => $attempts,
            'timeout' => $timeout,
        ]));
    }

    private function payloadJson(string $uuid, array $tags = [], ?int $timeout = 60): string
    {
        return json_encode($this->makePayload(['uuid' => $uuid, 'tags' => $tags, 'attempts' => 0, 'timeout' => $timeout]));
    }

    private function redisJob(string $uuid, ?int $timeout = 60): RedisJob
    {
        $queue = new RedisQueue($this->app['redis'], 'default', 'default');
        $payload = $this->payloadJson($uuid, [], $timeout);

        return new RedisJob($this->app, $queue, $payload, $payload, 'redis', 'default');
    }

    private function manager(array $config = []): RunningJobsManager
    {
        return new RunningJobsManager(array_merge([
            'cache' => ['enabled' => false],
            'redis_connection' => 'default',
            'queues' => ['default'],
        ], $config));
    }
}
