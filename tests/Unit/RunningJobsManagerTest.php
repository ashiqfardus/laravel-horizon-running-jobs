<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

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
}
