<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\Concerns\IsWatchable;
use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Illuminate\Console\Command;

class ListQueueDepthsCommand extends Command
{
    use IsWatchable;

    protected $signature = 'horizon:queues
                            {--queue=* : Limit to specific queue(s); repeatable}
                            {--json : Output as JSON}
                            {--watch= : Re-render every N seconds (default 3, Ctrl-C to exit). Ignored with --json.}';

    protected $description = 'Show pending, reserved, and delayed counts per queue';

    public function handle(QueueDepthInspector $inspector): int
    {
        // --watch is incompatible with --json: looping JSON output is unhelpful.
        if ($this->isWatchMode() && ! $this->option('json')) {
            return $this->runInWatchMode(fn () => $this->renderOnce($inspector));
        }

        return $this->renderOnce($inspector);
    }

    protected function renderOnce(QueueDepthInspector $inspector): int
    {
        $queues = $this->option('queue');
        $queues = empty($queues) ? null : array_values($queues);

        $result = $inspector->inspect($queues);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if (empty($result['queues'])) {
            $this->info('✓ No queues to inspect.');
            return self::SUCCESS;
        }

        $rows = array_map(fn ($q) => [
            'Queue' => $q['queue'],
            'Pending' => $q['pending'],
            'Reserved' => $q['reserved'],
            'Delayed' => $q['delayed'],
            'Total' => $q['total'],
        ], $result['queues']);

        $rows[] = new \Symfony\Component\Console\Helper\TableSeparator;
        $rows[] = [
            'Queue' => 'TOTAL',
            'Pending' => $result['totals']['pending'],
            'Reserved' => $result['totals']['reserved'],
            'Delayed' => $result['totals']['delayed'],
            'Total' => $result['totals']['total'],
        ];

        $this->table(
            ['Queue', 'Pending', 'Reserved', 'Delayed', 'Total'],
            $rows
        );

        return self::SUCCESS;
    }
}
