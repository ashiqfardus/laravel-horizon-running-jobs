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
     * The identifier of the server that created/processes this job.
     */
    public string $supervisor_id;

    /**
     * Initialize server tracking.
     * Call this in your job's constructor.
     */
    public function initializeServerTracking(): void
    {
        $this->supervisor_id = $this->getServerIdentifier();
    }

    /**
     * Get the server identifier.
     * Uses the first supervisor key from Horizon config that matches current environment.
     */
    protected function getServerIdentifier(): string
    {
        // If explicitly configured in package config, use that
        $configured = config('horizon-running-jobs.server_identifier');
        if (!empty($configured)) {
            return $configured;
        }

        // Get from Horizon config - check current environment first
        $currentEnv = app()->environment();
        $envSupervisors = config("horizon.environments.{$currentEnv}", []);

        if (!empty($envSupervisors)) {
            // Return the first supervisor key for current environment
            return array_key_first($envSupervisors);
        }

        // Fallback to defaults
        $defaults = config('horizon.defaults', []);
        if (!empty($defaults)) {
            return array_key_first($defaults);
        }

        // Ultimate fallback
        return gethostname();
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
            'server:' . $this->getServerIdentifier(),
            'environment:' . app()->environment(),
            'type:' . class_basename($this),
        ];
    }
}

