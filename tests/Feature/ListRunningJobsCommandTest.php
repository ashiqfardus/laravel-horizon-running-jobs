<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ListRunningJobsCommandTest extends TestCase
{
    public function test_command_renders_table_with_status_column(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('uuid-1', 'App\\Jobs\\Order', 'default', 'web-01', 'running', 5),
        ]));

        $exit = Artisan::call('horizon:running-jobs');

        $this->assertSame(0, $exit);
    }

    public function test_command_renders_x_of_y_truncation_in_json(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('uuid-3', 'App\\Jobs\\Foo', 'default', 'web-01', 'running', 1),
        ], totalCount: 10));

        Artisan::call('horizon:running-jobs', ['--json' => true, '--limit' => 1]);
        $output = Artisan::output();

        $this->assertStringContainsString('"shown_count": 1', $output);
        $this->assertStringContainsString('"total_count": 10', $output);
        $this->assertStringContainsString('"truncated": true', $output);
    }

    public function test_stats_command_renders_by_status_breakdown(): void
    {
        $this->bindManager(new SpyManager(stats: [
            'total_running' => 3,
            'by_server' => ['web-01' => 3],
            'by_queue' => ['default' => 3],
            'by_job_class' => ['App\\Jobs\\Foo' => 3],
            'by_status' => ['running' => 2, 'zombie' => 1],
            'dropped_count' => 4,
            'longest_running' => null,
            'warnings' => [],
        ]));

        Artisan::call('horizon:running-jobs', ['--stats' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('By Status', $output);
        $this->assertStringContainsString('zombie: 1', $output);
        $this->assertStringContainsString('Dropped (malformed): 4', $output);
    }

    public function test_command_warns_when_horizon_is_not_running(): void
    {
        $this->bindManager(new SpyManager(horizonRunning: false));

        $exit = Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Horizon is not running', $output);
    }

    public function test_json_truncation_metadata_when_not_truncated(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('uuid-only', 'App\\Jobs\\Foo', 'default', 'web-01', 'running', 1),
        ]));

        Artisan::call('horizon:running-jobs', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('"truncated": false', $output);
    }

    public function test_empty_state_message_when_no_jobs_running(): void
    {
        $this->bindManager(new SpyManager);

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertStringContainsString('No jobs currently running', $output);
    }

    public function test_human_output_shows_x_of_y_when_truncated(): void
    {
        $jobs = [];
        for ($i = 1; $i <= 50; $i++) {
            $jobs[] = $this->job("uuid-{$i}", 'App\\Jobs\\Job', 'default', 'web-01', 'running', $i);
        }

        $this->bindManager(new SpyManager(jobs: $jobs, totalCount: 50));

        Artisan::call('horizon:running-jobs', ['--limit' => 5]);
        $output = Artisan::output();

        $this->assertStringContainsString('Showing 5 of 50 running job(s)', $output);
    }

    public function test_human_output_shows_simple_count_when_not_truncated(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('id-1', 'App\\Jobs\\Foo', 'default', 'web-01', 'running', 1),
            $this->job('id-2', 'App\\Jobs\\Foo', 'default', 'web-01', 'running', 2),
        ]));

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertStringContainsString('Found 2 running job(s)', $output);
        $this->assertStringNotContainsString('Showing', $output);
    }

    public function test_zombie_status_renders_in_human_table(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('zombie-1', 'App\\Jobs\\Stuck', 'reports', 'web-01', 'zombie', 120),
        ], warnings: ['1 zombie job(s) detected (reservation expired but still in queue)']));

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertStringContainsString('zombie', $output);
        $this->assertStringContainsString('zombie job(s) detected', $output);
    }

    public function test_long_job_class_is_truncated_in_table(): void
    {
        $this->bindManager(new SpyManager(jobs: [
            $this->job('id-1', 'App\\Jobs\\ThisIsAReallyLongJobClassNameThatExceedsTheTruncationLimit', 'default', 'web-01', 'running', 1),
        ]));

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        // Truncate() limit is 35 chars and appends "...".
        $this->assertStringContainsString('...', $output);
    }

    public function test_queue_option_filters_to_specified_queues(): void
    {
        $spy = new SpyManager(jobs: []);
        $this->bindManager($spy);

        Artisan::call('horizon:running-jobs', ['--queue' => ['emails', 'reports']]);

        $this->assertSame(['emails', 'reports'], $spy->capturedQueues);
    }

    public function test_orphan_status_renders_in_human_table(): void
    {
        $orphan = $this->job('orphan-1', 'App\\Jobs\\Stuck', 'reports', 'web-01', 'running', 5);
        $orphan['is_orphaned'] = true;

        $this->bindManager(new SpyManager(jobs: [$orphan]));

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertStringContainsString('⚠ orphan', $output);
    }

    public function test_orphan_zombie_combo_renders_in_human_table(): void
    {
        $job = $this->job('z-orphan', 'App\\Jobs\\Stuck', 'reports', 'web-01', 'zombie', 200);
        $job['is_orphaned'] = true;

        $this->bindManager(new SpyManager(jobs: [$job]));

        Artisan::call('horizon:running-jobs');
        $output = Artisan::output();

        $this->assertStringContainsString('⚠ orphan+zombie', $output);
    }

    public function test_orphaned_flag_passes_through_to_manager(): void
    {
        $spy = new SpyManager(jobs: []);
        $this->bindManager($spy);

        Artisan::call('horizon:running-jobs', ['--orphaned' => true]);

        $this->assertTrue($spy->capturedOrphanedOnly);
    }

    public function test_json_includes_orphan_count_and_orphaned_only_flag(): void
    {
        $orphan = $this->job('o-1', 'App\\Jobs\\X', 'default', 'web-01', 'running', 1);
        $orphan['is_orphaned'] = true;

        $this->bindManager(new SpyManager(jobs: [$orphan], totalCount: 1));

        Artisan::call('horizon:running-jobs', ['--json' => true, '--orphaned' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('"orphaned_only": true', $output);
        $this->assertStringContainsString('"orphan_count":', $output);
    }

    public function test_watch_mode_invokes_manager_for_each_iteration(): void
    {
        $spy = new SpyManager(jobs: [$this->job('a', 'X', 'default', 'web-01', 'running', 1)]);
        $this->bindManager($spy);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 4,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        Artisan::call('horizon:running-jobs', ['--watch' => 1]);

        $this->assertSame(4, $spy->getRunningJobsCallCount);
    }

    public function test_watch_mode_skipped_when_json_flag_set(): void
    {
        $spy = new SpyManager;
        $this->bindManager($spy);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 4,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        Artisan::call('horizon:running-jobs', ['--watch' => 1, '--json' => true]);

        $this->assertSame(1, $spy->getRunningJobsCallCount);
    }

    private function bindManager(SpyManager $manager): void
    {
        $this->app->instance(RunningJobsManager::class, $manager);
    }

    private function job(string $id, string $class, string $queue, string $server, string $status, int $duration): array
    {
        return [
            'job_id' => $id,
            'job_class' => $class,
            'queue' => $queue,
            'server' => $server,
            'status' => $status,
            'start_time' => '2026-04-25T10:00:00+00:00',
            'start_timestamp' => time() - $duration,
            'running_for_seconds' => $duration,
            'running_for_formatted' => "{$duration}s",
            'attempts' => 1,
            'timeout' => 60,
            'tags' => [],
        ];
    }
}

class SpyManager extends RunningJobsManager
{
    public ?array $capturedQueues = null;
    public bool $capturedOrphanedOnly = false;
    public int $getRunningJobsCallCount = 0;

    public function __construct(
        public array $jobs = [],
        public array $warnings = [],
        public ?int $totalCount = null,
        public array $stats = [],
        public bool $horizonRunning = true
    ) {
        parent::__construct(['distributed' => true, 'cache' => ['enabled' => false]]);
    }

    public function isDistributed(): bool { return true; }
    public function isHorizonRunning(): bool { return $this->horizonRunning; }
    public function getServerIdentifier(): string { return 'web-01'; }
    public function getDefaultQueues(): array { return ['default']; }

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        $this->capturedQueues = $queues;
        $this->capturedOrphanedOnly = $orphanedOnly;
        $this->getRunningJobsCallCount++;

        return [
            'jobs' => $this->jobs,
            'warnings' => $this->warnings,
            'total_count' => $this->totalCount ?? count($this->jobs),
            'dropped_count' => 0,
            'orphan_count' => count(array_filter($this->jobs, fn ($j) => ($j['is_orphaned'] ?? false) === true)),
        ];
    }

    public function getStats(?array $queues = null): array
    {
        return $this->stats ?: [
            'total_running' => count($this->jobs),
            'by_server' => [],
            'by_queue' => [],
            'by_job_class' => [],
            'by_status' => ['running' => count($this->jobs), 'zombie' => 0],
            'dropped_count' => 0,
            'longest_running' => null,
            'warnings' => [],
        ];
    }
}
