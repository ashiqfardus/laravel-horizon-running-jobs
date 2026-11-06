<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ReleaseCommandTest extends TestCase
{
    public function test_no_target_errors_with_help_message(): void
    {
        $this->bindReleaser(new FakeReleaser);

        $exit = Artisan::call('horizon:release');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Specify a job ID', $output);
    }

    public function test_dry_run_lists_releasable_jobs_without_releasing(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [
            ['job_id' => 'job-a', 'queue' => 'reports', 'reason' => 'orphaned', 'payload' => 'p1'],
            ['job_id' => 'job-b', 'queue' => 'reports', 'reason' => 'orphaned', 'payload' => 'p2'],
        ];
        $this->bindReleaser($fake);

        $exit = Artisan::call('horizon:release', ['--orphaned' => true, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Would release 2 job', $output);
        $this->assertStringContainsString('job-a', $output);
        $this->assertStringContainsString('job-b', $output);
        // Critical: dry-run must NOT call release().
        $this->assertSame(0, $fake->releaseCallCount);
    }

    public function test_force_skips_confirmation_and_releases(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [
            ['job_id' => 'orphan-1', 'queue' => 'reports', 'reason' => 'orphaned', 'payload' => 'p1'],
        ];
        $fake->releaseReturns = 1;
        $this->bindReleaser($fake);

        $exit = Artisan::call('horizon:release', ['--orphaned' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Released 1 job', $output);
        $this->assertSame(1, $fake->releaseCallCount);
    }

    public function test_zombie_flag_passes_zombie_criteria(): void
    {
        $fake = new FakeReleaser;
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['--zombie' => true, '--dry-run' => true]);

        $this->assertSame(['zombie' => true], $fake->capturedCriteria);
    }

    public function test_orphaned_flag_passes_orphaned_criteria(): void
    {
        $fake = new FakeReleaser;
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['--orphaned' => true, '--dry-run' => true]);

        $this->assertSame(['orphaned' => true], $fake->capturedCriteria);
    }

    public function test_job_id_arg_passes_id_criteria(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [['job_id' => 'specific-job', 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p']];
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['job_id' => 'specific-job', '--force' => true]);

        $this->assertSame(['job_id' => 'specific-job'], $fake->capturedCriteria);
    }

    public function test_queue_filter_combines_with_targeting_flag(): void
    {
        $fake = new FakeReleaser;
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', [
            '--orphaned' => true,
            '--queue' => ['emails', 'reports'],
            '--dry-run' => true,
        ]);

        $this->assertSame(
            ['orphaned' => true, 'queues' => ['emails', 'reports']],
            $fake->capturedCriteria
        );
    }

    public function test_no_matches_reports_clean_exit(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [];
        $this->bindReleaser($fake);

        $exit = Artisan::call('horizon:release', ['--orphaned' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No matching reserved jobs', $output);
        $this->assertSame(0, $fake->releaseCallCount);
    }

    public function test_unknown_job_id_reports_not_found(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [];
        $this->bindReleaser($fake);

        $exit = Artisan::call('horizon:release', ['job_id' => 'ghost-job', '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Job ID "ghost-job" not found', $output);
    }

    public function test_job_id_cannot_be_combined_with_a_bulk_flag(): void
    {
        $this->bindReleaser(new FakeReleaser);

        $exit = Artisan::call('horizon:release', ['job_id' => 'abc', '--orphaned' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be combined', Artisan::output());
    }

    public function test_orphaned_and_zombie_flags_combine(): void
    {
        $fake = new FakeReleaser;
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['--orphaned' => true, '--zombie' => true, '--dry-run' => true]);

        $this->assertSame(['orphaned' => true, 'zombie' => true], $fake->capturedCriteria);
    }

    public function test_manual_release_warns_when_a_worker_may_still_be_running_the_job(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [['job_id' => 'abc', 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p', 'safe' => false]];
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['job_id' => 'abc', '--dry-run' => true]);

        $this->assertStringContainsString('can make it run twice', Artisan::output());
    }

    public function test_manual_release_of_a_stuck_job_does_not_warn(): void
    {
        $fake = new FakeReleaser;
        $fake->findResult = [['job_id' => 'abc', 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p', 'safe' => true]];
        $this->bindReleaser($fake);

        Artisan::call('horizon:release', ['job_id' => 'abc', '--dry-run' => true]);

        $this->assertStringNotContainsString('run twice', Artisan::output());
    }

    public function test_failed_supervisor_lookup_is_reported_and_nothing_is_released(): void
    {
        $fake = new FakeReleaser;
        $fake->throwOnFind = new \Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable;
        $this->bindReleaser($fake);

        $exit = Artisan::call('horizon:release', ['--orphaned' => true, '--force' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Nothing was released', Artisan::output());
        $this->assertSame(0, $fake->releaseCallCount);
    }

    private function bindReleaser(FakeReleaser $fake): void
    {
        $this->app->instance(JobReleaser::class, $fake);
    }
}

class FakeReleaser extends JobReleaser
{
    public array $findResult = [];
    public int $releaseReturns = 0;
    public ?array $capturedCriteria = null;
    public int $releaseCallCount = 0;
    public ?\Throwable $throwOnFind = null;

    public function __construct() {}

    public function findReleasable(array $criteria): array
    {
        if ($this->throwOnFind) {
            throw $this->throwOnFind;
        }

        $this->capturedCriteria = $criteria;
        return $this->findResult;
    }

    public function release(array $items): int
    {
        $this->releaseCallCount++;
        return $this->releaseReturns;
    }
}
