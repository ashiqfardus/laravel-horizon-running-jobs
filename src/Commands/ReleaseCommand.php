<?php

namespace Ashiqfardus\HorizonRunningJobs\Commands;

use Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable;
use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use Illuminate\Console\Command;

class ReleaseCommand extends Command
{
    protected $signature = 'horizon:release
                            {job_id? : UUID of a single reserved job to release}
                            {--orphaned : Release every orphaned job that has run past its timeout}
                            {--zombie : Release every zombie job (expired reservation) that has run past its timeout}
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

        try {
            $found = $releaser->findReleasable($criteria);
        } catch (LiveSupervisorsUnavailable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (empty($found)) {
            if (isset($criteria['job_id'])) {
                $this->error("Job ID \"{$criteria['job_id']}\" not found in any reserved set.");
                return self::FAILURE;
            }

            $this->info('No matching reserved jobs to release.');
            return self::SUCCESS;
        }

        $this->renderTable($found);

        if (isset($criteria['job_id']) && ! ($found[0]['safe'] ?? false)) {
            $this->warn(
                '⚠ This job is not orphaned or expired and past its timeout. A worker may still be running it; '
                . 'releasing it now can make it run twice.'
            );
        }

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

        if (! $jobId && ! $orphaned && ! $zombie) {
            $this->error(
                'Specify a job ID, --orphaned, or --zombie to indicate what to release. '
                . 'Use --dry-run first to preview.'
            );
            return self::FAILURE;
        }

        if ($jobId && ($orphaned || $zombie)) {
            $this->error('A job ID cannot be combined with --orphaned or --zombie.');
            return self::FAILURE;
        }

        $criteria = $jobId
            ? ['job_id' => $jobId]
            : array_filter(['orphaned' => $orphaned, 'zombie' => $zombie]);

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
