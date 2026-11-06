<?php

namespace Ashiqfardus\HorizonRunningJobs\Traits;

/**
 * Add this trait to your Job classes to enable server identification.
 *
 * The package identifies a job's server through a `server:<identifier>`
 * Horizon tag. Horizon computes tags when the job is dispatched, so the tag
 * names the instance that dispatched the job. The trait's tags() adds it:
 *
 * class YourJob implements ShouldQueue
 * {
 *     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 *     use \Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;
 * }
 *
 * A tags() method on the job class replaces the trait's, and the server tag
 * with it. Merge serverTags() into your own list to keep it:
 *
 *     public function tags(): array
 *     {
 *         return ['order:' . $this->order->id, ...$this->serverTags()];
 *     }
 *
 * Calling initializeServerTracking() in the constructor is optional. It
 * stores the identifier on the job as a fallback for jobs that end up
 * without a server tag.
 */
trait TracksServer
{
    /**
     * The identifier of the server that created this job. Null unless
     * initializeServerTracking() was called.
     */
    public ?string $supervisor_id = null;

    /**
     * Store the server identifier on the job itself. Optional: call it in
     * your job's constructor to give the package a fallback when the job
     * carries no server tag.
     */
    public function initializeServerTracking(): void
    {
        $this->supervisor_id = $this->getServerIdentifier();
    }

    /**
     * Get the server identifier: the same value the package uses for this
     * instance (`server_identifier` config, else the first supervisor key of
     * the Horizon environment, else gethostname()).
     */
    protected function getServerIdentifier(): string
    {
        return app(\Ashiqfardus\HorizonRunningJobs\RunningJobsManager::class)->getServerIdentifier();
    }

    /**
     * The tag the package reads this job's server from. Merge
     * it into your own tags() when the job defines one.
     *
     * @return array<int, string>
     */
    protected function serverTags(): array
    {
        return ['server:' . $this->getServerIdentifier()];
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * If the job defines its own tags(), this method is not used: merge
     * serverTags() into yours.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            ...$this->serverTags(),
            'environment:' . app()->environment(),
            'type:' . class_basename($this),
        ];
    }
}
