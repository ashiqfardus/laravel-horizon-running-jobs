<?php

namespace Ashiqfardus\HorizonRunningJobs\Traits;

/**
 * Add this trait to your Job classes to enable server identification.
 *
 * This trait implements the hybrid approach for tracking which server
 * is processing each job in a distributed Horizon setup.
 *
 * Usage:
 *
 * class YourJob implements ShouldQueue
 * {
 *     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 *     use \Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;
 *
 *     public function __construct()
 *     {
 *         $this->initializeServerTracking();
 *     }
 * }
 */
trait TracksServer
{
    /**
     * The hostname of the server that created/processes this job.
     */
    public string $supervisor_id;

    /**
     * Initialize server tracking.
     * Call this in your job's constructor.
     */
    public function initializeServerTracking(): void
    {
        $this->supervisor_id = gethostname();
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * Override this method if you need additional tags.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'server:' . gethostname(),
            'environment:' . app()->environment(),
            'type:' . class_basename($this),
        ];
    }
}

