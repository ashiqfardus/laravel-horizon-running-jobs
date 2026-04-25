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

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null): array
    {
        return [
            'jobs' => $this->jobs,
            'warnings' => $this->warnings,
            'total_count' => $this->totalCount ?? count($this->jobs),
            'dropped_count' => 0,
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
