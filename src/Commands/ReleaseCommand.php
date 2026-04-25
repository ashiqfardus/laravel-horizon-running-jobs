<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use Illuminate\Console\Command;

class ReleaseCommand extends Command
{
    protected $signature = 'horizon:release
                            {job_id? : UUID of a single reserved job to release}
                            {--orphaned : Release every orphaned reserved job}
                            {--zombie : Release every zombie reserved job (expired reservation)}
                            {--queue=* : Restrict to specific queue(s); repeatable}
                            {--dry-run : Print what would be released without modifying Redis}
                            {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Release reserved jobs back to the pending list (orphan / zombie recovery)';

    public function handle(JobReleaser $releaser): int
    {
        $criteria = $this->buildCriteria();
        if (is_int($criteria)) {
            return $criteria; // returned an exit code (validation error)
        }

        $found = $releaser->findReleasable($criteria);

        if (empty($found)) {
            if (isset($criteria['job_id'])) {
                $this->error("Job ID \"{$criteria['job_id']}\" not found in any reserved set.");
                return self::FAILURE;
            }

            $this->info('No matching reserved jobs to release.');
            return self::SUCCESS;
        }

        $this->renderTable($found);

        if ($this->option('dry-run')) {
            $this->info(sprintf('Would release %d job(s). Re-run without --dry-run to apply.', count($found)));
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm(sprintf('Release %d job(s) back to the pending list?', count($found)))) {
                $this->line('Aborted.');
                return self::SUCCESS;
            }
        }

        $count = $releaser->release($found);
        $this->info(sprintf('Released %d job(s) to the pending list.', $count));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|int
     */
    protected function buildCriteria(): array|int
    {
        $jobId = $this->argument('job_id');
        $orphaned = (bool) $this->option('orphaned');
        $zombie = (bool) $this->option('zombie');

        $targetingCount = (int) ((bool) $jobId) + (int) $orphaned + (int) $zombie;

        if ($targetingCount === 0) {
            $this->error(
                'Specify a job ID, --orphaned, or --zombie to indicate what to release. '
                . 'Use --dry-run first to preview.'
            );
            return self::FAILURE;
        }

        if ($targetingCount > 1) {
            $this->error('--orphaned, --zombie, and a job ID are mutually exclusive — pick one.');
            return self::FAILURE;
        }

        $criteria = [];
        if ($jobId) {
            $criteria['job_id'] = $jobId;
        } elseif ($orphaned) {
            $criteria['orphaned'] = true;
        } elseif ($zombie) {
            $criteria['zombie'] = true;
        }

        $queues = $this->option('queue');
        if (! empty($queues)) {
            $criteria['queues'] = array_values($queues);
        }

        return $criteria;
    }

    /**
     * @param  array<int, array{job_id: string, queue: string, reason: string}>  $found
     */
    protected function renderTable(array $found): void
    {
        $this->table(
            ['Job ID', 'Queue', 'Reason'],
            array_map(fn ($f) => [
                'Job ID' => $f['job_id'],
                'Queue' => $f['queue'],
                'Reason' => $f['reason'],
            ], $found)
        );
    }
}
