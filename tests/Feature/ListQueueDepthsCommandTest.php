<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ListQueueDepthsCommandTest extends TestCase
{
    public function test_command_renders_table_with_queue_rows(): void
    {
        $this->bindInspector([
            'queues' => [
                ['queue' => 'default', 'pending' => 5, 'reserved' => 2, 'delayed' => 1, 'total' => 8],
                ['queue' => 'emails', 'pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            ],
            'totals' => ['pending' => 5, 'reserved' => 2, 'delayed' => 1, 'total' => 8],
            'inspected_at' => time(),
        ]);

        $exit = Artisan::call('horizon:queues');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('default', $output);
        $this->assertStringContainsString('emails', $output);
        $this->assertStringContainsString('TOTAL', $output);
    }

    public function test_command_handles_empty_queue_list(): void
    {
        $this->bindInspector([
            'queues' => [],
            'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            'inspected_at' => time(),
        ]);

        Artisan::call('horizon:queues');
        $output = Artisan::output();

        $this->assertStringContainsString('No queues to inspect', $output);
    }

    public function test_json_flag_outputs_raw_payload(): void
    {
        $this->bindInspector([
            'queues' => [
                ['queue' => 'default', 'pending' => 1, 'reserved' => 2, 'delayed' => 3, 'total' => 6],
            ],
            'totals' => ['pending' => 1, 'reserved' => 2, 'delayed' => 3, 'total' => 6],
            'inspected_at' => 1745576400,
        ]);

        Artisan::call('horizon:queues', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('"queue": "default"', $output);
        $this->assertStringContainsString('"pending": 1', $output);
        $this->assertStringContainsString('"inspected_at": 1745576400', $output);
    }

    public function test_queue_option_filters_to_specified_queues(): void
    {
        $fake = $this->bindInspector([
            'queues' => [],
            'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            'inspected_at' => time(),
        ]);

        Artisan::call('horizon:queues', ['--queue' => ['emails', 'reports']]);

        $this->assertSame(['emails', 'reports'], $fake->capturedQueues);
    }

    public function test_no_queue_option_passes_null_so_inspector_uses_defaults(): void
    {
        $fake = $this->bindInspector([
            'queues' => [],
            'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            'inspected_at' => time(),
        ]);

        Artisan::call('horizon:queues');

        $this->assertNull($fake->capturedQueues);
    }

    public function test_watch_mode_invokes_inspector_for_each_iteration(): void
    {
        $fake = $this->bindInspector([
            'queues' => [['queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1]],
            'totals' => ['pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
            'inspected_at' => time(),
        ]);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 3,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        Artisan::call('horizon:queues', ['--watch' => 1]);

        // Inspector got called once per loop iteration.
        $this->assertSame(3, $fake->inspectCallCount);
    }

    public function test_watch_mode_renders_header_with_interval(): void
    {
        $this->bindInspector([
            'queues' => [],
            'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            'inspected_at' => time(),
        ]);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 1,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        Artisan::call('horizon:queues', ['--watch' => 5]);
        $output = Artisan::output();

        $this->assertStringContainsString('refreshing every 5s', $output);
    }

    public function test_watch_with_json_skips_loop(): void
    {
        $fake = $this->bindInspector([
            'queues' => [],
            'totals' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0],
            'inspected_at' => 0,
        ]);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 5,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        Artisan::call('horizon:queues', ['--watch' => 1, '--json' => true]);

        // Inspector called once (no loop) because --json took precedence.
        $this->assertSame(1, $fake->inspectCallCount);
    }

    public function test_command_preserves_queue_order_from_inspector(): void
    {
        // Intentionally non-alphabetical to prove order is preserved, not sorted.
        $this->bindInspector([
            'queues' => [
                ['queue' => 'zebra', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
                ['queue' => 'alpha', 'pending' => 2, 'reserved' => 0, 'delayed' => 0, 'total' => 2],
                ['queue' => 'mike',  'pending' => 3, 'reserved' => 0, 'delayed' => 0, 'total' => 3],
            ],
            'totals' => ['pending' => 6, 'reserved' => 0, 'delayed' => 0, 'total' => 6],
            'inspected_at' => time(),
        ]);

        Artisan::call('horizon:queues');
        $output = Artisan::output();

        $zebraPos = strpos($output, 'zebra');
        $alphaPos = strpos($output, 'alpha');
        $mikePos = strpos($output, 'mike');

        $this->assertNotFalse($zebraPos);
        $this->assertNotFalse($alphaPos);
        $this->assertNotFalse($mikePos);
        $this->assertLessThan($alphaPos, $zebraPos, 'zebra row must precede alpha');
        $this->assertLessThan($mikePos, $alphaPos, 'alpha row must precede mike');
    }

    public function test_totals_row_renders_aggregate_counts(): void
    {
        $this->bindInspector([
            'queues' => [
                ['queue' => 'a', 'pending' => 3, 'reserved' => 4, 'delayed' => 5, 'total' => 12],
                ['queue' => 'b', 'pending' => 1, 'reserved' => 1, 'delayed' => 1, 'total' => 3],
            ],
            'totals' => ['pending' => 4, 'reserved' => 5, 'delayed' => 6, 'total' => 15],
            'inspected_at' => time(),
        ]);

        Artisan::call('horizon:queues');
        $output = Artisan::output();

        // Find the row that starts with "| TOTAL" and parse its columns.
        // This is far more robust than a regex against the raw output.
        $totalLine = collect(explode("\n", $output))
            ->first(fn ($line) => preg_match('/^\|\s*TOTAL\s*\|/', $line));

        $this->assertNotNull($totalLine, 'Expected a TOTAL row in the table output');

        $columns = array_values(array_filter(
            array_map('trim', explode('|', $totalLine)),
            fn ($c) => $c !== ''
        ));

        $this->assertSame(['TOTAL', '4', '5', '6', '15'], $columns);
    }

    private function bindInspector(array $payload): FakeQueueDepthInspectorCmd
    {
        $fake = new FakeQueueDepthInspectorCmd;
        $fake->payload = $payload;
        $this->app->instance(QueueDepthInspector::class, $fake);
        return $fake;
    }
}

class FakeQueueDepthInspectorCmd extends QueueDepthInspector
{
    public array $payload = [];
    public ?array $capturedQueues = null;
    public int $inspectCallCount = 0;

    public function __construct() {} // bypass parent

    public function inspect(?array $queues = null): array
    {
        $this->capturedQueues = $queues;
        $this->inspectCallCount++;
        return $this->payload;
    }
}
