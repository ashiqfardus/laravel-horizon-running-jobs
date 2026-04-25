<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Integration;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\IntegrationTestCase;
use Illuminate\Support\Facades\Log;

class RunningJobsManagerIntegrationTest extends IntegrationTestCase
{
    public function test_returns_running_jobs_seeded_in_redis(): void
    {
        $this->seedReservedJob('default', $this->makePayload([
            'uuid' => 'job-1',
            'displayName' => 'App\\Jobs\\Order',
            'tags' => ['server:web-01'],
        ]), expiresAt: time() + 80);

        $manager = $this->manager(distributed: false);

        $result = $manager->getRunningJobs(null, true, ['default']);

        $this->assertSame(1, $result['total_count']);
        $this->assertSame(0, $result['dropped_count']);
        $this->assertSame('job-1', $result['jobs'][0]['job_id']);
        $this->assertSame('App\\Jobs\\Order', $result['jobs'][0]['job_class']);
        $this->assertSame('running', $result['jobs'][0]['status']);
        $this->assertGreaterThanOrEqual(0, $result['jobs'][0]['running_for_seconds']);
    }

    public function test_marks_jobs_with_expired_score_as_zombie(): void
    {
        $this->seedReservedJob('reports', $this->makePayload([
            'uuid' => 'zombie-1',
            'tags' => ['server:web-01'],
        ]), expiresAt: time() - 30);

        $result = $this->manager()->getRunningJobs(null, true, ['reports']);

        $this->assertSame(1, $result['total_count']);
        $this->assertSame('zombie', $result['jobs'][0]['status']);
        $this->assertContains(
            '1 zombie job(s) detected (reservation expired but still in queue)',
            $result['warnings']
        );
    }

    public function test_logs_and_counts_malformed_payloads(): void
    {
        Log::shouldReceive('warning')
            ->twice()
            ->with(
                'HorizonRunningJobs: dropped malformed reserved job',
                \Mockery::on(fn ($ctx) => ($ctx['queue'] ?? null) === 'default')
            );

        // Bypass our seedReservedJob helper so we can write malformed strings.
        $this->redis()->zadd('queues:default:reserved', time() + 80, 'not-json');
        $this->redis()->zadd('queues:default:reserved', time() + 80, '{"missing":"data.command"}');

        $result = $this->manager()->getRunningJobs(null, true, ['default']);

        $this->assertSame([], $result['jobs']);
        $this->assertSame(2, $result['dropped_count']);
        $this->assertContains('2 malformed job(s) skipped (see logs)', $result['warnings']);
    }

    public function test_aggregates_jobs_across_multiple_queues(): void
    {
        $this->seedReservedJob('default', $this->makePayload(['uuid' => 'd-1', 'tags' => ['server:web-01']]));
        $this->seedReservedJob('emails', $this->makePayload(['uuid' => 'e-1', 'tags' => ['server:web-01']]));
        $this->seedReservedJob('reports', $this->makePayload(['uuid' => 'r-1', 'tags' => ['server:web-01']]));

        $result = $this->manager()->getRunningJobs(null, true, ['default', 'emails', 'reports']);

        $ids = array_column($result['jobs'], 'job_id');
        sort($ids);
        $this->assertSame(['d-1', 'e-1', 'r-1'], $ids);
        $this->assertSame(3, $result['total_count']);
    }

    public function test_distributed_mode_filters_jobs_to_current_server(): void
    {
        $this->seedReservedJob('default', $this->makePayload([
            'uuid' => 'mine',
            'tags' => ['server:web-01'],
        ]));
        $this->seedReservedJob('default', $this->makePayload([
            'uuid' => 'someone-elses',
            'tags' => ['server:web-02'],
        ]));

        $manager = $this->manager(distributed: true);

        $own = $manager->getRunningJobs('web-01', false, ['default']);
        $this->assertSame(1, count($own['jobs']));
        $this->assertSame('mine', $own['jobs'][0]['job_id']);

        $all = $manager->getRunningJobs('web-01', true, ['default']);
        $this->assertSame(2, count($all['jobs']));
    }

    public function test_max_jobs_truncates_results_but_total_count_still_reflects_redis(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->seedReservedJob('default', $this->makePayload([
                'uuid' => "j-{$i}",
                'tags' => ['server:web-01'],
            ]));
        }

        $manager = $this->manager(distributed: false, config: ['max_jobs' => 5]);

        $result = $manager->getRunningJobs(null, true, ['default']);

        $this->assertCount(5, $result['jobs']);
        $this->assertSame(25, $result['total_count']);
    }

    private function manager(bool $distributed = false, array $config = []): RunningJobsManager
    {
        return new RunningJobsManager(array_merge([
            'distributed' => $distributed,
            'cache' => ['enabled' => false],
            'redis_connection' => 'default',
            'queues' => ['default'],
            'max_jobs' => 1000,
        ], $config));
    }
}
