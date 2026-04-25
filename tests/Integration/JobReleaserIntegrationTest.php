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
        $this->redis()->zadd('supervisors', time() + 90, 'master:web-01');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'healthy', 'tags' => ['server:web-01']]));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'orphan-1', 'tags' => ['server:web-99']]));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'orphan-2', 'tags' => ['server:web-99']]));

        $found = $this->releaser()->findReleasable(['orphaned' => true]);

        $ids = array_column($found, 'job_id');
        sort($ids);
        $this->assertSame(['orphan-1', 'orphan-2'], $ids);
        foreach ($found as $row) {
            $this->assertSame('orphaned', $row['reason']);
        }
    }

    public function test_findReleasable_zombie_returns_only_expired_reservations(): void
    {
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'zombie-1', 'tags' => ['server:web-01']]), expiresAt: time() - 30);
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'live-1', 'tags' => ['server:web-01']]), expiresAt: time() + 60);

        $found = $this->releaser()->findReleasable(['zombie' => true]);

        $this->assertCount(1, $found);
        $this->assertSame('zombie-1', $found[0]['job_id']);
        $this->assertSame('zombie', $found[0]['reason']);
    }

    public function test_findReleasable_with_queue_filter_excludes_other_queues(): void
    {
        $this->redis()->zadd('supervisors', time() + 90, 'master:web-01');

        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'd-1', 'tags' => ['server:web-99']]));
        $this->seedReservedJob('emails', $this->makePayload(['uuid' => 'e-1', 'tags' => ['server:web-99']]));

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
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'a', 'tags' => ['server:web-01']]));
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'b', 'tags' => ['server:web-01']]));

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['orphaned' => true]);
        $count = $releaser->release($found);

        $this->assertSame(2, $count);
    }

    public function test_released_job_lands_at_front_of_pending_list(): void
    {
        // Pre-existing pending job that workers would normally process first.
        $this->redis()->rpush('queues:default', json_encode(['uuid' => 'pending-first']));

        $this->seedReservedJob('default', $this->makePayload([
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
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'atom-1', 'tags' => ['server:web-99']]));

        $releaser = $this->releaser();
        $found = $releaser->findReleasable(['orphaned' => true]);
        $releaser->release($found);

        $this->assertSame(0, $this->redis()->zcard('queues:default:reserved'));
        $this->assertSame(1, $this->redis()->llen('queues:default'));
    }

    private function releaser(): JobReleaser
    {
        return new JobReleaser(
            $this->app['redis'],
            new RunningJobsManager([
                'redis_connection' => 'default',
                'queues' => ['default', 'emails', 'reports'],
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
