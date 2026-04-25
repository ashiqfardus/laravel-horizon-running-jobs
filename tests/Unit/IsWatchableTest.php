<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\Concerns\IsWatchable;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

class IsWatchableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->registerCommand(new WatchableTestStub);
    }

    public function test_watch_interval_defaults_to_3_when_flag_passed_without_value(): void
    {
        // Symfony's `--watch=` (VALUE_REQUIRED) requires a value, so to simulate
        // "user typed --watch with no value" we pass an empty string.
        $stub = $this->runStub(['--watch' => '']);

        $this->assertSame(3, $stub->capturedInterval);
    }

    public function test_watch_interval_uses_explicit_numeric_value(): void
    {
        $stub = $this->runStub(['--watch' => 7]);

        $this->assertSame(7, $stub->capturedInterval);
    }

    public function test_watch_interval_falls_back_to_3_for_non_numeric_garbage(): void
    {
        $stub = $this->runStub(['--watch' => 'abc']);

        $this->assertSame(3, $stub->capturedInterval);
    }

    public function test_watch_interval_floors_below_one_to_one(): void
    {
        $stub = $this->runStub(['--watch' => 0]);

        $this->assertSame(1, $stub->capturedInterval);
    }

    public function test_watch_mode_returns_false_when_flag_absent(): void
    {
        $stub = $this->runStub();

        $this->assertFalse($stub->capturedIsWatchMode);
    }

    public function test_watch_mode_returns_true_when_flag_present(): void
    {
        $stub = $this->runStub(['--watch' => 5]);

        $this->assertTrue($stub->capturedIsWatchMode);
    }

    public function test_clear_screen_escape_emits_exactly_once_per_iteration(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new WatchLoopTestStub);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 4,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        $kernel->call('test:watch-loop-stub', ['--watch' => 1]);
        $output = $kernel->output();

        // The trait writes "\x1b[2J\x1b[H" (clear + home) once per iteration.
        $count = substr_count($output, "\x1b[2J\x1b[H");
        $this->assertSame(4, $count);
    }

    public function test_loop_returns_renderer_exit_code(): void
    {
        $stub = new WatchLoopTestStub;
        $stub->renderExitCode = 1;

        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand($stub);

        config([
            'horizon-running-jobs.test_hooks.watch_iteration_limit' => 2,
            'horizon-running-jobs.test_hooks.watch_sleep_override' => 0,
        ]);

        $exit = $kernel->call('test:watch-loop-stub', ['--watch' => 1]);

        $this->assertSame(1, $exit, 'Renderer FAILURE should propagate as loop exit code');
    }

    private function runStub(array $args = []): WatchableTestStub
    {
        // Use the kernel directly so we get the same stub instance back
        // (Artisan::call would internally re-resolve the command).
        $kernel = $this->app->make(Kernel::class);
        $kernel->call('test:watchable-stub', $args);

        return collect($kernel->all())
            ->first(fn ($cmd) => $cmd instanceof WatchableTestStub);
    }
}

class WatchLoopTestStub extends Command
{
    use IsWatchable;

    protected $signature = 'test:watch-loop-stub
                            {--watch= : Watch interval}';

    protected $description = 'Internal test stub that exercises runInWatchMode';

    public int $renderExitCode = 0;

    public function handle(): int
    {
        return $this->runInWatchMode(fn () => $this->renderExitCode);
    }
}

class WatchableTestStub extends Command
{
    use IsWatchable;

    protected $signature = 'test:watchable-stub
                            {--watch= : Watch interval}';

    protected $description = 'Internal test stub for IsWatchable trait';

    public ?int $capturedInterval = null;
    public ?bool $capturedIsWatchMode = null;

    public function handle(): int
    {
        $this->capturedIsWatchMode = $this->isWatchMode();
        if ($this->capturedIsWatchMode) {
            $this->capturedInterval = $this->watchInterval();
        }

        return self::SUCCESS;
    }
}
