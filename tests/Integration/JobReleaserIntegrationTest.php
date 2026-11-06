<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Integration;

use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\IntegrationTestCase;
use Illuminate\Support\Facades\Log;

class JobReleaserIntegrationTest extends IntegrationTestCase
{
    public function test_release_by_id_moves_reserved_entry_back_to_pending(): void
    {
        $this->seedReservedJob('default', $this->makePayload([
            'uuid' => 'release-target',
            'tags' => ['server:web-01'],
        ]));

        $releaser = $this->releaser();
        $releaser->release([
            ['job_id' => 'release-target', 'queue' => 'default', 'reason' => 'manual', 'payload' => $this->payloadFor('release-target', 'default')],
        ]);

        $this->assertSame(0, $this->redis()->zcard('queues:default:reserved'));
        $this->assertSame(1, $this->redis()->llen('queues:default'));
    }

    public function test_release_pushes_nothing_when_the_job_left_the_reserved_set_after_find(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'finished-meanwhile', 'tags' => ['server:web-01']]));

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['job_id' => 'finished-meanwhile']);
        $this->assertCount(1, $found);

        // The worker finishes the job before the operator confirms.
        $this->redis()->zrem('queues:default:reserved', $found[0]['payload']);

        $this->assertSame(0, $releaser->release($found));
        $this->assertSame(0, $this->redis()->llen('queues:default'), 'a finished job must not be pushed back');
        $this->assertSame(0, $this->redis()->llen('queues:default:notify'));
    }

    public function test_release_wakes_blocked_workers_like_laravel_does(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'wake-up', 'tags' => ['server:web-01']]));

        $releaser = $this->releaser();
        $this->assertSame(1, $releaser->release($releaser->findReleasable(['job_id' => 'wake-up'])));

        $this->assertSame(1, $this->redis()->llen('queues:default:notify'));
    }

    public function test_releasing_the_same_items_twice_pushes_once(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'double-click', 'tags' => ['server:web-01']]));

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['job_id' => 'double-click']);

        $this->assertSame(1, $releaser->release($found));
        $this->assertSame(0, $releaser->release($found));
        $this->assertSame(1, $this->redis()->llen('queues:default'));
    }

    public function test_orphan_release_stops_when_the_live_supervisor_lookup_fails(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'running-job', 'tags' => ['server:web-01']]));

        $manager = new class(['redis_connection' => 'default', 'queues' => ['default']]) extends RunningJobsManager {
            protected function getHorizonRedisConnection()
            {
                throw new \RuntimeException('horizon connection reset');
            }
        };

        $this->expectException(\Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable::class);

        try {
            (new JobReleaser($this->app['redis'], $manager))->findReleasable(['orphaned' => true]);
        } finally {
            $this->assertSame(1, $this->redis()->zcard('queues:default:reserved'));
        }
    }

    public function test_orphan_release_reads_supervisors_fresh_instead_of_reusing_an_earlier_answer(): void
    {
        $this->redis()->zadd('supervisors', time(), 'master:web-01');
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'job-1', 'tags' => ['server:web-01']]));

        $manager = new RunningJobsManager(['redis_connection' => 'default', 'queues' => ['default']]);
        $releaser = new JobReleaser($this->app['redis'], $manager);

        // Earlier answer: web-01 has no live supervisor yet.
        $this->redis()->del('supervisors');
        $manager->refreshLiveSupervisorNames();

        // web-01 comes back before the release is requested.
        $this->redis()->zadd('supervisors', time(), 'master:web-01');

        $this->assertSame([], $releaser->findReleasable(['orphaned' => true]));
    }

    public function test_findReleasable_locates_job_by_id(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'find-me', 'tags' => ['server:web-01']]));
        $this->seedReservedJob('emails', $this->makePayload(['uuid' => 'other', 'tags' => ['server:web-01']]));

        $found = $this->releaser()->findReleasable(['job_id' => 'find-me']);

        $this->assertCount(1, $found);
        $this->assertSame('find-me', $found[0]['job_id']);
        $this->assertSame('default', $found[0]['queue']);
        $this->assertSame('manual', $found[0]['reason']);
    }

    public function test_findReleasable_orphaned_returns_only_orphan_jobs(): void
    {
        // Live supervisor for web-01; web-99 has no live supervisor → orphan.
        $this->redis()->zadd('supervisors', time(), 'master:web-01');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'healthy', 'tags' => ['server:web-01']]));
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'orphan-1', 'tags' => ['server:web-99']]));
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'orphan-2', 'tags' => ['server:web-99']]));

        $found = $this->releaser()->findReleasable(['orphaned' => true]);

        $ids = array_column($found, 'job_id');
        sort($ids);
        $this->assertSame(['orphan-1', 'orphan-2'], $ids);
        foreach ($found as $row) {
            $this->assertSame('orphaned', $row['reason']);
        }
    }

    public function test_findReleasable_orphaned_skips_jobs_of_a_supervisor_that_missed_a_few_heartbeats(): void
    {
        // Releasing a job that is still running makes it run twice. A
        // supervisor whose last heartbeat was 10s ago is alive by Horizon's
        // own 29s rule, so its jobs must not be offered for release.
        $this->redis()->zadd('supervisors', time() - 10, 'master:web-01');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'still-running', 'tags' => ['server:web-01']]));

        $this->assertSame([], $this->releaser()->findReleasable(['orphaned' => true]));
    }

    public function test_findReleasable_orphaned_skips_jobs_of_a_reaped_supervisor_whose_master_is_alive(): void
    {
        $this->redis()->zadd('masters', time(), 'master');
        $this->redis()->hset('master:master', 'supervisors', json_encode(['master:web-01']));

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'still-running', 'tags' => ['server:web-01']]));

        $this->assertSame([], $this->releaser()->findReleasable(['orphaned' => true]));
    }

    public function test_findReleasable_zombie_returns_only_expired_reservations(): void
    {
        // Reserved 120s ago with retry_after 90: expired, and past its 60s timeout.
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'zombie-1', 'tags' => ['server:web-01']]), expiresAt: time() - 30);
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'live-1', 'tags' => ['server:web-01']]), expiresAt: time() + 60);

        $found = $this->releaser()->findReleasable(['zombie' => true]);

        $this->assertCount(1, $found);
        $this->assertSame('zombie-1', $found[0]['job_id']);
        $this->assertSame('zombie', $found[0]['reason']);
    }

    public function test_zombie_release_finds_nothing_when_retry_after_is_disabled(): void
    {
        // With retry_after => null the score is the reservation time: a job
        // reserved a minute ago is running, not expired.
        config()->set('queue.connections.redis.retry_after', null);

        // Reserved an hour ago: past its timeout, so it would be released if
        // it were (wrongly) classified as a zombie.
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'long-runner', 'tags' => ['server:web-01']]), expiresAt: time() - 3600);

        $releaser = new JobReleaser($this->app['redis'], new RunningJobsManager([
            'redis_connection' => 'default',
            'queues' => ['reports'],
        ]));

        $this->assertSame([], $releaser->findReleasable(['zombie' => true]));
    }

    public function test_findReleasable_with_queue_filter_excludes_other_queues(): void
    {
        $this->redis()->zadd('supervisors', time(), 'master:web-01');

        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'd-1', 'tags' => ['server:web-99']]));
        $this->seedStuckOrphan('emails', $this->makePayload(['uuid' => 'e-1', 'tags' => ['server:web-99']]));

        $found = $this->releaser()->findReleasable(['orphaned' => true, 'queues' => ['emails']]);

        $this->assertCount(1, $found);
        $this->assertSame('e-1', $found[0]['job_id']);
    }

    public function test_release_logs_each_action(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with(
                'HorizonRunningJobs: released job',
                \Mockery::on(fn ($ctx) =>
                    $ctx['job_id'] === 'logged-1'
                    && $ctx['queue'] === 'default'
                    && $ctx['reason'] === 'manual'
                )
            );

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'logged-1', 'tags' => ['server:web-01']]));

        $found = $this->releaser()->findReleasable(['job_id' => 'logged-1']);
        $this->releaser()->release($found);
    }

    public function test_release_returns_count_of_successful_actions(): void
    {
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'a', 'tags' => ['server:web-01']]), 'gone-x1:web-01');
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'b', 'tags' => ['server:web-01']]), 'gone-x1:web-01');

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['orphaned' => true]);
        $count = $releaser->release($found);

        $this->assertSame(2, $count);
    }

    public function test_released_job_lands_at_front_of_pending_list(): void
    {
        // Pre-existing pending job that workers would normally process first.
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'pending-first']));

        $this->seedStuckOrphan('default', $this->makePayload([
            'uuid' => 'released-job',
            'tags' => ['server:web-99'],
        ]));

        $releaser = $this->releaser();
        $releaser->release($releaser->findReleasable(['orphaned' => true]));

        // LPOP returns the head of the list — the released job should pop first
        // so a worker picks it up before the pre-existing pending job.
        $head = json_decode($this->redis()->lpop('queues:default'), true);

        $this->assertSame('released-job', $head['uuid']);
    }

    public function test_release_is_atomic_per_job(): void
    {
        // After release: pending count + reserved count should equal the
        // pre-release reserved count, with no jobs lost.
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'atom-1', 'tags' => ['server:web-99']]));

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['orphaned' => true]);
        $releaser->release($found);

        $this->assertSame(0, $this->redis()->zcard('queues:default:reserved'));
        $this->assertSame(1, $this->redis()->llen('queues:default'));
    }

    // ---- release only when no worker can still be running the job --------

    public function test_orphan_is_offered_only_once_it_has_run_past_its_timeout(): void
    {
        // timeout 60 + 10s margin: releasable once it started 70s ago.
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'young', 'tags' => ['server:web-99']]), expiresAt: time() + 90 - 30);
        $this->recordWorker('young', 'gone-x1:web-99', time() - 30);
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'old', 'tags' => ['server:web-99']]), expiresAt: time() + 90 - 80);
        $this->recordWorker('old', 'gone-x1:web-99', time() - 80);

        $found = $this->releaser()->findReleasable(['orphaned' => true]);

        $this->assertSame(['old'], array_column($found, 'job_id'));
        $this->assertTrue($found[0]['safe']);
    }

    public function test_job_without_a_timeout_is_never_bulk_released(): void
    {
        // timeout 0: the worker never kills itself, so it may still be running.
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'endless', 'timeout' => 0, 'tags' => ['server:web-99']]), expiresAt: time() - 3600);

        $this->assertSame([], $this->releaser()->findReleasable(['orphaned' => true, 'zombie' => true]));
    }

    public function test_supervisor_timeout_applies_when_the_job_sets_none(): void
    {
        config()->set('horizon.defaults', ['supervisor-1' => ['connection' => 'redis', 'timeout' => 300]]);

        // Started 80s ago: past the 60s default, inside the supervisor's 300s.
        $this->seedStuckJob('default', $this->makePayload(['uuid' => 'slow', 'timeout' => null, 'tags' => ['server:web-99']]));

        $this->assertSame([], $this->releaser()->findReleasable(['orphaned' => true]));
    }

    public function test_zombie_still_inside_its_timeout_is_not_released(): void
    {
        // retry_after (90) shorter than the job's timeout (600): the reservation
        // expired but the worker may legitimately still be running it.
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'long-zombie', 'timeout' => 600]), expiresAt: time() - 30);

        $this->assertSame([], $this->releaser()->findReleasable(['zombie' => true]));
    }

    public function test_orphans_and_zombies_can_be_released_together(): void
    {
        $this->redis()->zadd('supervisors', time(), 'master:web-01');
        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'orphan', 'tags' => ['server:web-99']]));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'zombie', 'tags' => ['server:web-01']]), expiresAt: time() - 30);
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'healthy', 'tags' => ['server:web-01']]));

        $found = collect($this->releaser()->findReleasable(['orphaned' => true, 'zombie' => true]))->pluck('reason', 'job_id')->all();
        ksort($found);

        $this->assertSame(['orphan' => 'orphaned', 'zombie' => 'zombie'], $found);
    }

    public function test_jobs_still_draining_during_horizon_terminate_are_not_released(): void
    {
        // horizon:terminate deregisters supervisor and master first, then waits
        // for the workers to finish the job in hand. Nothing in Redis vouches
        // for the worker, but it is still running the job.
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'draining', 'tags' => ['server:web-01']]), expiresAt: time() + 85);
        $this->recordWorker('draining', 'host-a-x1:web-01', time() - 5);

        $releaser = $this->releaser();
        $this->assertSame([], $releaser->findReleasable(['orphaned' => true]));

        // Explicit by-id release still finds it, flagged unsafe.
        $manual = $releaser->findReleasable(['job_id' => 'draining']);
        $this->assertCount(1, $manual);
        $this->assertFalse($manual[0]['safe']);
    }

    public function test_orphan_without_a_worker_record_waits_until_it_has_also_expired(): void
    {
        // Without a record, start time, timeout and orphan status are inferred
        // from the payload and config. The job is released only once its
        // reservation has expired too, when Laravel would re-queue it anyway.
        $orphanTag = ['tags' => [], 'data' => ['command' => 'O:8:"App\\Job":1:{s:13:"supervisor_id";s:6:"web-99";}']];

        $this->seedStuckJob('default', $this->makePayload(['uuid' => 'not-expired'] + $orphanTag));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'expired'] + $orphanTag), expiresAt: time() - 30);

        $this->assertSame(['expired'], array_column($this->releaser()->findReleasable(['orphaned' => true, 'zombie' => true]), 'job_id'));
        // The expired one is an orphan too, so --orphaned alone takes it as well;
        // the unexpired one is never taken.
        $this->assertSame(['expired'], array_column($this->releaser()->findReleasable(['orphaned' => true]), 'job_id'));
    }

    public function test_release_uses_the_worker_record_for_hosts_sharing_a_supervisor_key(): void
    {
        $this->redis()->zadd('supervisors', time(), 'host-a-x1:supervisor-1');

        $this->seedStuckOrphan('default', $this->makePayload(['uuid' => 'host-b-job', 'tags' => ['server:supervisor-1']]), 'host-b-x1:supervisor-1');

        $this->assertSame(['host-b-job'], array_column($this->releaser()->findReleasable(['orphaned' => true]), 'job_id'));
    }

    /**
     * A reserved job that started 80s ago (retry_after 90): orphan candidates
     * built with makePayload()'s 60s timeout are past timeout + margin, while
     * the reservation itself has not expired.
     */
    private function seedStuckJob(string $queue, array $payload): void
    {
        $this->seedReservedJob($queue, $payload, expiresAt: time() + 90 - 80);
    }

    private function recordWorker(string $uuid, string $supervisor, int $startedAt, int $attempts = 1): void
    {
        $this->redis()->set(RunningJobsManager::WORKER_KEY_PREFIX . $uuid, json_encode([
            'supervisor' => $supervisor,
            'started_at' => $startedAt,
            'attempts' => $attempts,
        ]));
    }

    /**
     * A stuck orphan as a Horizon worker on this version leaves it: reserved
     * 80s ago, with a worker record naming a supervisor that is gone.
     */
    private function seedStuckOrphan(string $queue, array $payload, string $supervisor = 'gone-x1:web-99'): void
    {
        $this->seedStuckJob($queue, $payload);
        $this->recordWorker($payload['uuid'], $supervisor, time() - 80);
    }

    private function releaser(): JobReleaser
    {
        return new JobReleaser(
            $this->app['redis'],
            new RunningJobsManager([
                'redis_connection' => 'default',
                'queues' => ['default', 'emails', 'reports'],
                'retry_after' => 90,
            ])
        );
    }

    private function payloadFor(string $uuid, string $queue): string
    {
        $rows = $this->redis()->zrange("queues:{$queue}:reserved", 0, -1);
        foreach ($rows as $payload) {
            $decoded = json_decode($payload, true);
            if (($decoded['uuid'] ?? null) === $uuid) {
                return $payload;
            }
        }
        return '';
    }
}
