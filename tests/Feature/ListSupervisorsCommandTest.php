<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ListSupervisorsCommandTest extends TestCase
{
    public function test_command_renders_table_with_running_supervisor(): void
    {
        $this->bindInspector($this->payload(supervisors: [
            $this->supervisorRow('worker-01', 'running', 1234, ['default'], 3, isStale: false),
        ]));

        $exit = Artisan::call('horizon:supervisors');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('worker-01', $output);
        $this->assertStringContainsString('running', $output);
        $this->assertStringContainsString('1234', $output);
        $this->assertStringContainsString('default', $output);
    }

    public function test_command_marks_stale_supervisor_and_warns(): void
    {
        $this->bindInspector($this->payload(supervisors: [
            $this->supervisorRow('dead', 'stale', null, [], 0, isStale: true),
        ]));

        Artisan::call('horizon:supervisors');
        $output = Artisan::output();

        $this->assertStringContainsString('⚠ stale', $output);
        $this->assertStringContainsString('OVERDUE', $output);
        $this->assertStringContainsString('1 supervisor(s) past their expiry', $output);
    }

    public function test_masters_flag_renders_master_table(): void
    {
        $this->bindInspector($this->payload(
            supervisors: [],
            masters: [
                ['name' => 'master-A', 'status' => 'running', 'environment' => 'production', 'pid' => 9999, 'supervisor_count' => 2, 'expires_at' => time() + 30, 'seconds_until_expiry' => 30, 'is_stale' => false],
            ]
        ));

        Artisan::call('horizon:supervisors', ['--masters' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Masters:', $output);
        $this->assertStringContainsString('master-A', $output);
        $this->assertStringContainsString('production', $output);
    }

    public function test_json_flag_emits_inspector_payload(): void
    {
        $this->bindInspector($this->payload(supervisors: [
            $this->supervisorRow('worker-A', 'running', 1, ['default'], 1, isStale: false),
        ]));

        Artisan::call('horizon:supervisors', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('"name": "worker-A"', $output);
        $this->assertStringContainsString('"is_stale": false', $output);
        $this->assertStringContainsString('"inspected_at"', $output);
    }

    public function test_command_handles_empty_state(): void
    {
        $this->bindInspector($this->payload());

        Artisan::call('horizon:supervisors');
        $output = Artisan::output();

        $this->assertStringContainsString('No supervisors registered', $output);
    }

    private function bindInspector(array $payload): void
    {
        $fake = new FakeInspector;
        $fake->payload = $payload;
        $this->app->instance(SupervisorInspector::class, $fake);
    }

    private function payload(array $supervisors = [], array $masters = []): array
    {
        return ['supervisors' => $supervisors, 'masters' => $masters, 'inspected_at' => time()];
    }

    private function supervisorRow(string $name, string $status, ?int $pid, array $queues, int $procs, bool $isStale): array
    {
        $now = time();

        return [
            'name' => $name,
            'status' => $status,
            'master' => 'master',
            'pid' => $pid,
            'queues' => $queues,
            'process_count' => $procs,
            'processes' => [],
            'expires_at' => $isStale ? $now - 30 : $now + 60,
            'seconds_until_expiry' => $isStale ? 0 : 60,
            'is_stale' => $isStale,
        ];
    }
}

class FakeInspector extends SupervisorInspector
{
    public array $payload = ['supervisors' => [], 'masters' => [], 'inspected_at' => 0];

    public function __construct()
    {
        // Bypass parent constructor — we don't need real Horizon repositories.
    }

    public function inspect(): array
    {
        return $this->payload;
    }
}
