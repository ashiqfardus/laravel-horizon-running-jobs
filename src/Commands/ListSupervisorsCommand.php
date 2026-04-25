<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\Concerns\IsWatchable;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Illuminate\Console\Command;

class ListSupervisorsCommand extends Command
{
    use IsWatchable;

    protected $signature = 'horizon:supervisors
                            {--json : Output as JSON}
                            {--masters : Include the master process table}
                            {--watch= : Re-render every N seconds (default 3, Ctrl-C to exit). Ignored with --json.}';

    protected $description = 'Inspect every Horizon supervisor (and optionally master) registered in Redis';

    public function handle(SupervisorInspector $inspector): int
    {
        if ($this->isWatchMode() && ! $this->option('json')) {
            return $this->runInWatchMode(fn () => $this->renderOnce($inspector));
        }

        return $this->renderOnce($inspector);
    }

    protected function renderOnce(SupervisorInspector $inspector): int
    {
        $result = $inspector->inspect();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->renderSupervisors($result['supervisors']);

        if ($this->option('masters')) {
            $this->newLine();
            $this->renderMasters($result['masters']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $supervisors
     */
    protected function renderSupervisors(array $supervisors): void
    {
        if (empty($supervisors)) {
            $this->info('✓ No supervisors registered.');
            return;
        }

        $rows = array_map(function ($s) {
            $expiry = $s['is_stale']
                ? 'OVERDUE ' . abs($s['expires_at'] - time()) . 's'
                : $s['seconds_until_expiry'] . 's';

            return [
                'Name' => $this->shortName((string) $s['name']),
                'Status' => $this->statusMarker($s['status']),
                'PID' => $s['pid'] ?? '-',
                'Queues' => empty($s['queues']) ? '-' : implode(',', $s['queues']),
                'Procs' => $s['process_count'],
                'Expires' => $expiry,
            ];
        }, $supervisors);

        $this->table(
            ['Name', 'Status', 'PID', 'Queues', 'Procs', 'Expires'],
            $rows
        );

        $stale = array_filter($supervisors, fn ($s) => $s['is_stale']);
        if (! empty($stale)) {
            $this->warn(sprintf(
                '⚠ %d supervisor(s) past their expiry — workers may have died without cleanup.',
                count($stale)
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $masters
     */
    protected function renderMasters(array $masters): void
    {
        if (empty($masters)) {
            $this->info('✓ No master processes registered.');
            return;
        }

        $rows = array_map(function ($m) {
            $expiry = $m['is_stale']
                ? 'OVERDUE ' . abs($m['expires_at'] - time()) . 's'
                : $m['seconds_until_expiry'] . 's';

            return [
                'Name' => $this->shortName((string) $m['name']),
                'Status' => $this->statusMarker($m['status']),
                'PID' => $m['pid'] ?? '-',
                'Env' => $m['environment'] ?? '-',
                'Supervisors' => $m['supervisor_count'],
                'Expires' => $expiry,
            ];
        }, $masters);

        $this->info('Masters:');
        $this->table(
            ['Name', 'Status', 'PID', 'Env', 'Supervisors', 'Expires'],
            $rows
        );
    }

    protected function statusMarker(string $status): string
    {
        return match ($status) {
            'running' => 'running',
            'paused' => '⏸ paused',
            'stale' => '⚠ stale',
            default => $status,
        };
    }

    protected function shortName(string $name): string
    {
        if (strlen($name) <= 45) {
            return $name;
        }
        return substr($name, 0, 42) . '...';
    }
}
