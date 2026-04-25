<?php

namespace Ashiqfardus\HorizonRunningJobs\Concerns;

/**
 * Adds a `--watch=N` flag to a Console command. When the flag is set, the
 * given renderer closure is invoked on a loop with a clear-screen between
 * iterations. Pressing Ctrl-C exits.
 *
 * Tests bound the loop via the static `$testWatchIterationLimit` hook;
 * production code never touches it (default = PHP_INT_MAX).
 */
trait IsWatchable
{
    /**
     * True when the user passed `--watch` (with or without a value).
     */
    protected function isWatchMode(): bool
    {
        $value = $this->option('watch');
        return $value !== null && $value !== false;
    }

    /**
     * Resolved interval in seconds. Default 3, minimum 1.
     */
    protected function watchInterval(): int
    {
        $value = $this->option('watch');

        if (! is_numeric($value)) {
            return 3;
        }

        return max(1, (int) $value);
    }

    /**
     * Run the renderer in a loop. Returns the renderer's last exit code.
     *
     * Test hooks (read from `horizon-running-jobs.test_hooks.*` config; never
     * set in production):
     *   - `watch_iteration_limit` — caps loop count so tests don't hang.
     *   - `watch_sleep_override`  — set to 0 to skip the sleep between iterations.
     */
    protected function runInWatchMode(callable $render): int
    {
        $interval = $this->watchInterval();
        $limit = (int) (config('horizon-running-jobs.test_hooks.watch_iteration_limit') ?? PHP_INT_MAX);
        $sleep = config('horizon-running-jobs.test_hooks.watch_sleep_override');
        $sleep = $sleep === null ? $interval : (int) $sleep;

        $exitCode = 0;
        for ($i = 0; $i < $limit; $i++) {
            $this->clearScreen();
            $this->renderWatchHeader($interval);
            $exitCode = $render() ?? 0;

            if ($i + 1 < $limit && $sleep > 0) {
                sleep($sleep);
            }
        }

        return $exitCode;
    }

    protected function clearScreen(): void
    {
        // ANSI: clear screen + move cursor home. Cheaper than spawning `clear`.
        $this->output->write("\033[2J\033[H");
    }

    protected function renderWatchHeader(int $interval): void
    {
        $this->line(sprintf(
            "<fg=cyan>⌚ Watching — refreshing every %ds (Ctrl-C to exit) — %s</>",
            $interval,
            date('H:i:s')
        ));
        $this->newLine();
    }
}
